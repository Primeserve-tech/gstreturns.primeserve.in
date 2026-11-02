<?php
require __DIR__.'/../vendor/autoload.php';require __DIR__.'/../vendor/codeigniter4/framework/system/Common.php';
define('ROOTPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('WRITEPATH',sys_get_temp_dir().'/ps-export-'.bin2hex(random_bytes(5)).'/');mkdir(WRITEPATH.'data',0700,true);putenv('GST_MODE=fixture');putenv('GST_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
use App\Libraries\ReturnStore;use App\Libraries\GstDataExport;
function verifyExport($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;}
$store=new ReturnStore();$path=$store->temporaryZip(time()+600);$z=new ZipArchive();$z->open($path,ZipArchive::CREATE);$raw='{"gstin":"001234","amount":12.5,"note":"=HYPERLINK(1)","big":12345678901234567890}';$pdf="%PDF-1.7\nOriginal provider fixture";$z->addFromString('data/one.json',$raw);$z->addFromString('registration.pdf',$pdf);$z->close();$bytes=file_get_contents($path);unlink($path);
verifyExport(GstDataExport::fromZip($bytes,'zip',$store)['bytes']===$bytes,'original ZIP unchanged');
verifyExport(GstDataExport::fromZip($bytes,'json',$store)['bytes']===$raw,'JSON bytes unchanged');
verifyExport(GstDataExport::fromZip($bytes,'pdf',$store)['bytes']===$pdf,'original PDF bytes unchanged; no generated PDF');
$out=GstDataExport::fromZip($bytes,'excel',$store);file_put_contents($path,$out['bytes']);$z->open($path,ZipArchive::CHECKCONS);$xml=$z->getFromName('xl/worksheets/sheet1.xml');$dom=new DOMDocument();verifyExport($dom->loadXML($xml),'valid XLSX worksheet');verifyExport($dom->getElementsByTagName('f')->length===0&&str_contains($xml,'=HYPERLINK(1)'),'Excel formula text stays literal');verifyExport(str_contains($xml,'001234')&&str_contains($xml,'12345678901234567890'),'Excel preserves identifiers and large integers as text');$z->close();unlink($path);
$readable=GstDataExport::excel(['fields.json'=>'{"doc_issue":{"doc_det":[{"docs":[{"net_issue":12,"from":"0001","active":true,"missing":null,"rate":1.5}]}]}}'],$store);
file_put_contents($path,$readable['bytes']);$z->open($path);$sheet=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();unlink($path);
verifyExport(str_contains($sheet,'Documents Issued → Document Details (1) → Documents (1) → Net Issued'),'nested field paths use readable labels and one-based item numbers');
foreach(['Text','Whole number','Decimal number','True / False','Null (no value)'] as $type)verifyExport(str_contains($sheet,$type),'readable value type: '.$type);
verifyExport(str_contains($sheet,'0001')&&str_contains($sheet,'Original JSON path')&&str_contains($sheet,'doc_issue'),'original values and technical paths remain available');
$z->open($path,ZipArchive::CREATE);$z->addFromString('a.json',$raw);$z->addFromString('b.json','[]');$z->close();$multi=GstDataExport::fromZip(file_get_contents($path),'json',$store);unlink($path);file_put_contents($path,$multi['bytes']);$z->open($path);verifyExport($z->numFiles===2&&$z->getFromIndex(0)===$raw,'multiple original JSON files packaged together');$z->close();unlink($path);
try{GstDataExport::fromZip($multi['bytes'],'pdf',$store);throw new LogicException('invented PDF');}catch(RuntimeException $e){verifyExport(str_contains($e->getMessage(),'No original'),'PDF unavailable is rejected');}
verifyExport(glob(WRITEPATH.'data/ttl-*')===[],'temporary export payloads removed');
