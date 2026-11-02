<?php
namespace App\Libraries;

final class AccountExcel
{
    public static function build(array $rows,ReturnStore $store):string
    {
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $all=$rows?[array_keys($rows[0]),...array_map('array_values',$rows)]:[['No records in this date range']];
        foreach($all as $i=>$values){$xml.='<row r="'.($i+1).'">';foreach($values as $value){$xml.='<c t="inlineStr"><is><t>'.htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</t></is></c>';}$xml.='</row>';}
        $xml.='</sheetData></worksheet>';$path=$store->temporaryZip(time()+600);$zip=new \ZipArchive();
        try{
            if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Unable to prepare Excel export.');
            $zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Account Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();return file_get_contents($path);
        }finally{@unlink($path);}
    }
}
