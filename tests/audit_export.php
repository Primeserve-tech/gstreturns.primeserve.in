<?php
declare(strict_types=1);
define('ROOTPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);
define('WRITEPATH',sys_get_temp_dir().DIRECTORY_SEPARATOR.'ps-audit-export-'.bin2hex(random_bytes(5)).DIRECTORY_SEPARATOR);
mkdir(WRITEPATH.'data',0700,true);
require ROOTPATH.'vendor/autoload.php';require ROOTPATH.'vendor/codeigniter4/framework/system/Common.php';
putenv('GST_MODE=fixture');putenv('GST_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
use App\Libraries\ReturnStore;use App\Libraries\AuditExcel;
function verify(bool $value,string $label): void {if(!$value)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
$store=new ReturnStore();$keyA='fixture-key-ALPHA-123456';$keyB='fixture-key-BETA-789012';
$idA=$store->auditStart('owner','203.0.113.10','OTP request',null,'POST','/api/requestotp',$keyA);$store->auditFinish($idA,'success',200,100);
$store->rememberConnectionKey('owner',$keyA,time()-10);
$legacy=$store->auditStart('owner','203.0.113.10','Job status','GSTR-1','GET','/api/jobstatus');
verify($store->revealAuditKey($legacy)===$keyA,'legacy worker inserts receive a database-level key snapshot');
$store->rememberConnectionKey('owner',$keyB,time());$idB=$store->auditStart('owner','203.0.113.11','=HYPERLINK("https://example.test")','GSTR-2B','GET','/api/test',$keyB);$store->auditFinish($idB,'failed',400,200);
verify($store->revealAuditKey($idA)===$keyA&&$store->revealAuditKey($idB)===$keyB,'key rotation does not change earlier audit attribution');
$rows=$store->auditRows();verify(!str_contains(json_encode($rows),$keyA)&&!str_contains(json_encode($rows),$keyB),'default audit response contains masked keys only');
$cipher=$store->query('SELECT secret FROM audit_keys LIMIT 1')->fetchColumn();verify(!str_contains($cipher,'fixture-key'),'audit keys encrypted at rest');
$excel=new AuditExcel();$masked=$excel->build($store,'','',false);$full=$excel->build($store,'203.0.113.11','failed',true);
foreach(['masked'=>$masked,'full'=>$full] as $name=>$bytes){$p=WRITEPATH.$name.'.xlsx';file_put_contents($p,$bytes);$zip=new ZipArchive();verify($zip->open($p,ZipArchive::CHECKCONS)===true,'valid '.$name.' XLSX package');$xml=$zip->getFromName('xl/worksheets/sheet1.xml');$dom=new DOMDocument();verify($dom->loadXML($xml),'worksheet XML valid');if($name==='masked')verify(!str_contains($xml,$keyA)&&!str_contains($xml,$keyB),'masked Excel export excludes full keys');else{verify(str_contains($xml,$keyB)&&!str_contains($xml,$keyA),'full-key Excel export honors IP/outcome filters');verify($dom->getElementsByTagName('f')->length===0&&str_contains($xml,'HYPERLINK'),'formula-like log text exported as literal text');}$zip->close();}
verify(glob(WRITEPATH.'data/ttl-*')===[],'temporary Excel files removed');
for($i=0;$i<501;$i++)$store->auditStart('owner','203.0.113.12','Fixture audit entry',null,'GET','/fixture/audit',$keyB);
verify(count($store->auditRows())===500,'admin table retains its 500-row display limit');
$all=$excel->build($store,'','',false);$path=WRITEPATH.'all.xlsx';file_put_contents($path,$all);$zip=new ZipArchive();$zip->open($path);$dom=new DOMDocument();$dom->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));verify($dom->getElementsByTagName('row')->length===509,'Excel includes all 504 records beyond the table display limit');$zip->close();
$store->query('DELETE FROM api_audit');verify((int)$store->query('SELECT COUNT(*) FROM audit_keys')->fetchColumn()===0,'keys removed when their final retained audit record is deleted');
unset($store);gc_collect_cycles();foreach(glob(WRITEPATH.'data/*') as $file)@unlink($file);foreach(glob(WRITEPATH.'*.xlsx') as $file)@unlink($file);@rmdir(WRITEPATH.'data');@rmdir(WRITEPATH);
