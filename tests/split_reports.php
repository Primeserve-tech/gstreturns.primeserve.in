<?php
require __DIR__.'/../vendor/autoload.php';require __DIR__.'/../vendor/codeigniter4/framework/system/Common.php';
define('ROOTPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('APPPATH',ROOTPATH.'app/');define('WRITEPATH',sys_get_temp_dir().'/ps-split-'.bin2hex(random_bytes(5)).'/');mkdir(WRITEPATH.'data',0700,true);
putenv('GST_MODE=fixture');putenv('GST_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
use App\Libraries\ReturnStore;use App\Libraries\SplitExcelReport;use App\Libraries\AdminReports;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$store=new ReturnStore();
$rows=static function($count){for($i=1;$i<=$count;$i++)yield ['sequence'=>(string)$i,'name'=>'Fixture customer','amount'=>'3.54'];};
foreach([0,3,100000,100001]as$count){
 $result=SplitExcelReport::build($rows($count),$store);
 check($result['records']===$count&&$result['parts']===max(1,(int)ceil($count/100000)),'correct count and part total for '.$count.' rows');
 check($result['format']===($count>100000?'zip':'xlsx'),'correct format at '.$count.' rows');
 $path=WRITEPATH.'result.zip';file_put_contents($path,$result['bytes']);$outer=new ZipArchive();check($outer->open($path,ZipArchive::CHECKCONS)===true,'valid archive at '.$count.' rows');
 if($count===0){$outer->close();unlink($path);continue;}
 $expected=1;
 for($part=0;$part<$result['parts'];$part++){
  if($result['format']==='zip'){$partPath=WRITEPATH.'part.xlsx';$partName=sprintf('Primeserve_Report_Part_%03d.xlsx',$part+1);file_put_contents($partPath,$outer->getFromName($partName));$book=new ZipArchive();check($book->open($partPath,ZipArchive::CHECKCONS)===true,'numbered Excel part opens');}
  else $book=$outer;
  $xml=$book->getFromName('xl/worksheets/sheet1.xml');$reader=new XMLReader();$reader->XML($xml);$position=0;$partCount=0;
  while($reader->read())if($reader->nodeType===XMLReader::ELEMENT&&$reader->localName==='t'){
   $value=$reader->readString();if($position>=3&&$position%3===0){if((int)$value!==$expected)throw new RuntimeException('Missing, duplicated or reordered row');$expected++;$partCount++;}$position++;
  }
  $reader->close();check($partCount<=100000,'Excel part stays within row limit');
  if($result['format']==='zip'){$book->close();unlink($partPath);}
 }
 check($expected===$count+1,'every row appears exactly once at '.$count.' rows');$outer->close();unlink($path);unset($result,$xml);
}
$bad=(function(){yield ['sequence'=>'1'];throw new RuntimeException('Fixture interrupted cursor');})();
try{SplitExcelReport::build($bad,$store);throw new LogicException('Expected cursor failure');}catch(RuntimeException $e){check(!($e instanceof LogicException),'cursor failure aborts instead of exporting incomplete data');}
check(count(glob(WRITEPATH.'data/*.zip'))===0,'temporary report files removed on success and failure');

// Exercise the actual filtered database cursor with several same-day records.
$store->query('CREATE TABLE customers(id TEXT,name TEXT,email TEXT,company TEXT)');$store->query('CREATE TABLE customer_ledger(customer_id TEXT,kind TEXT,amount INTEGER,balance_after INTEGER,created INTEGER)');
$store->query("INSERT INTO customers VALUES('client-a','Client A','a@example.invalid','Company A'),('client-b','Client B','b@example.invalid','Company B')");
$date='2026-11-04';$midnight=(new DateTimeImmutable($date,new DateTimeZone('Asia/Kolkata')))->getTimestamp();
foreach([['client-a',$midnight],['client-b',$midnight],['client-a',$midnight-1],['client-a',$midnight+86400]]as[$id,$time])$store->query("INSERT INTO customer_ledger VALUES(?,'admin-credit',100,100,?)",[$id,$time]);
$report=AdminReports::download($store,'wallet',$date,$date,'client-a');check($report['records']===1,'client filter and inclusive Indian date range apply to full reports');
echo 'Split report checks complete. No live API calls made.'.PHP_EOL;
