<?php
namespace App\Controllers;
use App\Libraries\ReturnStore;
use App\Libraries\ReturnGateway;
use App\Libraries\ClientIp;
use RuntimeException;
use Throwable;
use ZipArchive;

class ReturnsPortal extends BaseController
{
    private function clientIp(): string { return ClientIp::resolve((string)$this->request->getServer('REMOTE_ADDR'),$this->request->getHeaderLine('X-Forwarded-For')); }

    private function owner(): string
    {
        $session=session();
        if (!$session->has('return_owner')) { $session->regenerate(true); $session->set('return_owner',bin2hex(random_bytes(24))); }
        if (!$session->has('return_csrf')) $session->set('return_csrf',bin2hex(random_bytes(32)));
        return $session->get('return_owner');
    }

    private function guard(): void
    {
        $this->owner();
        if ((string)env('GST_MODE','disabled')==='fixture' && !in_array($this->request->getIPAddress(),['127.0.0.1','::1'],true)) throw new RuntimeException('Fixture mode is restricted to localhost.');
        if ($this->request->getMethod()==='POST' && !hash_equals(session()->get('return_csrf'),(string)$this->request->getHeaderLine('X-CSRF-Token'))) throw new RuntimeException('Your page session changed. Refresh the page and try again.');
    }

    private function result(callable $callback)
    {
        $this->response->setHeader('Cache-Control','no-store, private')->setHeader('X-Content-Type-Options','nosniff')->setHeader('X-Frame-Options','DENY')->setHeader('Referrer-Policy','no-referrer');
        try { $this->guard(); return $callback(); }
        catch (Throwable $e) {
            $message=$e instanceof RuntimeException?$e->getMessage():'The request could not be completed. Please try again.';
            return $this->response->setStatusCode(422)->setJSON(['error'=>$message]);
        }
    }

    private function input(): array
    {
        $data=$this->request->getJSON(true);
        if (!is_array($data)) throw new RuntimeException('Send a valid JSON request.');
        return $data;
    }

    public function index()
    {
        $this->owner();
        $this->response->setHeader('Cache-Control','no-store, private')->setHeader('X-Frame-Options','DENY')->setHeader('X-Content-Type-Options','nosniff')->setHeader('Referrer-Policy','no-referrer');
        return view('returns_portal',['csrf'=>session()->get('return_csrf')]);
    }

    public function health()
    {
        try { (new ReturnStore())->query('SELECT 1'); return $this->response->setJSON(['status'=>'ok']); }
        catch (Throwable) { return $this->response->setStatusCode(503)->setJSON(['status'=>'unavailable']); }
    }

    public function state()
    {
        return $this->result(function() {
            $store=new ReturnStore(); $gateway=new ReturnGateway(); $connection=null;
            try { $row=$store->connection($this->owner(),false); $connection=['gstin'=>$row['gstin'],'username'=>$row['username'],'state'=>$row['state'],'expires'=>(int)$row['expires'],'resendAt'=>(int)$row['last_otp']+60]; } catch (RuntimeException) {}
            $specs=[]; foreach (ReturnGateway::TYPES as $type) $specs[$type]=$gateway->spec($type);
            $heartbeat=WRITEPATH.'data/worker-v3-heartbeat';$janitor=WRITEPATH.'data/janitor-v3-heartbeat';
            return $this->response->setJSON(['mode'=>$gateway->fixture?'fixture':(string)env('GST_MODE','disabled'),'ready'=>$gateway->ready(),'connection'=>$connection,'jobs'=>$store->jobs($this->owner()),'specs'=>$specs,'workerOnline'=>is_file($heartbeat) && time()-(int)file_get_contents($heartbeat)<75,'janitorOnline'=>is_file($janitor) && time()-(int)file_get_contents($janitor)<10,'pollSeconds'=>max(1,(int)env('GST_POLL_SECONDS',10)),'retentionSeconds'=>ReturnStore::RETENTION_SECONDS,'dataExpiresAt'=>$store->downloadExpiry($this->owner()),'serverTime'=>time()]);
        });
    }

    public function otp()
    {
        return $this->result(function() {
            $data=$this->input(); $store=new ReturnStore(); $gateway=new ReturnGateway(); $owner=$this->owner();
            $store->rateLimit($owner,'otp');
            if (!$gateway->ready()) throw new RuntimeException('Live API contract configuration is incomplete.');
            if (($data['resend']??false)===true) {
                $pending=$store->connection($owner,false);
                if ($pending['state']!=='otp_pending') throw new RuntimeException('Request an OTP first.');
                $credentials=$store->open($pending['secret']);
            } else $credentials=['apiKey'=>trim((string)($data['apiKey']??'')),'username'=>trim((string)($data['username']??'')),'gstin'=>strtoupper(trim((string)($data['gstin']??'')))];
            if (strlen($credentials['apiKey'])<3 || strlen($credentials['apiKey'])>512 || preg_match('/[\r\n]/',$credentials['apiKey'])) throw new RuntimeException('Enter a valid API key.');
            if (!preg_match('/^[A-Za-z0-9_.@-]{3,100}$/',$credentials['username'])) throw new RuntimeException('Enter a valid GST username (3–100 characters).');
            if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',$credentials['gstin']) || (int)substr($credentials['gstin'],0,2)<1 || (int)substr($credentials['gstin'],0,2)>38) throw new RuntimeException('Enter a valid 15-character GSTIN.');
            $active=$store->query("SELECT COUNT(*) FROM jobs WHERE owner=? AND status IN ('queued','initiating','processing')",[$owner])->fetchColumn();
            if ($active) throw new RuntimeException('Wait for the current batch before changing your connection.');
            $existing=$store->query('SELECT last_otp FROM connections WHERE owner=?',[$owner])->fetch();
            if ($existing && time()-(int)$existing['last_otp']<60) throw new RuntimeException('Please wait 60 seconds before requesting another OTP.');
            $store->startWindow($owner,$this->clientIp());
            $gateway->audited($store,$owner,$this->clientIp())->otp($credentials);
            $store->query("INSERT INTO connections(owner,gstin,username,secret,state,expires,attempts,last_otp) VALUES(?,?,?,?,'otp_pending',?,0,?) ON CONFLICT(owner) DO UPDATE SET gstin=excluded.gstin,username=excluded.username,secret=excluded.secret,state=excluded.state,expires=excluded.expires,attempts=0,last_otp=excluded.last_otp",[$owner,$credentials['gstin'],$credentials['username'],$store->seal($credentials),time()+600,time()]);
            $store->rememberConnectionKey($owner,$credentials['apiKey'],time());
            return $this->response->setJSON(['message'=>$gateway->fixture?'Local OTP initiated. Use test code 123456.':'OTP initiation confirmed. Enter the code received on your registered mobile.']);
        });
    }

    public function connect()
    {
        return $this->result(function() {
            $data=$this->input(); $store=new ReturnStore(); $owner=$this->owner(); $row=$store->connection($owner,false);
            $store->rateLimit($owner,'connect',8);
            if ($row['state']!=='otp_pending') throw new RuntimeException('Request an OTP before connecting.');
            $otp=trim((string)($data['otp']??''));
            if (!preg_match('/^[A-Za-z0-9]{4,10}$/',$otp)) throw new RuntimeException('Enter the OTP received from GST.');
            $credentials=$store->open($row['secret']); $credentials['session']=(new ReturnGateway())->audited($store,$owner,$this->clientIp())->connect($credentials,$otp);
            $store->query("UPDATE connections SET state='connected',secret=?,expires=? WHERE owner=?",[$store->seal($credentials),time()+30*86400,$owner]);
            return $this->response->setJSON(['message'=>'GST connected. Return selection is now available.']);
        });
    }

    public function disconnect()
    {
        return $this->result(function() {
            $store=new ReturnStore(); $owner=$this->owner();
            if ($store->query("SELECT COUNT(*) FROM jobs WHERE owner=? AND status IN ('queued','initiating','processing')",[$owner])->fetchColumn()) throw new RuntimeException('Wait for the active batch before disconnecting.');
            $row=$store->query('SELECT * FROM connections WHERE owner=?',[$owner])->fetch();
            if ($row && $row['state']==='connected' && (int)$row['expires']>time()) {
                $store->startWindow($owner,$this->clientIp());
                (new ReturnGateway())->audited($store,$owner,$this->clientIp())->disconnect($store->open($row['secret']));
            }
            $store->query('DELETE FROM connections WHERE owner=?',[$owner]);
            return $this->response->setJSON(['message'=>'GST credentials removed. Job history remains available in this browser session.']);
        });
    }

    public function batch()
    {
        return $this->result(function() {
            $data=$this->input(); $store=new ReturnStore(); $gateway=new ReturnGateway(); $owner=$this->owner(); $connection=$store->connection($owner);
            if (!$gateway->ready()) throw new RuntimeException('Complete the live API contract configuration first.');
            $types=$data['returns']??[];
            if (!is_array($types) || !$types || count($types)>8 || count(array_unique($types))!==count($types) || array_diff($types,ReturnGateway::TYPES)) throw new RuntimeException('Select between one and eight supported returns.');
            $requestKey=(string)($data['requestKey']??'');
            if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$requestKey)) throw new RuntimeException('Invalid submission identifier. Refresh and try again.');
            $fy=(string)($data['financialYear']??''); $month=(string)($data['month']??'');$scope=(string)($data['scope']??'monthly');
            $periods=[]; foreach ($types as $type) $periods[$type]=$gateway->periods($type,$fy,$month,$scope);
            $store->startWindow($owner,$this->clientIp());
            $store->db->exec('BEGIN IMMEDIATE');
            try {
                $existing=$store->query('SELECT id FROM batches WHERE owner=? AND request_key=?',[$owner,$requestKey])->fetchColumn();
                if ($existing) { $store->db->exec('COMMIT'); return $this->response->setJSON(['batchId'=>$existing,'message'=>'This batch has already been submitted.']); }
                if ($store->query("SELECT COUNT(*) FROM jobs WHERE owner=? AND status IN ('queued','initiating','processing')",[$owner])->fetchColumn()) throw new RuntimeException('A batch is already active. Follow its progress below.');
                $store->rateLimit($owner,'batches',20,3600);
                $batch=bin2hex(random_bytes(16)); $now=time();
                $store->query('INSERT INTO batches(id,owner,request_key,created,scope) VALUES(?,?,?,?,?)',[$batch,$owner,$requestKey,$now,$scope]);
                $count=0;
                foreach (ReturnGateway::TYPES as $type) if (in_array($type,$types,true)) foreach ($periods[$type] as $period) {
                    $store->query("INSERT INTO jobs(id,batch_id,owner,return_type,gstin,financial_year,period,status,message,created,updated,client_ip) VALUES(?,?,?,?,?,?,?,'queued','Waiting for sequential initiation.',?,?,?)",[bin2hex(random_bytes(16)),$batch,$owner,$type,$connection['gstin'],$fy,$period,$now,$now,$this->clientIp()]);$count++;
                }
                $store->db->exec('COMMIT');
            } catch (Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
            return $this->response->setJSON(['batchId'=>$batch,'message'=>$count.' monthly return requests queued for sequential processing.']);
        });
    }

    public function retry(string $id)
    {
        return $this->result(function() use($id) {
            $store=new ReturnStore(); $job=$store->job($this->owner(),$id); $connection=$store->connection($this->owner());
            if ($connection['gstin']!==$job['gstin']) throw new RuntimeException('Reconnect the GSTIN belonging to this job.');
            $store->rateLimit($this->owner(),'retry',12);
            if (!(int)$job['retry_allowed']) throw new RuntimeException('This return is not eligible for automatic retry. Check the provider outcome first.');
            if (in_array($job['status'],['timeout','monitoring_error'],true) && $job['job_id']) $store->query("UPDATE jobs SET status='processing',message='Resuming status checks for the existing job.',retry_allowed=0,next_poll=?,deadline=?,poll_errors=0,updated=? WHERE id=? AND status IN ('timeout','monitoring_error')",[time(),min($store->assertWindow($this->owner()),time()+max(60,(int)env('GST_JOB_TIMEOUT_SECONDS',540))),time(),$id]);
            elseif ($job['status']==='failed' && ((new ReturnGateway())->fixture || ((new ReturnGateway())->contract['allowInitiationRetry']??false))) $store->query("UPDATE jobs SET status='queued',message='Retry queued.',retry_allowed=0,job_id=NULL,upstream_status=NULL,updated=? WHERE id=? AND status='failed'",[time(),$id]);
            else throw new RuntimeException('Retry is unavailable for this job.');
            return $this->response->setJSON(['message'=>'The selected return has been queued for retry.']);
        });
    }

    private function zipBytes(ReturnStore $store, array $job): string
    {
        if ($job['status']!=='completed') throw new RuntimeException('This job is not completed.');
        $row=$store->connection($this->owner());
        if ($row['gstin']!==$job['gstin']) throw new RuntimeException('Reconnect the GSTIN associated with this download.');
        $bytes=(new ReturnGateway())->audited($store,$this->owner(),$this->clientIp())->download($store->open($row['secret']),$job);
        $path=$store->temporaryZip($store->assertWindow($this->owner()));
        try {
            file_put_contents($path,$bytes); $zip=new ZipArchive(); $valid=$zip->open($path,ZipArchive::CHECKCONS);
            if ($valid!==true) throw new RuntimeException('Provider response is not a valid ZIP file. Please retry the download.');
            $zip->close(); $store->assertWindow($this->owner()); return $bytes;
        } finally { @unlink($path); }
    }

    private function filename(array $job): string { return $job['gstin'].'_'.str_replace('-','',$job['return_type']).'_'.$job['financial_year'].'_'.$job['period'].'_'.substr($job['id'],0,6).'.zip'; }

    private function attachment(string $bytes,string $name,ReturnStore $store,array $ids)
    {
        return $this->response->setHeader('Content-Type','application/zip')->setHeader('X-Download-Receipt',$store->receipt($this->owner(),$ids))->setHeader('Content-Disposition','attachment; filename="'.$name.'"')->setBody($bytes);
    }

    public function download(string $id)
    {
        return $this->result(function() use($id) { $store=new ReturnStore(); $store->rateLimit($this->owner(),'download',120); $job=$store->job($this->owner(),$id); return $this->attachment($this->zipBytes($store,$job),$this->filename($job),$store,[$id]); });
    }

    public function acknowledgeDownload()
    {
        return $this->result(function() { $data=$this->input();return $this->response->setJSON((new ReturnStore())->acknowledge($this->owner(),(string)($data['receipt']??''))); });
    }

    public function downloadAll()
    {
        return $this->result(function() {
            $store=new ReturnStore(); $store->rateLimit($this->owner(),'downloadAll',5); $data=$this->input(); $batch=(string)($data['batchId']??'');
            $jobs=$store->query("SELECT * FROM jobs WHERE owner=? AND batch_id=? AND status='completed' ORDER BY rowid",[$this->owner(),$batch])->fetchAll();
            if (!$jobs || count($jobs)>96) throw new RuntimeException('Choose a batch with completed returns.');
            $path=$store->temporaryZip($store->assertWindow($this->owner())); $zip=new ZipArchive(); $opened=false;
            try {
                if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Unable to prepare ZIP archive.'); $opened=true; $size=0;
                foreach ($jobs as $job) { $bytes=$this->zipBytes($store,$job); $size+=strlen($bytes); if ($size>100*1024*1024) throw new RuntimeException('Combined ZIP exceeds 100 MB. Download these returns individually.'); $zip->addFromString($this->filename($job),$bytes); }
                $zip->close(); $opened=false;
                $store->assertWindow($this->owner());
                return $this->attachment(file_get_contents($path),'PrimeServe_'.$jobs[0]['gstin'].'_completed_returns.zip',$store,array_column($jobs,'id'));
            } finally { if ($opened) $zip->close(); @unlink($path); }
        });
    }
}
