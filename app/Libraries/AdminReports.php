<?php
namespace App\Libraries;
use RuntimeException;

final class AdminReports
{
    public static function section(ReturnStore $store,string $section,string $search):string
    {
        $queries=[
            'Postpaid Payments'=>"SELECT c.id AS customer_id,c.name,c.email,c.company,json_extract(a.detail,'$.amount') AS amount,a.subject AS reference,a.created FROM customer_audit a JOIN customers c ON c.id=json_extract(a.detail,'$.customer') WHERE a.action='postpaid-payment' ORDER BY a.created DESC LIMIT 500",
            'API Key Configuration'=>"SELECT 'Prepaid backend key' AS configuration,CASE WHEN value!='' THEN 'Configured (secret excluded)' ELSE 'Not configured' END AS status FROM customer_settings WHERE name='prepaid_key'",
            'Customers'=>'SELECT id,name,email,mobile,company,mode,balance,outstanding,credit_limit,terms_days,enabled,state_code,billing_gstin,created FROM customers ORDER BY created DESC LIMIT 500',
            'Current Settings (amounts in paise)'=>"SELECT name,value FROM customer_settings WHERE name!='prepaid_key'",
            'Client Pricing'=>'SELECT p.customer_id,c.name,c.email,c.company,p.service,p.net,p.enabled FROM customer_prices p JOIN customers c ON c.id=p.customer_id',
            'Signup Bonus History'=>'SELECT b.customer_id,c.name,c.email,c.company,b.amount,b.created FROM bonus_awards b JOIN customers c ON c.id=b.customer_id ORDER BY b.created DESC LIMIT 500',
            'Recharge History'=>'SELECT r.id,r.customer_id,c.name,c.email,c.company,r.amount,r.utr,r.status,r.reason,r.created,r.reviewed FROM recharge_requests r JOIN customers c ON c.id=r.customer_id ORDER BY r.created DESC LIMIT 500',
            'Primeserve Payment Email Status'=>'SELECT n.id,n.recharge_id,c.name,c.email,c.company,n.kind,n.status,n.created,n.updated FROM payment_notifications n JOIN recharge_requests r ON r.id=n.recharge_id JOIN customers c ON c.id=r.customer_id ORDER BY n.created DESC LIMIT 500',
            'API Usage & Holds'=>'SELECT u.id,u.customer_id,c.name,c.email,c.company,u.service,u.mode,u.net,u.tax,u.gross,u.status,u.unit,u.created FROM billing_usage u JOIN customers c ON c.id=u.customer_id ORDER BY u.created DESC LIMIT 500',
            'Email Delivery Acceptance Status'=>'SELECT e.id,e.customer_id,c.name,c.email,c.company,e.kind,e.status,e.created FROM email_events e LEFT JOIN customers c ON c.id=e.customer_id ORDER BY e.created DESC LIMIT 500',
            'Duplicate Account Monitoring'=>"SELECT id,actor,action,subject,detail,created FROM customer_audit WHERE action='duplicate-registration' ORDER BY created DESC LIMIT 500",
            'Account & Billing Audit'=>'SELECT id,actor,action,subject,detail,created FROM customer_audit ORDER BY created DESC LIMIT 500',
        ];
        if(!isset($queries[$section]))throw new RuntimeException('Choose a valid section.');
        $search=mb_strtolower(trim(mb_substr($search,0,200)));$rows=$store->query($queries[$section])->fetchAll();
        // Match customer IDs in legacy log rows as well as their readable customer details.
        $customers=$store->query('SELECT id,name,email,mobile,company FROM customers')->fetchAll();$lookup=[];foreach($customers as $c)$lookup[$c['id']]=implode(' ',array_values($c));
        $rows=array_values(array_filter($rows,static function($row)use($search,$lookup){if($search==='')return true;$text=implode(' ',array_values($row));foreach(['customer_id','actor','subject']as$key)if(isset($row[$key],$lookup[$row[$key]]))$text.=' '.$lookup[$row[$key]];return str_contains(mb_strtolower($text),$search);}));
        foreach($rows as &$row){foreach($row as $key=>&$value){if(in_array($key,['amount','balance','outstanding','credit_limit','net','tax','gross'],true))$value=number_format((int)$value/100,2,'.','');elseif(in_array($key,['created','updated','reviewed'],true)&&$value)$value=(new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:s').' IST';}unset($value);}unset($row);
        return AccountExcel::build($rows,$store);
    }
    public static function build(ReturnStore $store,string $kind,string $from,string $to,string $id):string
    {
        $zone=new \DateTimeZone('Asia/Kolkata');
        foreach([$from,$to]as$date){$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new RuntimeException('Choose valid From and To dates.');}
        if($from>$to)throw new RuntimeException('From date must not be after To date.');
        $start=(new \DateTimeImmutable($from,$zone))->getTimestamp();$end=(new \DateTimeImmutable($to,$zone))->modify('+1 day')->getTimestamp();
        $queries=[
            'customers'=>"SELECT id AS customer_id,name,email,mobile,company,mode,balance,outstanding,credit_limit,terms_days,state_code,billing_gstin,enabled,created FROM customers WHERE created>=? AND created<? AND (?='' OR id=?)",
            'usage'=>"SELECT c.name,c.email,c.company,u.service,u.mode,u.net,u.tax,u.gross,u.cgst,u.sgst,u.igst,u.status,u.unit,u.created FROM billing_usage u JOIN customers c ON c.id=u.customer_id WHERE u.created>=? AND u.created<? AND (?='' OR c.id=?)",
            'wallet'=>"SELECT c.name,c.email,c.company,l.kind,l.amount,l.balance_after,l.created FROM customer_ledger l JOIN customers c ON c.id=l.customer_id WHERE l.created>=? AND l.created<? AND (?='' OR c.id=?)",
            'bonus'=>"SELECT c.name,c.email,c.company,b.amount,b.created FROM bonus_awards b JOIN customers c ON c.id=b.customer_id WHERE b.created>=? AND b.created<? AND (?='' OR c.id=?)",
            'payments'=>"SELECT c.name,c.email,c.company,r.amount,r.utr AS reference,r.status,r.reason,r.created,r.reviewed,'Prepaid recharge' AS payment_type FROM recharge_requests r JOIN customers c ON c.id=r.customer_id WHERE r.created>=? AND r.created<? AND (?='' OR c.id=?) UNION ALL SELECT c.name,c.email,c.company,json_extract(a.detail,'$.amount') AS amount,a.subject AS reference,'recorded' AS status,'' AS reason,a.created,a.created AS reviewed,'Postpaid payment' AS payment_type FROM customer_audit a JOIN customers c ON c.id=json_extract(a.detail,'$.customer') WHERE a.action='postpaid-payment' AND a.created>=? AND a.created<? AND (?='' OR c.id=?)"
        ];
        if(!isset($queries[$kind]))throw new RuntimeException('Choose a valid report.');
        $params=[$start,$end,$id,$id];if($kind==='payments')$params=[...$params,...$params];
        $rows=$store->query('SELECT * FROM ('.$queries[$kind].') ORDER BY created LIMIT 100001',$params)->fetchAll();
        if(count($rows)>100000)throw new RuntimeException('Too many records. Choose a shorter date range.');
        foreach($rows as &$row){foreach($row as $key=>&$value){if(in_array($key,['amount','balance','balance_after','outstanding','credit_limit','net','tax','gross','cgst','sgst','igst'],true))$value=number_format((int)$value/100,2,'.','');elseif(in_array($key,['created','reviewed'],true)&&$value)$value=(new \DateTimeImmutable('@'.$value))->setTimezone($zone)->format('Y-m-d H:i:s').' IST';}unset($value);}unset($row);
        return AccountExcel::build($rows,$store);
    }
}
