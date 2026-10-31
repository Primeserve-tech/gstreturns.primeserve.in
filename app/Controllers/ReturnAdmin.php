<?php
namespace App\Controllers;
use App\Libraries\ReturnStore;
use App\Libraries\ReturnGateway;
use App\Libraries\ClientIp;
use App\Libraries\AuditExcel;
use Throwable;

class ReturnAdmin extends BaseController
{
    private function headers(): void
    {
        $this->response->setHeader('Cache-Control','no-store, private')->setHeader('X-Frame-Options','DENY')->setHeader('X-Content-Type-Options','nosniff')->setHeader('Referrer-Policy','no-referrer');
    }
    private function token(): string
    {
        if (!session()->has('admin_csrf')) session()->set('admin_csrf',bin2hex(random_bytes(32)));
        return session()->get('admin_csrf');
    }
    private function credentials(): array
    {
        $path=WRITEPATH.'data/admin-auth.enc';
        if (is_file($path)) return (new ReturnStore())->open(file_get_contents($path));
        return ['username'=>(string)env('GST_ADMIN_USERNAME',''),'hash'=>(string)env('GST_ADMIN_PASSWORD_HASH','')];
    }
    private function fingerprint(array $credentials): string { return hash('sha256',$credentials['username']."\0".$credentials['hash']); }
    private function authenticated(): bool
    {
        return (int)session()->get('admin_until')>time() && hash_equals($this->fingerprint($this->credentials()),(string)session()->get('admin_fingerprint'));
    }
    public function login()
    {
        $this->headers();
        if ($this->authenticated()) return redirect()->to('/admin/reports');
        return view('return_admin',['csrf'=>$this->token(),'authenticated'=>false,'error'=>null]);
    }
    public function authenticate()
    {
        $this->headers();$error='Sign-in failed. Check your credentials or wait before trying again.';
        try {
            if (!hash_equals($this->token(),(string)$this->request->getPost('csrf'))) throw new \RuntimeException('CSRF');
            $ip=ClientIp::resolve((string)$this->request->getServer('REMOTE_ADDR'),$this->request->getHeaderLine('X-Forwarded-For'));
            $store=new ReturnStore();$store->rateLimit('admin-'.hash_hmac('sha256',$ip,(string)env('GST_ENCRYPTION_KEY','')),'admin-login',10,600);
            $username=(string)$this->request->getPost('username');$password=(string)$this->request->getPost('password');
            $credentials=$this->credentials();$hash=$credentials['hash'];$configured=$credentials['username'];
            if ($hash==='' || $configured==='' || strlen($password)>512 || !hash_equals($configured,$username) || !password_verify($password,$hash)) throw new \RuntimeException('Authentication');
            session()->regenerate(true);session()->set('admin_until',time()+1800);session()->set('admin_csrf',bin2hex(random_bytes(32)));
            session()->set('admin_fingerprint',$this->fingerprint($credentials));
            return redirect()->to('/admin/reports');
        } catch (Throwable) { return $this->response->setStatusCode(401)->setBody(view('return_admin',['csrf'=>$this->token(),'authenticated'=>false,'error'=>$error])); }
    }
    public function reports()
    {
        $this->headers();if (!$this->authenticated()) return redirect()->to('/admin');
        return view('return_admin',['csrf'=>$this->token(),'authenticated'=>true,'error'=>null]);
    }
    public function data()
    {
        $this->headers();if (!$this->authenticated()) return $this->response->setStatusCode(401)->setJSON(['error'=>'Admin session expired. Sign in again.']);
        try {
            $ip=trim((string)$this->request->getGet('ip'));$outcome=trim((string)$this->request->getGet('outcome'));
            if ($ip!=='' && !filter_var($ip,FILTER_VALIDATE_IP)) throw new \RuntimeException('Enter an exact IPv4 or IPv6 address.');
            if (!in_array($outcome,['','success','failed','processing','completed','empty','in_progress'],true)) throw new \RuntimeException('Invalid outcome filter.');
            $store=new ReturnStore();$rows=$store->auditRows($ip,$outcome);$gateway=new ReturnGateway();
            return $this->response->setJSON(['rows'=>$rows,'retentionDays'=>max(1,min(365,(int)env('GST_AUDIT_RETENTION_DAYS',30))),'dataRetentionSeconds'=>ReturnStore::RETENTION_SECONDS,'mode'=>(string)env('GST_MODE','disabled'),'apiReady'=>$gateway->ready(),'serverTime'=>time(),'adminExpiresAt'=>(int)session()->get('admin_until')]);
        } catch (Throwable $e) { return $this->response->setStatusCode(422)->setJSON(['error'=>$e instanceof \RuntimeException?$e->getMessage():'Unable to load audit records.']); }
    }
    public function logout()
    {
        $this->headers();if (!hash_equals($this->token(),(string)$this->request->getPost('csrf'))) return $this->response->setStatusCode(403)->setBody('Invalid session token.');
        session()->remove(['admin_until','admin_csrf','admin_fingerprint']);session()->regenerate(true);return redirect()->to('/admin');
    }

    private function adminPost(): void
    {
        if (!$this->authenticated()) throw new \RuntimeException('Admin session expired. Sign in again.');
        if (!hash_equals($this->token(),$this->request->getHeaderLine('X-CSRF-Token'))) throw new \RuntimeException('Your admin session changed. Refresh and try again.');
    }

    public function revealKey(string $id)
    {
        $this->headers();if(!$this->authenticated())return $this->response->setStatusCode(401)->setJSON(['error'=>'Admin sign-in required.']);
        try {$this->adminPost();$key=(new ReturnStore())->revealAuditKey($id);if($key===null)throw new \RuntimeException('The API key was not captured for this historical record.');return $this->response->setJSON(['apiKey'=>$key]);}
        catch(Throwable $e){return $this->response->setStatusCode(422)->setJSON(['error'=>$e instanceof \RuntimeException?$e->getMessage():'Unable to show the API key.']);}
    }

    public function export()
    {
        $this->headers();if(!$this->authenticated())return $this->response->setStatusCode(401)->setJSON(['error'=>'Admin sign-in required.']);
        try {
            $this->adminPost();$data=$this->request->getJSON(true)??[];$ip=trim((string)($data['ip']??''));$outcome=(string)($data['outcome']??'');
            if(($ip!==''&&!filter_var($ip,FILTER_VALIDATE_IP))||!in_array($outcome,['','success','failed','processing','completed','empty','in_progress'],true))throw new \RuntimeException('Choose valid export filters.');
            $bytes=(new AuditExcel())->build(new ReturnStore(),$ip,$outcome,($data['includeKeys']??false)===true);
            $stamp=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Ymd_His');
            return $this->response->setHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setHeader('Content-Disposition','attachment; filename="PrimeServe_API_Logs_'.$stamp.'.xlsx"')->setBody($bytes);
        } catch(Throwable $e){return $this->response->setStatusCode(422)->setJSON(['error'=>$e instanceof \RuntimeException?$e->getMessage():'Unable to export API logs.']);}
    }
}
