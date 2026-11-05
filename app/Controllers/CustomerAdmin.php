<?php
namespace App\Controllers;
use App\Libraries\CustomerAccounts;
use App\Libraries\ReturnStore;
use App\Libraries\ZohoMailer;
use RuntimeException;
use Throwable;

class CustomerAdmin extends ReturnAdmin
{
    public function index()
    {
        $this->headers();if(!$this->authenticated())return redirect()->to('/admin');
        return view('customer_admin',['csrf'=>$this->token()]);
    }
    public function action(string $action)
    {
        $this->headers();if(!$this->authenticated())return $this->response->setStatusCode(401)->setJSON(['error'=>'Admin sign-in required.']);
        try{
            $this->adminPost();$s=new ReturnStore();$a=new CustomerAccounts($s);$d=$this->request->getJSON(true);if(!is_array($d))throw new RuntimeException('Invalid request.');$actor='admin';$result=['message'=>'Saved.'];
            switch($action){
                case 'access':
                    if(!in_array($d['operation']??'',['block','unblock'],true))throw new RuntimeException('Choose Block or Unblock.');
                    $enabled=$d['operation']==='unblock';$sent=$a->setAccess((string)($d['id']??''),$enabled,(string)($d['reason']??''),$actor);$result=['message'=>$enabled?'Customer unblocked. They can log in again.':'Customer blocked. All customer login sessions have been revoked.'];$result['message'].=$sent===true?' Status email accepted for sending.':($sent===false?' Status changed, but the email could not be sent. Check Email Delivery Acceptance Status.':' Account already has this status; no duplicate email sent.');break;
                case 'billing-details':
                    $a->adminBillingDetails((string)($d['id']??''),(string)($d['state']??''),(string)($d['gstin']??''),($d['operation']??'save')==='delete');break;
                case 'state':
                    $result=['openRefunds'=>(new \App\Libraries\WalletRefunds($a))->adminRows(true),'pendingRecharges'=>$s->query("SELECT * FROM recharge_requests WHERE status='pending' ORDER BY created")->fetchAll(),'heldUsage'=>$s->query("SELECT * FROM billing_usage WHERE status IN ('reserved','review') ORDER BY created")->fetchAll(),'refunds'=>(new \App\Libraries\WalletRefunds($a))->adminRows(),'customers'=>$s->query('SELECT id,name,email,mobile,company,mode,balance,outstanding,credit_limit,terms_days,enabled,state_code,billing_gstin,created FROM customers ORDER BY created DESC')->fetchAll(),'prices'=>$s->query('SELECT p.*,c.name,c.email,c.company FROM customer_prices p JOIN customers c ON c.id=p.customer_id')->fetchAll(),'settings'=>$s->query("SELECT name,value FROM customer_settings WHERE name!='prepaid_key'")->fetchAll(),'prepaidKeyConfigured'=>$a->setting('prepaid_key')!=='','emailConfigured'=>(new ZohoMailer())->configured(),'billingVerified'=>(string)env('GST_BILLING_VERIFIED','0')==='1','recharges'=>$s->query('SELECT * FROM recharge_requests ORDER BY created DESC LIMIT 50')->fetchAll(),'bonuses'=>$s->query('SELECT * FROM bonus_awards ORDER BY created DESC LIMIT 50')->fetchAll(),'paymentNotifications'=>$s->query('SELECT * FROM payment_notifications ORDER BY created DESC LIMIT 50')->fetchAll(),'bonusTotal'=>(int)$s->query('SELECT COALESCE(SUM(amount),0) FROM bonus_awards')->fetchColumn(),'usage'=>$s->query('SELECT c.name,c.email,c.company,u.gstin,u.return_type,u.period,u.* FROM billing_usage u JOIN customers c ON c.id=u.customer_id ORDER BY u.created DESC LIMIT 50')->fetchAll(),'email'=>$s->query('SELECT * FROM email_events ORDER BY created DESC LIMIT 50')->fetchAll(),'audit'=>$s->query('SELECT * FROM customer_audit ORDER BY created DESC LIMIT 50')->fetchAll()];break;
                case 'settings':
                    $name=(string)($d['name']??'');$value=(string)($d['value']??'');
                    if(!in_array($name,['bonus_active','bonus_paise','price_returns','price_challan-monthly','price_challan-yearly','price_certificate','upi_id','upi_name','supplier_state','payment_notification_email'],true))throw new RuntimeException('Unknown setting.');
                    if($name==='payment_notification_email'){$value=CustomerAccounts::email($value);if($value==='info@primeserve.in')throw new RuntimeException('This address is excluded from portal notifications. Use finance@primeserve.in or primeserve45@gmail.com.');}
                    if($name==='upi_id'&&$value!=='')\App\Libraries\UpiPayment::details($value,$a->setting('upi_name')?:'Primeserve Global Solution Private Limited');
                    if($name==='bonus_active'&&!in_array($value,['0','1'],true))throw new RuntimeException('Choose active or inactive.');
                    if($name==='bonus_paise'||str_starts_with($name,'price_'))$value=(string)CustomerAccounts::paise($value);
                    if($name==='supplier_state'&&$value!==''&&!preg_match('/^(0[1-9]|[12][0-9]|3[0-8])$/D',$value))throw new RuntimeException('Enter a valid Indian state code.');
                    if(strlen($value)>200||preg_match('/[\r\n\x00]/',$value))throw new RuntimeException('Invalid setting value.');
                    if(str_starts_with($name,'price_')){$count=$a->standardPrice(substr($name,6),(int)$value,$actor);$result=['message'=>'Standard price saved. Updated '.$count.' standard-price client rates. Special client rates and historical charges are unchanged.'];break;}
                    $a->atomic(function()use($s,$a,$name,$value,$actor){$old=$a->setting($name);$s->query('UPDATE customer_settings SET value=? WHERE name=?',[$value,$name]);$a->audit($actor,'setting-changed',$name,['before'=>$old,'after'=>$value]);});break;
                case 'prepaid-key':
                    $key=trim((string)($d['key']??''));CustomerAccounts::validateKey($key);
                    $a->atomic(function()use($a,$s,$key,$actor){$s->query("INSERT INTO customer_settings(name,value) VALUES('prepaid_key',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value",[$s->seal(['key'=>$key])]);$a->audit($actor,'prepaid-key-rotated','prepaid');});break;
                case 'customer':
                    $id=(string)($d['id']??'');$mode=(string)($d['mode']??'');$limit=CustomerAccounts::paise((string)($d['creditLimit']??'0'));$terms=filter_var($d['termsDays']??30,FILTER_VALIDATE_INT);$enabled=($d['enabled']??true)===true?1:0;
                    if(!in_array($mode,['PREPAID','POSTPAID'],true)||$terms===false||$terms<1||$terms>365)throw new RuntimeException('Choose valid billing mode and payment terms.');
                    $a->atomic(function()use($a,$s,$id,$mode,$limit,$terms,$enabled,$actor){$old=$s->query('SELECT mode,credit_limit,terms_days,enabled FROM customers WHERE id=?',[$id])->fetch();if(!$old)throw new RuntimeException('Customer not found.');if((int)$old['enabled']!==$enabled)throw new RuntimeException('Use Block / Unblock Customer to change account access with a reason and status email.');if($old['mode']!==$mode&&$s->query("SELECT id FROM refund_requests WHERE customer_id=? AND status IN ('pending','approved')",[$id])->fetchColumn())throw new RuntimeException('Resolve the open refund before changing billing mode.');$s->query('UPDATE customers SET mode=?,credit_limit=?,terms_days=?,enabled=? WHERE id=?',[$mode,$limit,$terms,$enabled,$id]);if($old['mode']!==$mode||!$enabled){foreach($s->query('SELECT owner FROM customer_sessions WHERE customer_id=?',[$id])->fetchAll()as$r)$a->logout($r['owner']);}$a->audit($actor,'customer-billing-changed',$id,['before'=>$old,'after'=>['mode'=>$mode,'credit_limit'=>$limit,'terms_days'=>$terms,'enabled'=>$enabled]]);});$a->notifyAccount($id,'Your billing mode, credit limit or account access was updated by Primeserve. Please log in to review your account.');break;
                case 'price':
                    $id=(string)($d['id']??'');$service=(string)($d['service']??'');$net=CustomerAccounts::paise((string)($d['net']??''));if(!in_array($service,CustomerAccounts::SERVICES,true))throw new RuntimeException('Choose a service.');
                    $a->clientPrice($id,$service,(string)($d['pricingMode']??'special'),$net,($d['enabled']??true)===true,$actor);$result=['message'=>'Client pricing saved. Special rates are protected from standard price changes.'];break;
                case 'recharge':
                    if(($d['approve']??false)===true&&($d['bankVerified']??false)!==true)throw new RuntimeException('Independently verify the business bank receipt before approval.');
                    $a->approveRecharge((string)($d['id']??''),($d['approve']??false)===true,trim((string)($d['reason']??'')),$actor);break;
                case 'refund-review':
                    if(!in_array($d['decision']??'',['approve','reject'],true))throw new RuntimeException('Choose approve or reject.');
                    (new \App\Libraries\WalletRefunds($a))->review((string)($d['id']??''),($d['decision']??'')==='approve',CustomerAccounts::paise((string)($d['amount']??'0')),(string)($d['reason']??''),($d['emailReceived']??false)===true);$result=['message'=>'Refund review saved. Approved refunds require a separate manual bank transfer.'];break;
                case 'refund-paid':
                    (new \App\Libraries\WalletRefunds($a))->paid((string)($d['id']??''),(string)($d['reference']??''),($d['transferConfirmed']??false)===true);$result=['message'=>'Manual refund payment recorded.'];break;
                case 'wallet-debit':
                    if(($d['confirmed']??false)!==true)throw new RuntimeException('Confirm that this correction will reduce the customer wallet balance.');
                    $created=$a->adminDebit((string)($d['id']??''),CustomerAccounts::paise((string)($d['amount']??'')),(string)($d['reason']??''),(string)($d['requestId']??''),$actor);
                    $result=['message'=>$created?'Wallet balance reduced successfully.':'This wallet correction was already applied.'];break;
                case 'wallet-credit':
                    $created=$a->adminCredit((string)($d['id']??''),CustomerAccounts::paise((string)($d['amount']??'')),(string)($d['reason']??''),(string)($d['requestId']??''),$actor);
                    $result=['message'=>$created?'Wallet balance added successfully.':'This wallet credit was already applied.'];break;
                case 'review':
                    $outcome=(string)($d['outcome']??'');$reason=trim((string)($d['reason']??''));if(!in_array($outcome,['charged','released'],true)||strlen($reason)<5||strlen($reason)>300)throw new RuntimeException('Enter the verified outcome and a review reason.');
                    $key=(string)($d['requestKey']??'');$a->settle($key,$outcome);$a->audit($actor,'billing-review',$key,['outcome'=>$outcome,'reason'=>$reason]);break;
                case 'postpaid-payment':
                    $id=(string)($d['id']??'');$amount=CustomerAccounts::paise((string)($d['amount']??''));$ref=strtoupper(trim((string)($d['reference']??'')));if($amount<1||!preg_match('/^[A-Z0-9-]{6,64}$/D',$ref)||($d['bankVerified']??false)!==true)throw new RuntimeException('Enter the independently verified payment and bank reference.');
                    $a->atomic(function()use($s,$a,$id,$amount,$ref,$actor){if($s->query("SELECT id FROM customer_audit WHERE action='postpaid-payment' AND subject=?",[$ref])->fetchColumn())return;
                        $r=$s->query('UPDATE customers SET outstanding=outstanding-? WHERE id=? AND outstanding>=?',[$amount,$id,$amount]);if($r->rowCount()!==1)throw new RuntimeException('Payment exceeds the outstanding balance.');$a->audit($actor,'postpaid-payment',$ref,['customer'=>$id,'amount'=>$amount]);});break;
                case 'section-excel':
                    $section=(string)($d['section']??'');$bytes=\App\Libraries\AdminReports::section($s,$section,(string)($d['search']??''));$a->audit($actor,'section-report-export',$section);
                    return $this->response->setHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setHeader('Content-Disposition','attachment; filename="Primeserve_Section.xlsx"')->setBody($bytes);
                case 'excel':
                    $kind=(string)($d['kind']??'');$from=(string)($d['from']??'');$to=(string)($d['to']??'');$id=(string)($d['id']??'');
                    $report=\App\Libraries\AdminReports::download($s,$kind,$from,$to,$id);$a->audit($actor,'excel-report-export',$kind,['from'=>$from,'to'=>$to,'customer'=>$id,'records'=>$report['records'],'parts'=>$report['parts']]);
                    return $this->response->setHeader('Content-Type',$report['format']==='zip'?'application/zip':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setHeader('Content-Disposition','attachment; filename="Primeserve_'.$kind.'_'.$from.'_'.$to.'.'.$report['format'].'"')->setHeader('X-Report-Records',(string)$report['records'])->setHeader('X-Report-Parts',(string)$report['parts'])->setBody($report['bytes']);
                case 'export':
                    $month=(string)($d['month']??'');if(!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('Choose a valid statement month.');$zone=new \DateTimeZone('Asia/Kolkata');$start=new \DateTimeImmutable($month.'-01 00:00:00',$zone);$end=$start->modify('+1 month');$id=(string)($d['id']??'');
                    $kind=(string)($d['kind']??'usage');if(!in_array($kind,['usage','payments'],true))throw new RuntimeException('Choose usage or payment report.');
                    $rows=$kind==='usage'?$s->query("SELECT u.*,c.name,c.company,c.email FROM billing_usage u JOIN customers c ON c.id=u.customer_id WHERE u.created>=? AND u.created<? AND (?='' OR customer_id=?) ORDER BY u.created",[$start->getTimestamp(),$end->getTimestamp(),$id,$id])->fetchAll():$s->query("SELECT customer_id,amount,utr AS reference,status,created,'prepaid-recharge' AS kind FROM recharge_requests WHERE created>=? AND created<? AND (?='' OR customer_id=?) UNION ALL SELECT json_extract(detail,'$.customer') AS customer_id,json_extract(detail,'$.amount') AS amount,subject AS reference,'recorded' AS status,created,'postpaid-payment' AS kind FROM customer_audit WHERE action='postpaid-payment' AND created>=? AND created<? AND (?='' OR json_extract(detail,'$.customer')=?) ORDER BY created",[$start->getTimestamp(),$end->getTimestamp(),$id,$id,$start->getTimestamp(),$end->getTimestamp(),$id,$id])->fetchAll();
                    $stream=fopen('php://temp','w+');fwrite($stream,"\xEF\xBB\xBF");if($rows){fputcsv($stream,array_keys($rows[0]));foreach($rows as$row)fputcsv($stream,array_map(static fn($v)=>preg_match('/^[=+@\t\r-]/',(string)$v)?"'".$v:$v,$row));}rewind($stream);$bytes=stream_get_contents($stream);fclose($stream);$a->audit($actor,'report-export',$kind,['month'=>$month,'customer'=>$id]);return $this->response->setHeader('Content-Type','text/csv; charset=utf-8')->setHeader('Content-Disposition','attachment; filename="Primeserve_'.$kind.'_'.$month.'.csv"')->setBody($bytes);
                default:throw new RuntimeException('Unknown admin action.');
            }
            return $this->response->setJSON($result);
        }catch(Throwable $e){return $this->response->setStatusCode(422)->setJSON(['error'=>get_class($e)===RuntimeException::class?$e->getMessage():'Unable to complete the admin request.']);}
    }
}
