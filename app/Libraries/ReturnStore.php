<?php
namespace App\Libraries;
use PDO;
use RuntimeException;

final class ReturnStore
{
    public const RETENTION_SECONDS = 600;
    public PDO $db;
    private string $key;

    public function __construct()
    {
        $dir = WRITEPATH . 'data';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $key = base64_decode((string) env('GST_ENCRYPTION_KEY', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Set GST_ENCRYPTION_KEY to a base64 encoded 32-byte key.');
        $this->key = $key;
        $mode=(string)env('GST_MODE','disabled');
        if (!in_array($mode,['fixture','live','disabled'],true)) throw new RuntimeException('Unknown GST_MODE.');
        // Never promote synthetic credentials or job IDs into the live API workflow.
        $database='returns-v3-'.$mode.'.sqlite';
        $this->db = new PDO('sqlite:' . $dir . '/' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA journal_mode=DELETE; PRAGMA secure_delete=ON; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
        $this->db->exec('CREATE TABLE IF NOT EXISTS windows (owner TEXT PRIMARY KEY, expires INTEGER NOT NULL, client_ip TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS connections (owner TEXT PRIMARY KEY, gstin TEXT NOT NULL, username TEXT NOT NULL, secret TEXT NOT NULL, state TEXT NOT NULL, expires INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_otp INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS batches (id TEXT PRIMARY KEY, owner TEXT NOT NULL REFERENCES windows(owner) ON DELETE CASCADE, request_key TEXT NOT NULL, created INTEGER NOT NULL, scope TEXT NOT NULL DEFAULT \'monthly\', UNIQUE(owner, request_key));
            CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, batch_id TEXT NOT NULL REFERENCES batches(id) ON DELETE CASCADE, owner TEXT NOT NULL, return_type TEXT NOT NULL, gstin TEXT NOT NULL, financial_year TEXT NOT NULL, period TEXT NOT NULL, status TEXT NOT NULL, job_id TEXT, message TEXT NOT NULL DEFAULT \'\', created INTEGER NOT NULL, updated INTEGER NOT NULL, next_poll INTEGER NOT NULL DEFAULT 0, deadline INTEGER NOT NULL DEFAULT 0, poll_errors INTEGER NOT NULL DEFAULT 0, upstream_status TEXT, retry_allowed INTEGER NOT NULL DEFAULT 0, client_ip TEXT NOT NULL DEFAULT \'unknown\', downloaded_at INTEGER, expires_at INTEGER);
            CREATE INDEX IF NOT EXISTS jobs_owner ON jobs(owner, created);
            CREATE TABLE IF NOT EXISTS rate_limits (owner TEXT NOT NULL, action TEXT NOT NULL, started INTEGER NOT NULL, count INTEGER NOT NULL, PRIMARY KEY(owner, action));
            CREATE TABLE IF NOT EXISTS api_audit (id TEXT PRIMARY KEY,owner TEXT NOT NULL,client_ip TEXT NOT NULL,operation TEXT NOT NULL,return_type TEXT,method TEXT NOT NULL,path TEXT NOT NULL,mode TEXT NOT NULL,started INTEGER NOT NULL,finished INTEGER,http_status INTEGER,outcome TEXT NOT NULL,duration_ms INTEGER,expires INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS audit_recent ON api_audit(started DESC);');
        if (!in_array('api_key_ref',array_column($this->query('PRAGMA table_info(api_audit)')->fetchAll(),'name'),true)) {
            try {$this->db->exec('ALTER TABLE api_audit ADD COLUMN api_key_ref TEXT');} catch (\PDOException $e) {if(!str_contains($e->getMessage(),'duplicate column'))throw $e;}
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS audit_keys (id TEXT PRIMARY KEY,secret TEXT NOT NULL,masked TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS audit_key_lookup ON api_audit(owner,api_key_ref,started);
            CREATE TABLE IF NOT EXISTS audit_key_owners (owner TEXT PRIMARY KEY,api_key_ref TEXT NOT NULL REFERENCES audit_keys(id) ON DELETE CASCADE,valid_from INTEGER NOT NULL);
            CREATE TRIGGER IF NOT EXISTS audit_key_snapshot AFTER INSERT ON api_audit WHEN NEW.api_key_ref IS NULL BEGIN
              UPDATE api_audit SET api_key_ref=(SELECT api_key_ref FROM audit_key_owners WHERE owner=NEW.owner AND valid_from<=NEW.started) WHERE id=NEW.id;
            END;
            CREATE TRIGGER IF NOT EXISTS audit_key_cleanup AFTER DELETE ON api_audit WHEN OLD.api_key_ref IS NOT NULL BEGIN
              DELETE FROM audit_keys WHERE id=OLD.api_key_ref AND NOT EXISTS(SELECT 1 FROM api_audit WHERE api_key_ref=OLD.api_key_ref);
            END;');
        $this->cleanup();
    }

    public function query(string $sql, array $args = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql); $statement->execute($args); return $statement;
    }

    public function seal(array $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR), $nonce, $this->key));
    }

    public function open(string $value): array
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Connection secret unavailable. Reconnect GST.');
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if ($plain === false) throw new RuntimeException('Connection secret unavailable. Reconnect GST.');
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    public function connection(string $owner, bool $authenticated = true): array
    {
        $row = $this->query('SELECT * FROM connections WHERE owner=?', [$owner])->fetch();
        if (!$row || (int) $row['expires'] < time() || ($authenticated && $row['state'] !== 'connected')) throw new RuntimeException('Your GST session has expired. Please connect again.');
        return $row;
    }

    public function jobs(string $owner): array
    {
        $this->cleanup();
        return $this->query('SELECT id,batch_id,return_type,gstin,financial_year,period,status,job_id,message,created,updated,retry_allowed,upstream_status,downloaded_at,expires_at,poll_errors FROM jobs WHERE owner=? ORDER BY created DESC,rowid ASC LIMIT 1000', [$owner])->fetchAll();
    }

    public function job(string $owner, string $id): array
    {
        $this->assertWindow($owner);
        $this->cleanup();
        $row = $this->query('SELECT * FROM jobs WHERE owner=? AND id=?', [$owner, $id])->fetch();
        if (!$row) throw new RuntimeException('Job not found.');
        return $row;
    }

    public function update(string $id, array $fields): void
    {
        $fields['updated'] = time();
        $this->query('UPDATE jobs SET ' . implode(',', array_map(static fn ($k) => $k . '=?', array_keys($fields))) . ' WHERE id=?', [...array_values($fields), $id]);
    }

    public function rateLimit(string $owner, string $action, int $limit = 5, int $window = 300): void
    {
        $this->query('INSERT INTO rate_limits(owner,action,started,count) VALUES(?,?,?,1) ON CONFLICT(owner,action) DO UPDATE SET count=CASE WHEN started<? THEN 1 ELSE count+1 END, started=CASE WHEN started<? THEN excluded.started ELSE started END', [$owner, $action, time(), time()-$window, time()-$window]);
        $row = $this->query('SELECT count FROM rate_limits WHERE owner=? AND action=?', [$owner, $action])->fetch();
        if ((int) $row['count'] > $limit) throw new RuntimeException('Too many attempts. Please wait a few minutes before trying again.');
    }

    public function cleanup(): void
    {
        $this->query('DELETE FROM jobs WHERE expires_at IS NOT NULL AND expires_at<=?', [time()]);
        $this->query('DELETE FROM windows WHERE expires<=?', [time()]);
        $this->query('DELETE FROM connections WHERE expires<=?', [time()]);
        $this->query('DELETE FROM api_audit WHERE expires<=?', [time()]);
        $this->query('DELETE FROM rate_limits WHERE started<=?', [time()-self::RETENTION_SECONDS]);
        foreach (glob($this->tempDir().'/ttl-*') ?: [] as $file) {
            if (preg_match('/^ttl-(\d+)-[a-f0-9]+\.zip(?:\.xml)?$/',basename($file),$match) && (int)$match[1]<=time()) @unlink($file);
        }
        // PHP session files contain only opaque IDs/CSRF/admin flags, never GST details or keys.
        foreach (glob(WRITEPATH.'session/ps_returns_session*') ?: [] as $file) {
            if (is_file($file) && filemtime($file)<=time()-self::RETENTION_SECONDS) @unlink($file);
        }
    }

    public function startWindow(string $owner, string $ip): int
    {
        $this->cleanup();
        // This is a separate undownloaded-work safeguard, not the post-download retention timer.
        $this->query('INSERT INTO windows(owner,expires,client_ip) VALUES(?,?,?) ON CONFLICT(owner) DO UPDATE SET expires=MAX(expires,excluded.expires)',[$owner,time()+86400,$ip]);
        return $this->assertWindow($owner);
    }

    public function expiresAt(string $owner): ?int
    {
        $expires=$this->query('SELECT expires FROM windows WHERE owner=? AND expires>?',[$owner,time()])->fetchColumn();
        return $expires===false?null:(int)$expires;
    }

    public function assertWindow(string $owner): int
    {
        $expires=$this->expiresAt($owner);
        if ($expires===null) { $this->cleanup(); throw new RuntimeException('This return workflow is no longer available. Start a new request.'); }
        return $expires;
    }

    public function temporaryZip(int $expires): string
    {
        if ($expires<=time()) throw new RuntimeException('The 10-minute data window has expired.');
        return $this->tempDir().'/ttl-'.min($expires,time()+600).'-'.bin2hex(random_bytes(12)).'.zip';
    }

    public function receipt(string $owner,array $ids): string
    {
        foreach ($ids as $id) if ($this->job($owner,$id)['status']!=='completed') throw new RuntimeException('Only completed returns can be downloaded.');
        return $this->seal(['owner'=>$owner,'ids'=>$ids,'issued'=>time(),'purpose'=>'download_ack']);
    }

    public function acknowledge(string $owner,string $receipt): array
    {
        $data=$this->open($receipt);
        if (($data['purpose']??'')!=='download_ack' || !hash_equals($owner,(string)($data['owner']??'')) || (int)($data['issued']??0)<time()-600 || !is_array($data['ids']??null) || count($data['ids'])>96) throw new RuntimeException('The download receipt is invalid or expired.');
        $now=time();$this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->query('UPDATE windows SET expires=MAX(expires,?) WHERE owner=?',[$now+self::RETENTION_SECONDS,$owner]);
            foreach ($data['ids'] as $id) {
                $job=$this->query('SELECT status,expires_at FROM jobs WHERE owner=? AND id=?',[$owner,$id])->fetch();
                if (!$job || $job['status']!=='completed' || ($job['expires_at']!==null && (int)$job['expires_at']<=$now)) throw new RuntimeException('A downloaded return has already expired.');
                $this->query('UPDATE jobs SET downloaded_at=?,expires_at=?,updated=? WHERE owner=? AND id=? AND downloaded_at IS NULL',[$now,$now+self::RETENTION_SECONDS,$now,$owner,$id]);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK');throw $e; }
        return ['message'=>'Download confirmed. These return records will be deleted 10 minutes after their first successful download.'];
    }

    public function downloadExpiry(string $owner): ?int
    {
        $value=$this->query('SELECT MIN(expires_at) FROM jobs WHERE owner=? AND expires_at>?',[$owner,time()])->fetchColumn();
        return $value===false||$value===null?null:(int)$value;
    }

    private function tempDir(): string
    {
        $dir=rtrim((string)env('GST_TEMP_DIR',WRITEPATH.'data'),'/\\');
        if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Temporary ZIP storage is unavailable.');
        return $dir;
    }

    public function auditStart(string $owner,string $ip,string $operation,?string $type,string $method,string $path,?string $apiKey=null): string
    {
        $expires=time()+max(1,min(365,(int)env('GST_AUDIT_RETENTION_DAYS',30)))*86400;$id=bin2hex(random_bytes(16));
        $ref=$apiKey!==null?$this->registerApiKey($apiKey):null;
        $this->query('INSERT INTO api_audit(id,owner,client_ip,operation,return_type,method,path,mode,started,outcome,expires,api_key_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$owner,$ip,$operation,$type,$method,$path,(string)env('GST_MODE','disabled'),time(),'in_progress',$expires,$ref]);
        return $id;
    }

    public function auditFinish(string $id,string $outcome,?int $http,int $duration): void
    {
        $this->query('UPDATE api_audit SET finished=?,outcome=?,http_status=?,duration_ms=? WHERE id=? AND expires>?',[time(),$outcome,$http,$duration,$id,time()]);
    }

    public function auditRows(string $ip='',string $outcome=''): array
    {
        return $this->auditCursor($ip,$outcome,500)->fetchAll();
    }

    public function auditCursor(string $ip='',string $outcome='',?int $limit=null): \PDOStatement
    {
        $this->cleanup();$this->backfillAuditKeys();$where=['a.expires>?']; $args=[time()];
        if ($ip!=='') { $where[]='client_ip=?';$args[]=$ip; }
        if ($outcome!=='') { $where[]='outcome=?';$args[]=$outcome; }
        return $this->query('SELECT a.id,a.client_ip,a.operation,a.return_type,a.method,a.path,a.mode,a.started,a.finished,a.http_status,a.outcome,a.duration_ms,a.expires,k.masked AS api_key_masked,CASE WHEN k.id IS NULL THEN 0 ELSE 1 END AS api_key_available FROM api_audit a LEFT JOIN audit_keys k ON k.id=a.api_key_ref WHERE '.implode(' AND ',$where).' ORDER BY a.started DESC,a.rowid DESC'.($limit===null?'':' LIMIT '.(int)$limit),$args);
    }

    private function registerApiKey(string $apiKey): string
    {
        $id=hash_hmac('sha256',$apiKey,$this->key);
        $masked=strlen($apiKey)>12?substr($apiKey,0,6).'…'.substr($apiKey,-4):'••••'.substr($apiKey,-2);
        $this->query('INSERT OR IGNORE INTO audit_keys(id,secret,masked) VALUES(?,?,?)',[$id,$this->seal(['apiKey'=>$apiKey]),$masked]);
        return $id;
    }

    public function rememberConnectionKey(string $owner,string $apiKey,int $since): void
    {
        $ref=$this->registerApiKey($apiKey);
        $this->query('INSERT INTO audit_key_owners(owner,api_key_ref,valid_from) VALUES(?,?,?) ON CONFLICT(owner) DO UPDATE SET api_key_ref=excluded.api_key_ref,valid_from=excluded.valid_from',[$owner,$ref,$since]);
        $this->query('UPDATE api_audit SET api_key_ref=? WHERE owner=? AND api_key_ref IS NULL AND started>=?',[$ref,$owner,$since]);
    }

    private function backfillAuditKeys(): void
    {
        $rows=$this->query('SELECT c.owner,c.secret,c.last_otp FROM connections c WHERE EXISTS(SELECT 1 FROM api_audit a WHERE a.owner=c.owner AND a.api_key_ref IS NULL AND a.started>=c.last_otp)')->fetchAll();
        foreach ($rows as $row) {
            try {$credentials=$this->open($row['secret']);if(isset($credentials['apiKey']))$this->rememberConnectionKey($row['owner'],$credentials['apiKey'],(int)$row['last_otp']);} catch (RuntimeException) {}
        }
    }

    public function revealAuditKey(string $auditId): ?string
    {
        $row=$this->query('SELECT k.secret FROM api_audit a JOIN audit_keys k ON k.id=a.api_key_ref WHERE a.id=? AND a.expires>?',[$auditId,time()])->fetch();
        return $row?$this->open($row['secret'])['apiKey']:null;
    }
}
