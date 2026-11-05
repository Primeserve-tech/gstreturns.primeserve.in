<?php
namespace App\Libraries;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/** Consume one database cursor without loading the whole report into memory. */
final class SplitExcelReport
{
    public static function build(iterable $rows,ReturnStore $store,int $chunkSize=100000):array
    {
        if($chunkSize<1||$chunkSize>100000)throw new RuntimeException('Invalid report part size.');
        $files=[];$xml=null;$xmlPath=null;$count=0;$total=0;$headers=null;$archivePath=null;
        $write=static function(XMLWriter $xml,array $values):void{
            $xml->startElement('row');foreach($values as$value){$xml->startElement('c');$xml->writeAttribute('t','inlineStr');$xml->startElement('is');$xml->writeElement('t',(string)$value);$xml->endElement();$xml->endElement();}$xml->endElement();
        };
        $start=function()use(&$xml,&$xmlPath,$store):void{
            $xmlPath=$store->temporaryZip(time()+600);$xml=new XMLWriter();if(!$xml->openUri($xmlPath))throw new RuntimeException('Unable to prepare report.');
            $xml->startDocument('1.0','UTF-8');$xml->startElementNs(null,'worksheet','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$xml->startElement('sheetData');
        };
        $finish=function()use(&$xml,&$xmlPath,&$files,$store):void{
            $xml->endElement();$xml->endElement();$xml->endDocument();$xml->flush();$xml=null;
            $path=$store->temporaryZip(time()+600);$files[]=$path;
            if(file_put_contents($path,AccountExcel::build([],$store))===false)throw new RuntimeException('Unable to write report.');
            $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Unable to open report.');
            if(!$zip->addFile($xmlPath,'xl/worksheets/sheet1.xml')){$zip->close();throw new RuntimeException('Unable to write report rows.');}
            if(!$zip->close())throw new RuntimeException('Unable to finish report.');
            unlink($xmlPath);$xmlPath=null;
        };
        try{
            foreach($rows as$row){
                if($count===0){$start();$headers??=array_keys($row);$write($xml,$headers);}
                $write($xml,array_values($row));$count++;$total++;
                if($count===$chunkSize){$finish();$count=0;}
            }
            if($count>0)$finish();
            if(!$files)return ['bytes'=>AccountExcel::build([],$store),'format'=>'xlsx','parts'=>1,'records'=>0];
            if(count($files)===1)return ['bytes'=>file_get_contents($files[0]),'format'=>'xlsx','parts'=>1,'records'=>$total];
            $archivePath=$store->temporaryZip(time()+600);$zip=new ZipArchive();
            if($zip->open($archivePath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to prepare report archive.');
            foreach($files as$i=>$file)if(!$zip->addFile($file,sprintf('Primeserve_Report_Part_%03d.xlsx',$i+1))){$zip->close();throw new RuntimeException('Unable to add report part.');}
            if(!$zip->close())throw new RuntimeException('Unable to finish report archive.');
            return ['bytes'=>file_get_contents($archivePath),'format'=>'zip','parts'=>count($files),'records'=>$total];
        }finally{
            $xml=null;if($xmlPath&&is_file($xmlPath))unlink($xmlPath);foreach($files as$file)if(is_file($file))unlink($file);if($archivePath&&is_file($archivePath))unlink($archivePath);
        }
    }
}
