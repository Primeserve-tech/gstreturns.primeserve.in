<?php
namespace App\Libraries;
use RuntimeException;
use ZipArchive;

final class AuditExcel
{
    private static function xml(string $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$value),ENT_XML1|ENT_QUOTES,'UTF-8');
    }
    private static function row(int $index,array $values,int $style=0): string
    {
        $cells='';
        foreach ($values as $column=>$value) {
            $ref=chr(65+$column).$index;
            $cells.=is_int($value)?'<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>':'<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string)$value).'</t></is></c>';
        }
        return '<row r="'.$index.'"'.($index===6?' ht="25" customHeight="1"':'').'>'.$cells.'</row>';
    }
    public function build(ReturnStore $store,string $ip,string $outcome,bool $fullKeys): string
    {
        $path=$store->temporaryZip(time()+600);$sheet=$path.'.xml';$handle=fopen($sheet,'xb');
        if (!$handle) throw new RuntimeException('Unable to prepare the Excel export.');
        $opened=false;$zip=new ZipArchive();
        try {
            $zone=new \DateTimeZone('Asia/Kolkata');$now=new \DateTimeImmutable('now',$zone);
            fwrite($handle,'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="22" customWidth="1"/><col min="3" max="3" width="45" customWidth="1"/><col min="4" max="5" width="22" customWidth="1"/><col min="6" max="6" width="12" customWidth="1"/><col min="7" max="7" width="70" customWidth="1"/><col min="8" max="11" width="18" customWidth="1"/></cols><sheetData>');
            fwrite($handle,self::row(1,['PRIMESERVE — API CALL LOG'],1));
            fwrite($handle,self::row(2,['Exported (IST)',$now->format('d M Y H:i:s')],2));
            fwrite($handle,self::row(3,['Filters','Client IP: '.($ip?:'All').' | Outcome: '.($outcome?:'All')],2));
            fwrite($handle,self::row(4,['API key display',$fullKeys?'Full keys — keep this file private':'Masked keys'],2));
            fwrite($handle,self::row(6,['Time (IST)','Client IP','API Key Used','API Operation','Return Type','Method','Endpoint','HTTP Status','Outcome','Duration (ms)','Mode'],3));
            $cursor=$store->auditCursor($ip,$outcome);$rowIndex=7;
            while ($row=$cursor->fetch()) {
                if ($rowIndex>1048576) throw new RuntimeException('The export exceeds Excel’s row limit. Narrow the filters and export again.');
                $key=$fullKeys?$store->revealAuditKey($row['id']):$row['api_key_masked'];
                $time=(new \DateTimeImmutable('@'.$row['started']))->setTimezone($zone)->format('d M Y H:i:s');
                fwrite($handle,self::row($rowIndex++,[$time,$row['client_ip'],$key??'Not captured',$row['operation'],$row['return_type']??'',$row['method'],$row['path'],$row['http_status']===null?'':(int)$row['http_status'],$row['outcome'],$row['duration_ms']===null?'':(int)$row['duration_ms'],$row['mode']],4));
            }
            fwrite($handle,'</sheetData><autoFilter ref="A6:K'.max(6,$rowIndex-1).'"/><mergeCells count="1"><mergeCell ref="A1:K1"/></mergeCells></worksheet>');fclose($handle);$handle=null;
            if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Unable to create Excel archive.');$opened=true;
            $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
            $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="API Logs" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $zip->addFromString('xl/styles.xml','<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/><color rgb="FF17345C"/></font><font><b/><sz val="18"/><name val="Calibri"/><color rgb="FF061B46"/></font><font><sz val="11"/><name val="Calibri"/><color rgb="FF61718A"/></font><font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF061B46"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            $zip->addFile($sheet,'xl/worksheets/sheet1.xml');$zip->close();$opened=false;
            return file_get_contents($path);
        } finally {if(is_resource($handle))fclose($handle);if($opened)$zip->close();@unlink($sheet);@unlink($path);}
    }
}
