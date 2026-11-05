<?php
namespace App\Libraries;
use RuntimeException;
use Throwable;

/** Customer accounting uses the existing SQLite connection so jobs and funds reserve atomically. */
final class CustomerAccounts
{
    public const SERVICES=['returns','challan-monthly','challan-yearly','certificate'];
    public function __construct(public ReturnStore $store,private ?\Closure $deliver=null)
    {
        $exists=$store->query("SELECT name FROM sqlite_master WHERE type='table' AND name='customer_migrations'")->fetchColumn();
        if(!$exists) $this->atomic(function(){ $this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v1.sql')); });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=2')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=2')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v2.sql'));
        });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=3')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=3')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v3.sql'));
        });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=4')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=4')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v4.sql'));
        });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=5')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=5')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v5.sql'));
        });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=6')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=6')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v6.sql'));
        });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=7')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=7')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v7.sql'));
        });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=8')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=8')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v8.sql'));
        });
        if(!$store->query('SELECT version FROM customer_migrations WHERE version=9')->fetchColumn())$this->atomic(function(){
            if(!$this->store->query('SELECT version FROM customer_migrations WHERE version=9')->fetchColumn())$this->store->db->exec(file_get_contents(APPPATH.'Database/customer-v9.sql'));
        });
        $this->store->query('DELETE FROM registration_attempts WHERE expires<?',[time()-3600]);
        $this->store->query("UPDATE registration_attempts SET payload='',verifier='',used=1 WHERE expires<=? AND used=0",[time()]);
        $this->store->query('DELETE FROM customer_sessions WHERE expires<=?',[time()]);
        $this->store->query('DELETE FROM password_resets WHERE expires<=? OR used=1',[time()]);
        $this->store->query("UPDATE billing_usage SET status='review',updated=? WHERE status='reserved' AND created<?",[time(),time()-3600]);
    }
    public static function enabled():bool{return (string)env('GST_CUSTOMERS_ENABLED','0')==='1';}
    public static function id():string{return bin2hex(random_bytes(20));}
    public function atomic(callable $fn):mixed
    {
        $this->store->db->exec('BEGIN IMMEDIATE');
        try{$result=$fn();$this->store->db->exec('COMMIT');return $result;}
        catch(Throwable $e){$this->store->db->exec('ROLLBACK');throw $e;}
    }
    public static function email(string $email):string
    {
        $email=strtolower(trim($email));if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');return $email;
    }
    public static function mobile(string $mobile):string
    {
        $mobile=trim($mobile);if(str_starts_with($mobile,'+91'))$mobile=substr($mobile,3);
        if(!preg_match('/^[6-9][0-9]{9}$/D',$mobile))throw new RuntimeException('Enter a valid 10-digit Indian mobile number starting with 6, 7, 8 or 9.');
        if(preg_match('/^(\d)\1{9}$/D',$mobile)||str_contains('01234567890123456789',$mobile)||str_contains('98765432109876543210',$mobile))throw new RuntimeException('Enter a valid mobile number. Repeated-digit and ascending or descending sequences are not allowed.');
        return '+91'.$mobile;
    }
    public static function password(string $password):void
    {
        if($password===''||strlen($password)>4096||str_contains($password,"\0"))throw new RuntimeException('Enter a valid password.');
    }
    public static function passwordHash(string $password):string{return '$ps1$'.password_hash(base64_encode(hash('sha384',$password,true)),PASSWORD_DEFAULT);}
    public static function passwordMatches(string $password,string $hash):bool{return str_starts_with($hash,'$ps1$')?password_verify(base64_encode(hash('sha384',$password,true)),substr($hash,5)):password_verify($password,$hash);}
    public function setting(string $name):string{return (string)$this->store->query('SELECT value FROM customer_settings WHERE name=?',[$name])->fetchColumn();}
    public function audit(string $actor,string $action,string $subject,array $detail=[]):void
    {
        $this->store->query('INSERT INTO customer_audit VALUES(?,?,?,?,?,?)',[self::id(),$actor,$action,$subject,json_encode($detail,JSON_THROW_ON_ERROR),time()]);
    }
    public function limit(string $bucket,int $max,int $seconds):void
    {
        // Separate from return-data cleanup: hourly OTP counters must survive the full hour.
        $bucket=hash_hmac('sha256',$bucket,(string)env('GST_ENCRYPTION_KEY',''));
        $this->store->query('INSERT INTO customer_limits VALUES(?,?,1) ON CONFLICT(bucket) DO UPDATE SET count=CASE WHEN started<=? THEN 1 ELSE count+1 END,started=CASE WHEN started<=? THEN excluded.started ELSE started END',[$bucket,time(),time()-$seconds,time()-$seconds]);
        if((int)$this->store->query('SELECT count FROM customer_limits WHERE bucket=?',[$bucket])->fetchColumn()>$max)throw new RuntimeException('Too many attempts. Please try again later.');
    }
    private function duplicates(string $email,string $mobile):void
    {
        if($this->store->query('SELECT id FROM customers WHERE email=?',[$email])->fetchColumn()||$this->store->query('SELECT customer_id FROM bonus_awards WHERE email=?',[$email])->fetchColumn())throw new RuntimeException('An account already exists with this email address. Please log in or reset your password.');
        if($this->store->query('SELECT id FROM customers WHERE mobile=?',[$mobile])->fetchColumn()||$this->store->query('SELECT customer_id FROM bonus_awards WHERE mobile=?',[$mobile])->fetchColumn())throw new RuntimeException('This mobile number is already associated with an account. Please log in or contact support.');
    }
    private function mail(?string $customer,string $email,string $name,string $kind,string $subject,string $body):bool
    {
        if(strcasecmp(trim($email),'info@primeserve.in')===0)return false;
        $id=self::id();$this->store->query('INSERT INTO email_events VALUES(?,?,?,?,?)',[$id,$customer,$kind,'sending',time()]);
        try{if($this->deliver)($this->deliver)($email,$name,$subject,$body);else(new ZohoMailer())->send($email,$name,$subject,$body);$status='accepted';}
        catch(Throwable){$status='failed';}
        $this->store->query('UPDATE email_events SET status=? WHERE id=?',[$status,$id]);return $status==='accepted';
    }
    public function register(array $data,string $ip):string
    {
        if(($data['privacyConsent']??false)!==true||($data['privacyVersion']??'')!==PrivacyConsent::VERSION)throw new RuntimeException('Please read the Privacy Notice and give consent before registration.');
        $email=self::email((string)($data['email']??''));$mobile=self::mobile((string)($data['mobile']??''));$name=trim((string)($data['name']??''));$company=trim((string)($data['company']??''));$password=(string)($data['password']??'');
        if(mb_strlen($name)<2||mb_strlen($name)>150||mb_strlen($company)<2||mb_strlen($company)>200)throw new RuntimeException('Enter your full name and company name.');
        self::password($password);if(!hash_equals($password,(string)($data['confirm']??'')))throw new RuntimeException('Passwords do not match.');
        $this->limit('registration-ip:'.$ip,20,3600);
        try{$this->duplicates($email,$mobile);}catch(RuntimeException $e){$this->audit('registration','duplicate-registration',hash_hmac('sha256',$email,(string)env('GST_ENCRYPTION_KEY','')),['reason'=>str_contains($e->getMessage(),'mobile')?'mobile':'email']);throw $e;}
        if($this->deliver===null&&!(new ZohoMailer())->configured())throw new RuntimeException('Email verification is temporarily unavailable. Please contact Primeserve support before registering.');
        $otp=(string)random_int(100000,999999);$id=self::id();
        $this->atomic(function()use($email,$mobile,$name,$company,$password,$otp,$id){
            $recent=$this->store->query('SELECT sent FROM registration_attempts WHERE email=? ORDER BY sent DESC LIMIT 1',[$email])->fetchColumn();
            if($recent!==false&&(int)$recent>time()-60)throw new RuntimeException('Please wait 60 seconds before requesting another OTP.');
            if((int)$this->store->query('SELECT COUNT(*) FROM registration_attempts WHERE email=? AND sent>?',[$email,time()-3600])->fetchColumn()>=3)throw new RuntimeException('You can request at most 3 email OTPs per hour.');
            $this->store->query('UPDATE registration_attempts SET used=1,payload=\'\',verifier=\'\' WHERE email=?',[$email]);
            $payload=$this->store->seal(['name'=>$name,'company'=>$company,'password_hash'=>self::passwordHash($password),'privacy_version'=>PrivacyConsent::VERSION]);
            $this->store->query('INSERT INTO registration_attempts(id,email,mobile,payload,verifier,sent,expires,delivery) VALUES(?,?,?,?,?,?,?,?)',[$id,$email,$mobile,$payload,password_hash($this->otpVerifier($otp),PASSWORD_DEFAULT),time(),time()+300,'sending']);
        });
        $sent=$this->mail(null,$email,$name,'registration-otp','Verify your Primeserve email','Your verification code is '.$otp.'. It expires in 5 minutes. Do not share this code.');
        $this->store->query('UPDATE registration_attempts SET delivery=? WHERE id=?',[$sent?'accepted':'failed',$id]);
        if(!$sent){$this->store->query("UPDATE registration_attempts SET used=1,payload='',verifier='' WHERE id=?",[$id]);throw new RuntimeException('Email delivery could not be confirmed. Please contact support or retry later.');}return $id;
    }
    public function verify(string $id,string $otp,string $ip):array
    {
        $this->limit('verify-ip:'.$ip,30,3600);
        // Commit incorrect attempts; throwing inside the transaction would roll the counter back.
        $result=$this->atomic(function()use($id,$otp){
            $r=$this->store->query('SELECT * FROM registration_attempts WHERE id=?',[$id])->fetch();
            if(!$r||$r['used']||(int)$r['expires']<=time()||(int)$r['attempts']>=5||$r['delivery']!=='accepted')return ['error'=>'This OTP has expired or is no longer valid. Request a new OTP.'];
            if(!preg_match('/^[0-9]{6}$/D',$otp)||!password_verify($this->otpVerifier($otp),$r['verifier'])){$this->store->query('UPDATE registration_attempts SET attempts=attempts+1 WHERE id=?',[$id]);return ['error'=>'Incorrect OTP.'];}
            $this->duplicates($r['email'],$r['mobile']);$p=$this->store->open($r['payload']);$customer=self::id();
            if(($p['privacy_version']??'')!==PrivacyConsent::VERSION)throw new RuntimeException('Please register again after reading the current Privacy Notice.');
            $this->store->query('INSERT INTO customers(id,name,email,mobile,company,password_hash,created) VALUES(?,?,?,?,?,?,?)',[$customer,$p['name'],$r['email'],$r['mobile'],$p['company'],$p['password_hash'],time()]);
            (new PrivacyConsent($this))->grant($customer);
            foreach(self::SERVICES as $service)$this->store->query('INSERT INTO customer_prices(customer_id,service,net,enabled) VALUES(?,?,?,1)',[$customer,$service,(int)$this->setting('price_'.$service)]);
            $bonus=$this->setting('bonus_active')==='1'?(int)$this->setting('bonus_paise'):0;
            if($bonus>0){$this->store->query('INSERT INTO bonus_awards VALUES(?,?,?,?,?)',[$customer,$r['email'],$r['mobile'],$bonus,time()]);$this->credit($customer,$bonus,'signup-bonus','bonus:'.$customer);}
            $this->store->query("UPDATE registration_attempts SET used=1,payload='',verifier='' WHERE id=?",[$id]);$this->audit($customer,'registered',$customer,['bonus'=>$bonus]);
            return ['id'=>$customer,'email'=>$r['email'],'name'=>$p['name'],'bonus'=>$bonus];
        });
        if(isset($result['error']))throw new RuntimeException($result['error']);
        $this->mail($result['id'],$result['email'],$result['name'],'welcome','Welcome to Primeserve','Your email is verified and your prepaid account is ready. Please log in to continue.');
        if($result['bonus']>0)$this->mail($result['id'],$result['email'],$result['name'],'signup-bonus','Primeserve signup bonus credited','Promotional credit of ₹'.number_format($result['bonus']/100,2).' has been added to your wallet.');
        $registered=$this->store->query('SELECT company,mobile FROM customers WHERE id=?',[$result['id']])->fetch();
        $this->mail($result['id'],'primeserve45@gmail.com','Primeserve Admin','new-customer','New customer registered - Primeserve',"A new customer has completed email verification.\n\nName: ".$result['name']."\nEmail: ".$result['email']."\nCompany: ".$registered['company']."\nMobile: ".$registered['mobile']."\nBilling: PREPAID\nSignup bonus: ₹".number_format($result['bonus']/100,2)."\n\nManage this customer in Admin → Customers.");
        return ['message'=>'Registration complete. Please log in.','redirect'=>'/login'];
    }
    public function login(string $email,string $password,string $owner,string $ip):array
    {
        $this->limit('login-ip:'.$ip,20,900);$email=self::email($email);$this->limit('login-email:'.$email,10,900);
        $r=$this->store->query('SELECT * FROM customers WHERE email=?',[$email])->fetch();
        $hash=$r['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if($password===''||strlen($password)>4096||str_contains($password,"\0")||!self::passwordMatches($password,$hash)||!$r)throw new RuntimeException('Email or password is incorrect.');
        if(!(int)$r['enabled'])throw new RuntimeException('Your account has been blocked by Admin. Reason: '.($r['access_reason']?:'Please contact support@primeserve.in for assistance.'));
        $this->store->query('INSERT INTO customer_sessions(owner,customer_id,expires) VALUES(?,?,?)',[$owner,$r['id'],time()+28800]);$this->audit($r['id'],'login',$r['id']);return $this->publicCustomer($r);
    }
    public function customer(string $owner):array
    {
        $r=$this->store->query('SELECT c.*,s.expires AS session_expires,s.api_secret FROM customer_sessions s JOIN customers c ON c.id=s.customer_id WHERE s.owner=? AND s.expires>? AND c.enabled=1',[$owner,time()])->fetch();
        if(!$r)throw new RuntimeException('Please log in to continue.');return $r;
    }
    public function publicCustomer(array $r):array
    {
        return array_intersect_key($r,array_flip(['id','name','email','mobile','company','mode','balance','outstanding','credit_limit','terms_days','state_code','billing_gstin','created']));
    }
    public function dashboard(string $owner):array
    {
        $c=$this->customer($owner);$id=$c['id'];$prepaid=$c['mode']==='PREPAID';
        $month=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m');
        $totals=$this->store->query("SELECT COALESCE(SUM(CASE WHEN kind='signup-bonus' THEN amount ELSE 0 END),0) AS bonus,COALESCE(SUM(CASE WHEN kind='paid-recharge' THEN amount ELSE 0 END),0) AS paid FROM customer_ledger WHERE customer_id=?",[$id])->fetch();
        $charges=(int)$this->store->query("SELECT COALESCE(SUM(gross),0) FROM billing_usage WHERE customer_id=? AND mode='PREPAID' AND status='charged'",[$id])->fetchColumn();
        $consumption=(int)$this->store->query("SELECT COALESCE(SUM(gross),0) FROM billing_usage WHERE customer_id=? AND mode='POSTPAID' AND status='charged' AND strftime('%Y-%m',created,'unixepoch','+330 minutes')=?",[$id,$month])->fetchColumn();
        $cards=$prepaid?[
            ['label'=>'Available Wallet Balance','value'=>(int)$c['balance'],'kind'=>'money'],
            ['label'=>'Signup Bonus Credits','value'=>(int)$totals['bonus'],'kind'=>'money'],
            ['label'=>'Paid Wallet Credits','value'=>(int)$totals['paid'],'kind'=>'money'],
            ['label'=>'API Charges','value'=>$charges,'kind'=>'money'],
        ]:[
            ['label'=>'Current API Consumption','value'=>$consumption,'kind'=>'money','detail'=>'This month: '.$month.' (IST)'],
            ['label'=>'Outstanding Amount','value'=>(int)$c['outstanding'],'kind'=>'money'],
            ['label'=>'Approved Credit Limit','value'=>(int)$c['credit_limit'],'kind'=>'money'],
            ['label'=>'Available Credit','value'=>max(0,(int)$c['credit_limit']-(int)$c['outstanding']),'kind'=>'money'],
            ['label'=>'Payment Terms','value'=>(int)$c['terms_days'],'kind'=>'days'],
        ];
        $statements=$prepaid?[]:$this->store->query("SELECT strftime('%Y-%m',created,'unixepoch','+330 minutes') AS month,COUNT(*) AS requests,SUM(net) AS net,SUM(tax) AS tax,SUM(gross) AS gross FROM billing_usage WHERE customer_id=? AND mode='POSTPAID' AND status='charged' GROUP BY month ORDER BY month DESC",[$id])->fetchAll();
        return ['mode'=>$c['mode'],'cards'=>$cards,'sections'=>['wallet'=>$prepaid,'postpaid'=>!$prepaid],
            'statements'=>$statements,'privacy'=>(new PrivacyConsent($this))->status($id),
            'refundEligible'=>$prepaid?(new WalletRefunds($this))->eligible($id):0,
            'refunds'=>$this->store->query('SELECT id,requested,approved,status,email_received,review_note,payment_reference,created FROM refund_requests WHERE customer_id=? ORDER BY created DESC LIMIT 100',[$id])->fetchAll(),
            'transactions'=>$prepaid?$this->store->query('SELECT kind,amount,balance_after,created FROM customer_ledger WHERE customer_id=? ORDER BY created DESC,rowid DESC LIMIT 500',[$id])->fetchAll():[],
            'recharges'=>$prepaid?$this->store->query('SELECT id,amount,utr,status,reason,created FROM recharge_requests WHERE customer_id=? ORDER BY created DESC LIMIT 100',[$id])->fetchAll():[],
            'usage'=>$this->store->query('SELECT service,mode,net,tax,gross,status,unit,created FROM billing_usage WHERE customer_id=? AND mode=? ORDER BY created DESC,rowid DESC LIMIT 500',[$id,$c['mode']])->fetchAll()];
    }
    public function statement(string $owner,string $month):string
    {
        $c=$this->customer($owner);if($c['mode']!=='POSTPAID')throw new RuntimeException('Billing statements are available for postpaid accounts.');
        if(!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('Choose a valid statement month.');
        $rows=$this->store->query("SELECT service,net,tax,gross,cgst,sgst,igst,unit,created FROM billing_usage WHERE customer_id=? AND mode='POSTPAID' AND status='charged' AND strftime('%Y-%m',created,'unixepoch','+330 minutes')=? ORDER BY created,rowid",[$c['id'],$month])->fetchAll();
        if(!$rows)throw new RuntimeException('No finalized postpaid usage is available for this month.');
        $stream=fopen('php://temp','w+');fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,['Time (IST)','Service','Taxable Value (INR)','GST (INR)','Total (INR)','CGST (INR)','SGST (INR)','IGST (INR)','Billable Unit']);
        foreach($rows as$r){$time=(new \DateTimeImmutable('@'.$r['created']))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:s');$values=[$time,$r['service']];foreach(['net','tax','gross','cgst','sgst','igst']as$key)$values[]=number_format((int)$r[$key]/100,2,'.','');$values[]=$r['unit'];fputcsv($stream,array_map(static fn($v)=>preg_match('/^[=+@\t\r]/',(string)$v)?"'".$v:$v,$values));}
        rewind($stream);$bytes=stream_get_contents($stream);fclose($stream);return $bytes;
    }
    private function otpVerifier(string $otp):string{return hash_hmac('sha256',$otp,(string)env('GST_ENCRYPTION_KEY',''));}
    public function notifyAccount(string $id,string $message):void
    {
        $c=$this->store->query('SELECT name,email FROM customers WHERE id=?',[$id])->fetch();if($c)$this->mail($id,$c['email'],$c['name'],'account-notification','Primeserve account updated',$message);
    }
    public function key(string $owner):string
    {
        $c=$this->customer($owner);
        if($c['mode']==='PREPAID'){$sealed=$this->setting('prepaid_key');$key=$sealed!==''?$this->store->open($sealed)['key']:'';}
        else $key=$c['api_secret']?$this->store->open($c['api_secret'])['key']:'';
        if($key==='')throw new RuntimeException($c['mode']==='PREPAID'?'Prepaid API access has not been configured. Contact support.':'Enter your current Primeserve API key for this login session.');return $key;
    }
    public function updateKey(string $owner,string $key):void
    {
        $c=$this->customer($owner);if($c['mode']!=='POSTPAID')throw new RuntimeException('Prepaid accounts use the server API key.');self::validateKey($key);
        $this->store->query('UPDATE customer_sessions SET api_secret=? WHERE owner=?',[$this->store->seal(['key'=>$key]),$owner]);$this->audit($c['id'],'session-key-updated',$c['id']);
    }
    public static function validateKey(string $key):void{if(strlen($key)<3||strlen($key)>512||preg_match('/[\r\n\x00]/',$key))throw new RuntimeException('Enter a valid API key.');}
    public function setAccess(string $id,bool $enabled,string $reason,string $actor):?bool
    {
        $reason=trim($reason);if(mb_strlen($reason)<5||mb_strlen($reason)>300||preg_match('/[\x00-\x1F]/',$reason))throw new RuntimeException('Enter an access change reason (5–300 characters).');
        $changed=$this->atomic(function()use($id,$enabled,$reason,$actor){
            $c=$this->store->query('SELECT enabled FROM customers WHERE id=?',[$id])->fetch();if(!$c)throw new RuntimeException('Customer not found.');
            if((int)$c['enabled']===($enabled?1:0))return false;
            $this->store->query('UPDATE customers SET enabled=?,access_reason=? WHERE id=?',[$enabled?1:0,$reason,$id]);
            if(!$enabled)foreach($this->store->query('SELECT owner FROM customer_sessions WHERE customer_id=?',[$id])->fetchAll() as $session)$this->logout($session['owner']);
            $this->audit($actor,$enabled?'customer-unblocked':'customer-blocked',$id,['reason'=>$reason]);return true;
        });
        if(!$changed)return null;
        $c=$this->store->query('SELECT name,email FROM customers WHERE id=?',[$id])->fetch();
        $status=$enabled?'UNBLOCKED / ACTIVE':'BLOCKED';
        $body='Account status: '.$status."\n\nAdmin reason: ".$reason."\n\n".($enabled?'Your account is enabled again. Please log in to continue.':'You cannot log in or use the portal while blocked. Existing login sessions have been ended.')."\n\nFor assistance, contact support@primeserve.in.";
        return $this->mail($id,$c['email'],$c['name'],$enabled?'account-unblocked':'account-blocked','Primeserve account status: '.$status,$body);
    }
    public function logout(string $owner):void
    {
        $this->store->query('DELETE FROM customer_sessions WHERE owner=?',[$owner]);$this->store->query('DELETE FROM connections WHERE owner=?',[$owner]);$this->store->query('DELETE FROM audit_key_owners WHERE owner=?',[$owner]);
    }
    private function credit(string $id,int $amount,string $kind,string $reference):void
    {
        if($this->store->query('SELECT id FROM customer_ledger WHERE reference=?',[$reference])->fetchColumn())return;
        $this->store->query('UPDATE customers SET balance=balance+? WHERE id=?',[$amount,$id]);$balance=(int)$this->store->query('SELECT balance FROM customers WHERE id=?',[$id])->fetchColumn();
        $this->store->query('INSERT INTO customer_ledger VALUES(?,?,?,?,?,?,?)',[self::id(),$id,$kind,$amount,$balance,$reference,time()]);
    }
    public static function paise(string $amount):int
    {
        if(!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D',$amount,$m))throw new RuntimeException('Enter a valid amount with at most two decimal places.');return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public function standardPrice(string $service,int $net,string $actor):int
    {
        if(!in_array($service,self::SERVICES,true)||$net<0||$net>999999999)throw new RuntimeException('Choose a valid service and price.');
        return $this->atomic(function()use($service,$net,$actor){
            $old=(int)$this->setting('price_'.$service);
            $this->store->query('UPDATE customer_settings SET value=? WHERE name=?',[(string)$net,'price_'.$service]);
            $count=(int)$this->store->query("SELECT COUNT(*) FROM customer_prices WHERE service=? AND pricing_mode='standard' AND net!=?",[$service,$net])->fetchColumn();
            $this->store->query("UPDATE customer_prices SET net=? WHERE service=? AND pricing_mode='standard'",[$net,$service]);
            $this->audit($actor,'standard-price-changed',$service,['before'=>$old,'after'=>$net,'clients_updated'=>$count]);return $count;
        });
    }
    public function clientPrice(string $id,string $service,string $mode,int $net,bool $enabled,string $actor):void
    {
        if(!in_array($service,self::SERVICES,true)||!in_array($mode,['standard','special'],true)||$net<0||$net>999999999)throw new RuntimeException('Choose a valid service, pricing mode and price.');
        $this->atomic(function()use($id,$service,$mode,$net,$enabled,$actor){
            $old=$this->store->query('SELECT * FROM customer_prices WHERE customer_id=? AND service=?',[$id,$service])->fetch();if(!$old)throw new RuntimeException('Customer price not found.');
            if($mode==='standard')$net=(int)$this->setting('price_'.$service);
            $this->store->query('UPDATE customer_prices SET net=?,enabled=?,pricing_mode=? WHERE customer_id=? AND service=?',[$net,$enabled?1:0,$mode,$id,$service]);
            $this->audit($actor,'client-price-changed',$id,['service'=>$service,'before'=>$old,'after_net'=>$net,'pricing_mode'=>$mode,'enabled'=>$enabled]);
        });
        $this->notifyAccount($id,'Your API pricing or service access has changed. Please log in to review current prices before your next request.');
    }
    public function quote(string $owner,string $service,int $count=1):array
    {
        $c=$this->customer($owner);if(!in_array($service,self::SERVICES,true)||$count<1||$count>96)throw new RuntimeException('Invalid service or quantity.');
        $p=$this->store->query('SELECT * FROM customer_prices WHERE customer_id=? AND service=?',[$c['id'],$service])->fetch();if(!$p||!$p['enabled'])throw new RuntimeException('This service is not enabled for your account.');
        $net=(int)$p['net'];$tax=intdiv($net*18+50,100);return ['service'=>$service,'count'=>$count,'net'=>$net*$count,'tax'=>$tax*$count,'gross'=>($net+$tax)*$count,'unitNet'=>$net,'unitTax'=>$tax,'mode'=>$c['mode'],'unit'=>$service==='returns'?'One accepted return initiation for one return type and month':'One successful '.$service.' retrieval','taxNote'=>'GST 18%; wallet top-ups are not charged GST again.'];
    }
    public function confirm(string $owner,string $service,int $count,string $confirmation):void
    {
        if((string)env('GST_BILLING_VERIFIED','0')!=='1')throw new RuntimeException('Paid API access is disabled pending billing verification.');
        try{$token=$this->store->open($confirmation);}catch(Throwable){throw new RuntimeException('Review and confirm the current price before making this request.');}
        $q=$this->quote($owner,$service,$count);
        if(($token['owner']??null)!==$owner||($token['service']??null)!==$service||($token['count']??null)!==$count||($token['gross']??null)!==$q['gross']||($token['expires']??0)<time())throw new RuntimeException('The price confirmation expired or changed. Review the price and try again.');
    }
    /** Caller may already own a BEGIN IMMEDIATE transaction (batch creation). */
    public function reserve(string $owner,string $service,string $requestKey,bool $inTransaction=false,array $context=[]):array
    {
        $fn=function()use($owner,$service,$requestKey,$context){
            $q=$this->quote($owner,$service);$c=$this->customer($owner);
            $old=$this->store->query('SELECT * FROM billing_usage WHERE customer_id=? AND request_key=?',[$c['id'],$requestKey])->fetch();if($old)return $old;
            if($c['mode']==='PREPAID'){
                $update=$this->store->query('UPDATE customers SET balance=balance-? WHERE id=? AND balance>=?',[$q['gross'],$c['id'],$q['gross']]);if($update->rowCount()!==1)throw new RuntimeException('Insufficient wallet balance. Add money before requesting this service.');
                $balance=(int)$c['balance']-$q['gross'];$this->store->query('INSERT INTO customer_ledger VALUES(?,?,?,?,?,?,?)',[self::id(),$c['id'],'api-reservation',-$q['gross'],$balance,'reserve:'.$c['id'].':'.$requestKey,time()]);
            }else{
                $update=$this->store->query('UPDATE customers SET outstanding=outstanding+? WHERE id=? AND outstanding+?<=credit_limit',[$q['gross'],$c['id'],$q['gross']]);if($update->rowCount()!==1)throw new RuntimeException('Your approved postpaid credit limit is insufficient. Contact support.');
            }
            $id=self::id();$supplier=$this->setting('supplier_state');$destination=$c['state_code'];$kind=$supplier===''||$destination===''?'unallocated':($supplier===$destination?'CGST/SGST':'IGST');$cgst=$kind==='CGST/SGST'?intdiv($q['unitTax'],2):0;$sgst=$kind==='CGST/SGST'?$q['unitTax']-$cgst:0;$igst=$kind==='IGST'?$q['unitTax']:0;
            $this->store->query("INSERT INTO billing_usage(id,customer_id,owner,request_key,service,mode,net,tax,gross,status,unit,created,updated,supplier_state,customer_state,tax_kind,cgst,sgst,igst) VALUES(?,?,?,?,?,?,?,?,?,'reserved',?,?,?,?,?,?,?,?,?)",[$id,$c['id'],$owner,$requestKey,$service,$c['mode'],$q['unitNet'],$q['unitTax'],$q['gross'],$q['unit'],time(),time(),$supplier,$destination,$kind,$cgst,$sgst,$igst]);
            $this->store->query('UPDATE billing_usage SET gstin=?,return_type=?,period=? WHERE id=?',[(string)($context['gstin']??''),(string)($context['return_type']??''),(string)($context['period']??''),$id]);
            return $this->store->query('SELECT * FROM billing_usage WHERE id=?',[$id])->fetch();
        };return $inTransaction?$fn():$this->atomic($fn);
    }
    public function adminCredit(string $id,int $amount,string $reason,string $requestId,string $actor):bool
    {
        $reason=trim($reason);
        if($amount<1||$amount>100000000||mb_strlen($reason)<5||mb_strlen($reason)>300||preg_match('/[\x00-\x1F]/',$reason)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$requestId))throw new RuntimeException('Choose a customer, enter a positive amount up to ₹10,00,000 and a reason (5–300 characters).');
        $reference='admin-credit:'.$requestId;
        $created=$this->atomic(function()use($id,$amount,$reason,$reference,$actor){
            $old=$this->store->query('SELECT customer_id,amount FROM customer_ledger WHERE reference=?',[$reference])->fetch();
            if($old){if($old['customer_id']!==$id||(int)$old['amount']!==$amount)throw new RuntimeException('This credit reference was already used for a different request.');return false;}
            $customer=$this->store->query('SELECT mode,balance FROM customers WHERE id=?',[$id])->fetch();
            if(!$customer)throw new RuntimeException('Customer not found.');
            if($customer['mode']!=='PREPAID')throw new RuntimeException('Wallet credits apply to prepaid customers. Manage postpaid credit limits under Customers.');
            $balance=(int)$customer['balance']+$amount;
            $this->store->query('UPDATE customers SET balance=? WHERE id=?',[$balance,$id]);
            $this->store->query('INSERT INTO customer_ledger VALUES(?,?,?,?,?,?,?)',[self::id(),$id,'admin-credit',$amount,$balance,$reference,time()]);
            $this->audit($actor,'admin-wallet-credit',$id,['amount'=>$amount,'reason'=>$reason,'reference'=>$reference,'balance_after'=>$balance]);
            return true;
        });
        if($created)$this->notifyAccount($id,'Primeserve Admin credited ₹'.number_format($amount/100,2).' to your wallet. Reason: '.$reason.'. You can review it in Wallet Transaction History.');
        return $created;
    }
    public function adminDebit(string $id,int $amount,string $reason,string $requestId,string $actor):bool
    {
        $reason=trim($reason);
        if($amount<1||$amount>100000000||mb_strlen($reason)<5||mb_strlen($reason)>300||preg_match('/[\x00-\x1F]/',$reason)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$requestId))throw new RuntimeException('Enter a positive correction amount up to ₹10,00,000 and a reason (5–300 characters).');
        $reference='admin-debit:'.$requestId;
        $created=$this->atomic(function()use($id,$amount,$reason,$reference,$actor){
            $old=$this->store->query('SELECT customer_id,amount FROM customer_ledger WHERE reference=?',[$reference])->fetch();
            if($old){if($old['customer_id']!==$id||(int)$old['amount']!==-$amount)throw new RuntimeException('This correction reference was already used for a different request.');return false;}
            $c=$this->store->query('SELECT mode,balance FROM customers WHERE id=?',[$id])->fetch();
            if(!$c)throw new RuntimeException('Customer not found.');
            if($c['mode']!=='PREPAID')throw new RuntimeException('Wallet corrections apply to prepaid customers only.');
            if($amount>(int)$c['balance'])throw new RuntimeException('Correction exceeds the available wallet balance. Reserved API and refund funds cannot be deducted.');
            $balance=(int)$c['balance']-$amount;
            $this->store->query('UPDATE customers SET balance=? WHERE id=?',[$balance,$id]);
            $this->store->query('INSERT INTO customer_ledger VALUES(?,?,?,?,?,?,?)',[self::id(),$id,'admin-debit',-$amount,$balance,$reference,time()]);
            $this->audit($actor,'admin-wallet-debit',$id,['amount'=>$amount,'reason'=>$reason,'reference'=>$reference,'balance_after'=>$balance]);return true;
        });
        if($created)$this->notifyAccount($id,'Primeserve Admin reduced your wallet by ₹'.number_format($amount/100,2).' as a balance correction. Reason: '.$reason.'. Review Wallet Transaction History for details.');
        return $created;
    }
    public function settle(string $requestKey,string $outcome):void
    {
        if(!in_array($outcome,['charged','released','review'],true))throw new RuntimeException('Invalid billing outcome.');
        $this->atomic(function()use($requestKey,$outcome){
            $r=$this->store->query("SELECT * FROM billing_usage WHERE request_key=? AND status IN ('reserved','review')",[$requestKey])->fetch();if(!$r)return;
            if($outcome==='released'){
                if($r['mode']==='PREPAID')$this->credit($r['customer_id'],(int)$r['gross'],'reservation-reversal','release:'.$r['id']);
                else $this->store->query('UPDATE customers SET outstanding=outstanding-? WHERE id=?',[$r['gross'],$r['customer_id']]);
            }
            $this->store->query('UPDATE billing_usage SET status=?,updated=? WHERE id=?',[$outcome,time(),$r['id']]);
        });
    }
    public function recharge(string $owner,string $amount,string $utr):string
    {
        if($this->customer($owner)['mode']!=='PREPAID')throw new RuntimeException('Add Money is available only for prepaid accounts.');
        if($this->setting('upi_id')==='')throw new RuntimeException('Payment details are not configured. Contact support before making a payment.');
        $c=$this->customer($owner);$paise=self::paise($amount);$utr=strtoupper(trim($utr));if($paise<100||$paise>10000000||!preg_match('/^[A-Z0-9-]{6,64}$/D',$utr))throw new RuntimeException('Enter an amount from ₹1 to ₹100,000 and a valid UTR/reference.');
        $this->limit('recharge:'.$c['id'],10,3600);$id=self::id();
        $this->atomic(function()use($id,$c,$paise,$utr){if($this->store->query('SELECT id FROM recharge_requests WHERE utr=?',[$utr])->fetchColumn())throw new RuntimeException('This payment reference has already been submitted.');$this->store->query('INSERT INTO recharge_requests(id,customer_id,amount,utr,created,payee_upi,payee_name) VALUES(?,?,?,?,?,?,?)',[$id,$c['id'],$paise,$utr,time(),$this->setting('upi_id'),$this->setting('upi_name')]);$this->queuePaymentNotice($id,'submitted');});
        $this->deliverPaymentNotice($id,'submitted');
        $this->mail($c['id'],$c['email'],$c['name'],'recharge-pending','Recharge request received','Your request is pending independent bank verification. Your wallet has not yet been credited.');return $id;
    }
    public function approveRecharge(string $id,bool $approve,string $reason,string $actor):void
    {
        $r=$this->atomic(function()use($id,$approve,$reason,$actor){$r=$this->store->query('SELECT r.*,c.email,c.name FROM recharge_requests r JOIN customers c ON c.id=r.customer_id WHERE r.id=?',[$id])->fetch();if(!$r)throw new RuntimeException('Recharge request not found.');if($r['status']!=='pending')return null;
            if($approve)$this->credit($r['customer_id'],(int)$r['amount'],'paid-recharge','recharge:'.$id);
            $this->store->query('UPDATE recharge_requests SET status=?,reason=?,reviewed=? WHERE id=?',[$approve?'approved':'rejected',mb_substr($reason,0,300),time(),$id]);if($approve)$this->queuePaymentNotice($id,'received');$this->audit($actor,'recharge-'.($approve?'approved':'rejected'),$id);return $r;});
        if($r&&$approve)$this->deliverPaymentNotice($id,'received');
        if($r)$this->mail($r['customer_id'],$r['email'],$r['name'],'recharge-result','Primeserve recharge '.($approve?'approved':'rejected'),$approve?'Your verified payment has been credited to your wallet.':'Your recharge was rejected. '.mb_substr($reason,0,300));
    }
    private function queuePaymentNotice(string $id,string $kind):void
    {
        $this->store->query('INSERT OR IGNORE INTO payment_notifications(id,recharge_id,kind,created,updated) VALUES(?,?,?,?,?)',[self::id(),$id,$kind,time(),time()]);
    }
    public function deliverQueuedPaymentNotice():void
    {
        // A crash after submission has an uncertain delivery outcome; do not send duplicates automatically.
        $this->store->query("UPDATE payment_notifications SET status='review',updated=? WHERE status='sending' AND updated<?",[time(),time()-300]);
        $row=$this->store->query("SELECT recharge_id,kind FROM payment_notifications WHERE status='queued' ORDER BY created,rowid LIMIT 1")->fetch();
        if($row)$this->deliverPaymentNotice($row['recharge_id'],$row['kind']);
    }
    private function deliverPaymentNotice(string $id,string $kind):void
    {
        $notice=$this->atomic(function()use($id,$kind){$claimed=$this->store->query("UPDATE payment_notifications SET status='sending',updated=? WHERE recharge_id=? AND kind=? AND status='queued'",[time(),$id,$kind]);if($claimed->rowCount()!==1)return null;return $this->store->query('SELECT r.*,c.name,c.email,c.company FROM recharge_requests r JOIN customers c ON c.id=r.customer_id WHERE r.id=?',[$id])->fetch();});
        if(!$notice)return;
        $received=$kind==='received';$amount=number_format((int)$notice['amount']/100,2,'.','');$time=(new \DateTimeImmutable('@'.($received?$notice['reviewed']:$notice['created'])))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('d M Y, h:i a').' IST';
        $subject=$received?'Payment received and wallet credited':'Payment reference submitted - bank verification required';
        $body=($received?'Primeserve Admin has independently verified this payment.':'A customer submitted this payment reference. This submission notice does NOT confirm bank receipt; verify the bank record and refer to the separate approved-payment notice.')."\n\nCustomer name: ".$notice['name']."\nCompany name: ".$notice['company']."\nCustomer email: ".$notice['email']."\nCustomer ID: ".$notice['customer_id']."\nRecharge ID: ".$id."\nUTR / reference: ".$notice['utr']."\nUPI destination: ".($notice['payee_upi']?:'Not recorded for this legacy request')."\nTime: ".$time."\n\n".($received?'Amount received':'Amount reported').': ₹'.$amount."\nGST collected on this wallet top-up: ₹0.00\n".($received?'Wallet credited':'Wallet credit pending verification').': ₹'.$amount."\n\nThis is a wallet advance. Under the portal's current billing policy, taxable value and GST @ 18% are recorded on API consumption, not charged again on the top-up. This payment notice is not a GST tax invoice.";
        $recipients=array_values(array_filter(array_unique([$this->setting('payment_notification_email'),'finance@primeserve.in','primeserve45@gmail.com']),static fn($email)=>strcasecmp(trim($email),'info@primeserve.in')!==0));$sent=true;
        foreach($recipients as $to)if(filter_var($to,FILTER_VALIDATE_EMAIL)){$accepted=$this->mail($notice['customer_id'],$to,'Primeserve Accounts','payment-admin-'.$kind,$subject,$body);$sent=$accepted&&$sent;}
        $this->store->query('UPDATE payment_notifications SET status=?,updated=? WHERE recharge_id=? AND kind=?',[$sent?'accepted':'failed',time(),$id,$kind]);
    }
    public function forgot(string $email,string $ip):void
    {
        $this->limit('forgot-ip:'.$ip,5,3600);$email=self::email($email);$this->limit('forgot-email:'.$email,3,3600);$c=$this->store->query('SELECT * FROM customers WHERE email=?',[$email])->fetch();if(!$c)return;
        $token=bin2hex(random_bytes(32));$id=self::id();$this->store->query('DELETE FROM password_resets WHERE customer_id=?',[$c['id']]);$this->store->query('INSERT INTO password_resets VALUES(?,?,?,?,0)',[$id,$c['id'],hash('sha256',$token),time()+900]);
        $url=rtrim((string)env('GST_PUBLIC_URL','http://127.0.0.1:8086'),'/').'/reset-password#'.$id.'.'.$token;
        $this->mail($c['id'],$c['email'],$c['name'],'password-reset','Reset your Primeserve password','Use this link within 15 minutes: '.$url);
    }
    public function billingDetails(string $owner,string $state,string $gstin):void
    {
        $c=$this->customer($owner);if(!preg_match('/^(0[1-9]|[12][0-9]|3[0-8])$/D',$state))throw new RuntimeException('Enter your billing state code.');
        $gstin=strtoupper(trim($gstin));if($gstin!==''&&(!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/D',$gstin)||substr($gstin,0,2)!==$state))throw new RuntimeException('Enter a valid billing GSTIN matching your state.');
        $this->atomic(function()use($c,$state,$gstin){$this->store->query('UPDATE customers SET state_code=?,billing_gstin=? WHERE id=?',[$state,$gstin,$c['id']]);$this->audit($c['id'],'billing-details-updated',$c['id']);});
    }
    public function adminBillingDetails(string $id,string $state,string $gstin,bool $delete=false):void
    {
        $gstin=strtoupper(trim($gstin));
        if($delete){$state='';$gstin='';}
        elseif(!preg_match('/^(0[1-9]|[12][0-9]|3[0-8])$/D',$state)||($gstin!==''&&(!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/D',$gstin)||substr($gstin,0,2)!==$state)))throw new RuntimeException('Enter a valid billing state and matching GSTIN.');
        $this->atomic(function()use($id,$state,$gstin,$delete){if(!$this->store->query('SELECT id FROM customers WHERE id=?',[$id])->fetch())throw new RuntimeException('Customer not found.');$this->store->query('UPDATE customers SET state_code=?,billing_gstin=? WHERE id=?',[$state,$gstin,$id]);$this->audit('admin',$delete?'billing-details-deleted':'billing-details-edited',$id);});
    }
    public function historyExport(string $owner,string $kind,string $from,string $to):string
    {
        $c=$this->customer($owner);$zone=new \DateTimeZone('Asia/Kolkata');
        foreach([$from,$to] as $date){$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new RuntimeException('Choose valid From and To dates.');}
        if($from>$to)throw new RuntimeException('From date must be before or equal to To date.');
        $start=(new \DateTimeImmutable($from,$zone))->getTimestamp();$end=(new \DateTimeImmutable($to,$zone))->modify('+1 day')->getTimestamp();
        if($kind==='wallet'){
            if($c['mode']!=='PREPAID')throw new RuntimeException('Wallet exports are available for prepaid accounts.');
            $rows=$this->store->query('SELECT kind,amount,balance_after,created FROM customer_ledger WHERE customer_id=? AND created>=? AND created<? ORDER BY created,rowid',[$c['id'],$start,$end])->fetchAll();
        }elseif($kind==='usage')$rows=$this->store->query('SELECT service,mode,net,tax,gross,status,unit,created FROM billing_usage WHERE customer_id=? AND mode=? AND created>=? AND created<? ORDER BY created,rowid',[$c['id'],$c['mode'],$start,$end])->fetchAll();
        else throw new RuntimeException('Choose wallet or API charges.');
        foreach($rows as &$row){foreach($row as $key=>&$value){if(in_array($key,['amount','balance_after','net','tax','gross'],true))$value=number_format((int)$value/100,2,'.','');elseif($key==='created')$value=(new \DateTimeImmutable('@'.$value))->setTimezone($zone)->format('Y-m-d H:i:s').' IST';}unset($value);}unset($row);
        return AccountExcel::build($rows,$this->store);
    }
    public function headerBalance(string $owner):array
    {
        $c=$this->customer($owner);$prepaid=$c['mode']==='PREPAID';
        return ['label'=>$prepaid?'Available Wallet Balance':'Available Credit','amount'=>$prepaid?(int)$c['balance']:max(0,(int)$c['credit_limit']-(int)$c['outstanding']),'mode'=>$c['mode'],'link'=>'/account'];
    }
    public function deliverLowBalanceReminder():void
    {
        $threshold=max(0,(int)env('GST_LOW_BALANCE_PAISE',1000));
        $customer=$this->atomic(function()use($threshold){
            // Held requests are not finalized consumption and must not trigger reminders.
            $sql="SELECT c.*,c.balance+COALESCE((SELECT SUM(gross) FROM billing_usage u WHERE u.customer_id=c.id AND u.mode='PREPAID' AND u.status IN ('reserved','review')),0) AS settled_balance FROM customers c WHERE c.mode='PREPAID' AND c.enabled=1";
            $customers=$this->store->query($sql)->fetchAll();
            foreach($customers as $c){if((int)$c['settled_balance']>$threshold)$this->store->query('DELETE FROM customer_balance_reminders WHERE customer_id=?',[$c['id']]);}
            foreach($customers as $c){if((int)$c['settled_balance']>$threshold)continue;$claimed=$this->store->query("INSERT OR IGNORE INTO customer_balance_reminders VALUES(?,'sending',?)",[$c['id'],time()]);if($claimed->rowCount()===1)return $c;}
            return null;
        });
        if(!$customer)return;
        $balance=number_format((int)$customer['settled_balance']/100,2);$url=rtrim((string)env('GST_PUBLIC_URL','http://127.0.0.1:8086'),'/').'/account';
        $sent=$this->mail($customer['id'],$customer['email'],$customer['name'],'low-balance','Please add money to your Primeserve wallet','Your wallet balance is ₹'.$balance.'. Please add money to continue using GST services. Open My Account: '.$url.'. Wallet credits do not expire. Payment references require bank verification before wallet credit.');
        $this->store->query('UPDATE customer_balance_reminders SET status=?,updated=? WHERE customer_id=?',[$sent?'accepted':'failed',time(),$customer['id']]);
    }
    public function reset(string $token,string $password):void
    {
        self::password($password);$parts=explode('.',$token,2);$id=$parts[0];$secret=$parts[1]??'';
        $c=$this->atomic(function()use($id,$secret,$password){$r=$this->store->query('SELECT * FROM password_resets WHERE id=? AND used=0 AND expires>?',[$id,time()])->fetch();if(!$r||!hash_equals($r['verifier'],hash('sha256',$secret)))throw new RuntimeException('The password reset link is invalid or expired.');
            $this->store->query('UPDATE customers SET password_hash=? WHERE id=?',[self::passwordHash($password),$r['customer_id']]);$this->store->query('UPDATE password_resets SET used=1 WHERE id=?',[$id]);$owners=$this->store->query('SELECT owner FROM customer_sessions WHERE customer_id=?',[$r['customer_id']])->fetchAll();foreach($owners as $owner)$this->logout($owner['owner']);return $this->store->query('SELECT * FROM customers WHERE id=?',[$r['customer_id']])->fetch();});
        $this->mail($c['id'],$c['email'],$c['name'],'password-reset-confirmation','Primeserve password changed','Your password was changed. All existing login sessions have been signed out. Contact support if you did not request this.');
    }
}
