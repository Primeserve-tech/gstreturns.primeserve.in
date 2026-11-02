<?php
namespace App\Controllers;
use App\Libraries\CustomerAccounts;
use App\Libraries\ReturnStore;
use App\Libraries\ClientIp;
use RuntimeException;
use Throwable;

class CustomerPortal extends BaseController
{
    private function headers():void{$this->response->setHeader('Cache-Control','no-store, private')->setHeader('X-Frame-Options','DENY')->setHeader('Referrer-Policy','no-referrer')->setHeader('X-Content-Type-Options','nosniff');}
    private function csrf():string{if(!session()->has('customer_csrf'))session()->set('customer_csrf',bin2hex(random_bytes(32)));return session()->get('customer_csrf');}
    private function owner():string{return (string)session()->get('customer_owner');}
    private function ip():string{return ClientIp::resolve((string)$this->request->getServer('REMOTE_ADDR'),$this->request->getHeaderLine('X-Forwarded-For'));}
    public function page(string $screen='login')
    {
        $this->headers();if(!CustomerAccounts::enabled())return $this->response->setStatusCode(404)->setBody('Customer accounts are not enabled.');
        $dashboard=null;$customer=null;
        if($screen==='account'){try{$accounts=new CustomerAccounts(new ReturnStore());$customer=$accounts->publicCustomer($accounts->customer($this->owner()));$dashboard=$accounts->dashboard($this->owner());}catch(RuntimeException){return redirect()->to('/login');}}
        return view('customer_portal',['csrf'=>$this->csrf(),'screen'=>$screen,'customer'=>$customer,'dashboard'=>$dashboard,'emailReady'=>(new \App\Libraries\ZohoMailer())->configured(),'registrationComplete'=>$screen==='login'&&session()->getFlashdata('registration_complete')===true]);
    }
    public function action(string $action)
    {
        $this->headers();
        try{
            if(!CustomerAccounts::enabled())throw new RuntimeException('Customer accounts are not enabled.');
            if(!hash_equals($this->csrf(),$this->request->getHeaderLine('X-CSRF-Token')))throw new RuntimeException('Refresh this page and try again.');
            $a=new CustomerAccounts(new ReturnStore());$d=$this->request->getJSON(true);if(!is_array($d))throw new RuntimeException('Invalid request.');$result=[];
            switch($action){
                case 'register':$result=['attempt'=>$a->register($d,$this->ip()),'message'=>'Check your email for a verification code.'];break;
                case 'verify':$result=$a->verify((string)($d['attempt']??''),(string)($d['otp']??''),$this->ip());session()->setFlashdata('registration_complete',true);break;
                case 'login':
                    if($this->owner()!=='')$a->logout($this->owner());
                    $owner=CustomerAccounts::id();$c=$a->login((string)($d['email']??''),(string)($d['password']??''),$owner,$this->ip());
                    session()->regenerate(true);session()->set(['customer_owner'=>$owner,'return_owner'=>$owner,'return_csrf'=>bin2hex(random_bytes(32)),'customer_csrf'=>bin2hex(random_bytes(32))]);$result=['redirect'=>'/'];break;
                case 'forgot':$a->forgot((string)($d['email']??''),$this->ip());$result=['message'=>'If an account exists, password reset instructions will be sent.'];break;
                case 'reset':$a->limit('reset-ip:'.$this->ip(),10,900);$a->reset((string)($d['token']??''),(string)($d['password']??''));$result=['redirect'=>'/login'];break;
                case 'state':
                    $c=$a->customer($this->owner());$id=$c['id'];$prices=[];foreach(CustomerAccounts::SERVICES as $s){try{$prices[]=$a->quote($this->owner(),$s);}catch(RuntimeException){}}
                    $dashboard=$a->dashboard($this->owner());$prepaid=$dashboard['mode']==='PREPAID';
                    $upi=$prepaid?['id'=>$a->setting('upi_id'),'name'=>$a->setting('upi_name')]:null;
                    if($prepaid&&$upi['id']!=='')$upi=\App\Libraries\UpiPayment::details($upi['id'],$upi['name']?:'Primeserve Global Solution Private Limited');
                    $result=['customer'=>$a->publicCustomer($c),'dashboard'=>$dashboard,'keyRequired'=>!$prepaid&&!$c['api_secret'],'prices'=>$prices,'upi'=>$upi];break;
                case 'balance':$result=$a->headerBalance($this->owner());break;
                case 'history-export':
                    $bytes=$a->historyExport($this->owner(),(string)($d['kind']??''),(string)($d['from']??''),(string)($d['to']??''));
                    return $this->response->setHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setHeader('Content-Disposition','attachment; filename="Primeserve_Account_Report.xlsx"')->setBody($bytes);
                case 'statement':
                    $month=(string)($d['month']??'');$csv=$a->statement($this->owner(),$month);
                    return $this->response->setHeader('Content-Type','text/csv; charset=utf-8')->setHeader('Content-Disposition','attachment; filename="Primeserve_Statement_'.$month.'.csv"')->setBody($csv);
                case 'quote':
                    $q=$a->quote($this->owner(),(string)($d['service']??''),(int)($d['count']??1));$expires=time()+300;$payload=['owner'=>$this->owner(),'service'=>$q['service'],'count'=>$q['count'],'gross'=>$q['gross'],'expires'=>$expires];$result=$q+['confirmation'=>$a->store->seal($payload)];break;
                case 'key':$a->updateKey($this->owner(),trim((string)($d['key']??'')));$result=['message'=>'API key updated for this login session.'];break;
                case 'billing-details':$a->billingDetails($this->owner(),(string)($d['state']??''),(string)($d['gstin']??''));$result=['message'=>'Billing details updated for future transactions. Historical charges are unchanged.'];break;
                case 'recharge':$result=['id'=>$a->recharge($this->owner(),(string)($d['amount']??''),(string)($d['utr']??'')),'message'=>'Payment submitted for independent verification. No wallet credit has been added yet.'];break;
                case 'logout':$a->logout($this->owner());session()->remove(['customer_owner','return_owner','return_csrf','customer_csrf']);session()->regenerate(true);$result=['redirect'=>'/login'];break;
                default:throw new RuntimeException('Unknown account action.');
            }
            return $this->response->setJSON($result);
        }catch(Throwable $e){return $this->response->setStatusCode(422)->setJSON(['error'=>get_class($e)===RuntimeException::class?$e->getMessage():'Unable to complete the account request.']);}
    }
}
