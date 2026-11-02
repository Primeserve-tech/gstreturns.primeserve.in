<?php
require __DIR__.'/../vendor/autoload.php';require __DIR__.'/../vendor/codeigniter4/framework/system/Common.php';
define('ROOTPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('APPPATH',ROOTPATH.'app/');define('WRITEPATH',sys_get_temp_dir().'/ps-payments-'.bin2hex(random_bytes(5)).'/');mkdir(WRITEPATH.'data',0700,true);putenv('GST_MODE=fixture');putenv('GST_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
use App\Libraries\CustomerAccounts;use App\Libraries\ReturnStore;use App\Libraries\UpiPayment;
function paymentCheck($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$qr=UpiPayment::details('8368414690.1@hdfc','Primeserve Global Solution Private Limited');parse_str(parse_url($qr['intent'],PHP_URL_QUERY),$params);
paymentCheck($params['pa']==='8368414690.1@hdfc'&&$params['cu']==='INR'&&!isset($params['am']),'QR intent targets the requested UPI ID and allows customer-entered amount');
$svg=base64_decode(substr($qr['qrDataUri'],strlen('data:image/svg+xml;base64,')));$doc=new DOMDocument();paymentCheck($doc->loadXML($svg)&&$doc->documentElement->localName==='svg'&&$doc->getElementsByTagName('path')->length>0,'QR is generated locally as valid SVG');
try{UpiPayment::details('javascript:bad','Bad');throw new LogicException('Invalid UPI allowed');}catch(RuntimeException){paymentCheck(true,'invalid UPI destination rejected');}
$mail=[];$store=new ReturnStore();$a=new CustomerAccounts($store,function($email,$name,$subject,$body)use(&$mail){$mail[]=['to'=>$email,'subject'=>$subject,'body'=>$body];});
$attempt=$a->register(['name'=>'Payment Test','company'=>'Fixture Payment Company','email'=>'payer@example.com','mobile'=>'9876543290','password'=>'FixturePassword123!','confirm'=>'FixturePassword123!'],'fixture-ip');preg_match('/\b([0-9]{6})\b/',$mail[0]['body'],$otp);$a->verify($attempt,$otp[1],'fixture-ip');$customer=$a->login('payer@example.com','FixturePassword123!','payment-owner','fixture-ip');
foreach(['upi_id'=>'8368414690.1@hdfc','upi_name'=>'Primeserve Global Solution Private Limited']as$key=>$value)$store->query('UPDATE customer_settings SET value=? WHERE name=?',[$value,$key]);
$id=$a->recharge('payment-owner','118.00','PAYMENT-UTR123');$pending=array_values(array_filter($mail,fn($m)=>$m['subject']==='Payment reference submitted - bank verification required'));
paymentCheck(count($pending)===2&&$pending[0]['to']==='finance@primeserve.in'&&str_contains($pending[0]['body'],'does NOT confirm bank receipt'),'Primeserve submission email clearly says bank receipt is unverified');
foreach(['Payment Test','Fixture Payment Company','payer@example.com','PAYMENT-UTR123','8368414690.1@hdfc','GST collected on this wallet top-up: ₹0.00']as$text)paymentCheck(str_contains($pending[0]['body'],$text),'notification includes '.$text);
paymentCheck((int)$a->customer('payment-owner')['balance']===3000,'submitted reference does not credit wallet');
$a->approveRecharge($id,true,'Verified fixture receipt','admin');$a->approveRecharge($id,true,'Repeated click','admin');
$received=array_values(array_filter($mail,fn($m)=>$m['subject']==='Payment received and wallet credited'));
paymentCheck(count($received)===2&&str_contains($received[0]['body'],'Amount received: ₹118.00')&&str_contains($received[0]['body'],'Wallet credited: ₹118.00'),'one verified receipt email after bank approval with accurate amounts');
paymentCheck(count(array_unique(array_column($received,'to')))===2&&in_array('finance@primeserve.in',array_column($received,'to'),true)&&in_array('primeserve45@gmail.com',array_column($received,'to'),true),'receipt reaches finance and Primeserve Gmail once each');
paymentCheck((int)$a->customer('payment-owner')['balance']===14800,'recharge credited once without adding GST a second time');
paymentCheck((int)$store->query('SELECT COUNT(*) FROM payment_notifications WHERE recharge_id=?',[$id])->fetchColumn()===2,'durable unique submission and received notifications');
$a->deliverQueuedPaymentNotice();paymentCheck(count(array_filter($mail,fn($m)=>$m['subject']==='Payment received and wallet credited'))===2,'background delivery does not repeat accepted messages');
$reject=$a->recharge('payment-owner','10','REJECTED-UTR123');$a->approveRecharge($reject,false,'No matching receipt','admin');paymentCheck((int)$store->query("SELECT COUNT(*) FROM payment_notifications WHERE recharge_id=? AND kind='received'",[$reject])->fetchColumn()===0,'rejected recharge never sends a received-payment confirmation');
$a->atomic(function()use($store,$id){$store->query("UPDATE payment_notifications SET status='sending',updated=? WHERE recharge_id=? AND kind='received'",[time()-600,$id]);});$a->deliverQueuedPaymentNotice();paymentCheck($store->query("SELECT status FROM payment_notifications WHERE recharge_id=? AND kind='received'",[$id])->fetchColumn()==='review','uncertain interrupted delivery is flagged for review instead of duplicated');
echo "All payment checks passed using injected mail transport; no real payments or emails.\n";

$a->adminBillingDetails($customer['id'],'07','07AAACE1288P2Z8');
paymentCheck($a->customer('payment-owner')['billing_gstin']==='07AAACE1288P2Z8','Admin edits customer GST details');
$a->adminBillingDetails($customer['id'],'','',true);
paymentCheck($a->customer('payment-owner')['billing_gstin']==='','Admin removes current GST details');
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$xlsx=$a->historyExport('payment-owner','wallet',$today,$today);$path=WRITEPATH.'report.xlsx';file_put_contents($path,$xlsx);$zip=new ZipArchive();paymentCheck($zip->open($path)===true,'history export is an Excel workbook');$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');$xml=new DOMDocument();paymentCheck($xml->loadXML($sheet)&&str_contains($sheet,'118.00'),'export contains accurate rupee amounts and valid worksheet XML');$zip->close();unlink($path);
try{$a->historyExport('payment-owner','wallet','2026-02-30',$today);throw new LogicException('Invalid date allowed');}catch(RuntimeException){paymentCheck(true,'invalid export dates rejected');}
try{$a->historyExport('payment-owner','wallet','2027-01-01','2026-01-01');throw new LogicException('Reversed range allowed');}catch(RuntimeException){paymentCheck(true,'reversed export range rejected');}

paymentCheck($a->headerBalance('payment-owner')['amount']===14800,'header shows authenticated wallet balance');
$store->query('UPDATE customers SET balance=900 WHERE id=?',[$customer['id']]);
$a->deliverLowBalanceReminder();$a->deliverLowBalanceReminder();
paymentCheck(count(array_filter($mail,fn($m)=>$m['subject']==='Please add money to your Primeserve wallet'))===1,'only one low-balance reminder per low-balance period');
$store->query('UPDATE customers SET balance=2000 WHERE id=?',[$customer['id']]);$a->deliverLowBalanceReminder();
$store->query('UPDATE customers SET balance=0 WHERE id=?',[$customer['id']]);$a->deliverLowBalanceReminder();
paymentCheck(count(array_filter($mail,fn($m)=>$m['subject']==='Please add money to your Primeserve wallet'))===2,'recharged wallet can receive one new reminder when depleted again');
$store->query("UPDATE customers SET mode='POSTPAID',credit_limit=10000,outstanding=2500 WHERE id=?",[$customer['id']]);
paymentCheck($a->headerBalance('payment-owner')['amount']===7500,'postpaid header shows available credit');$a->deliverLowBalanceReminder();
paymentCheck(count(array_filter($mail,fn($m)=>$m['subject']==='Please add money to your Primeserve wallet'))===2,'postpaid customers do not receive wallet reminders');

paymentCheck(!in_array('info@primeserve.in',array_column($mail,'to'),true),'no notifications sent to excluded info address');

foreach(['payments','customers','wallet','bonus','usage']as$kind){$report=\App\Libraries\AdminReports::build($store,$kind,$today,$today,'');$file=WRITEPATH.'admin.xlsx';file_put_contents($file,$report);$archive=new ZipArchive();paymentCheck($archive->open($file)===true,'Admin '.$kind.' date-range report is valid Excel');$sheet=$archive->getFromName('xl/worksheets/sheet1.xml');paymentCheck(!str_contains($sheet,'password_hash')&&!str_contains($sheet,'api_secret'),'Admin '.$kind.' report excludes secrets');$archive->close();unlink($file);}
$empty=\App\Libraries\AdminReports::build($store,'payments',$today,$today,'other-customer');$file=WRITEPATH.'empty.xlsx';file_put_contents($file,$empty);$archive=new ZipArchive();$archive->open($file);paymentCheck(str_contains($archive->getFromName('xl/worksheets/sheet1.xml'),'No records'),'Admin customer report filter excludes other customers');$archive->close();unlink($file);

$registrations=array_values(array_filter($mail,fn($m)=>$m['subject']==='New customer registered - Primeserve'));
paymentCheck(count($registrations)===1&&$registrations[0]['to']==='primeserve45@gmail.com'&&str_contains($registrations[0]['body'],'Fixture Payment Company'),'verified registration notifies Primeserve Gmail once with customer details');
foreach(['Customers','Current Settings (amounts in paise)','Client Pricing','Signup Bonus History','Recharge History','Primeserve Payment Email Status','API Usage & Holds','Email Delivery Acceptance Status','Duplicate Account Monitoring','Account & Billing Audit','Postpaid Payments','API Key Configuration']as$section){$bytes=\App\Libraries\AdminReports::section($store,$section,'');paymentCheck(str_starts_with($bytes,'PK'),'section Excel export: '.$section);}
