<?php
namespace App\Libraries;

use RuntimeException;
use ZipArchive;

/** Lossless original JSON export and a field-path workbook, without invented GST layouts. */
final class GstDataExport
{
    public static function format(mixed $value): string
    {
        if (!is_string($value) || !in_array($value,['zip','json','excel','pdf'],true)) throw new RuntimeException('Choose a supported download format.');
        return $value;
    }

    public static function fromZip(string $bytes,string $format,ReturnStore $store): array
    {
        self::format($format);
        if ($format==='zip') return ['bytes'=>$bytes,'extension'=>'zip','mime'=>'application/zip'];
        $path=$store->temporaryZip(time()+600);$zip=new ZipArchive();$opened=false;
        try {
            file_put_contents($path,$bytes);
            if ($zip->open($path,ZipArchive::CHECKCONS)!==true) throw new RuntimeException('The provider archive is invalid.');
            $opened=true;$files=[];$expanded=0;
            if ($zip->numFiles>1000) throw new RuntimeException('Archive contains too many files. Download the original ZIP.');
            for ($i=0;$i<$zip->numFiles;$i++) {
                $entry=$zip->statIndex($i);$extension=strtolower(pathinfo($entry['name'],PATHINFO_EXTENSION));
                if (($format==='pdf'&&$extension!=='pdf') || ($format!=='pdf'&&$extension!=='json')) continue;
                $expanded+=(int)$entry['size'];
                if ($expanded>50*1024*1024) throw new RuntimeException('Export exceeds 50 MB of uncompressed data. Download the original ZIP.');
                $content=$zip->getFromIndex($i,50*1024*1024+1);
                if (!is_string($content)) throw new RuntimeException('Unable to read the provider file.');
                if ($format==='pdf'&&!str_starts_with($content,'%PDF-')) throw new RuntimeException('The provider file is not a valid PDF.');
                // Safe flat names only. No archive entries are extracted to the filesystem.
                $name=preg_replace('/[^A-Za-z0-9_.-]/','_',basename(str_replace('\\','/',$entry['name'])));
                $files[sprintf('%03d_',count($files)+1).$name]=$content;
            }
            if (!$files) throw new RuntimeException($format==='pdf'?'No original GST Portal PDF was supplied. Choose ZIP, JSON or Excel.':'No JSON data is present in this provider ZIP. Download the original ZIP.');
            if ($format==='excel') return self::excel($files,$store);
            if (count($files)===1) return ['bytes'=>reset($files),'extension'=>$format,'mime'=>$format==='pdf'?'application/pdf':'application/json'];
            return self::archive($files,$store);
        } finally {if($opened)$zip->close();@unlink($path);}
    }

    private static function archive(array $files,ReturnStore $store): array
    {
        $path=$store->temporaryZip(time()+600);$zip=new ZipArchive();$opened=false;
        try {
            if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to prepare archive.');$opened=true;
            foreach($files as $name=>$bytes)$zip->addFromString($name,$bytes);
            $zip->close();$opened=false;
            return ['bytes'=>file_get_contents($path),'extension'=>'zip','mime'=>'application/zip'];
        } finally {if($opened)$zip->close();@unlink($path);}
    }

    private static function fieldLabel(string $key): string
    {
        $labels=['doc_issue'=>'Documents Issued','doc_det'=>'Document Details','docs'=>'Documents','net_issue'=>'Net Issued','cancel'=>'Cancelled','num'=>'Number','totnum'=>'Total Number'];
        if(isset($labels[$key]))return $labels[$key];
        $label=preg_replace('/([a-z0-9])([A-Z])/','$1 $2',$key);
        $label=ucwords(str_replace(['_','-'],' ',$label));
        return preg_replace_callback('/\b(gstin|gst|cgst|sgst|igst|utgst|itc|hsn|pos|fy|url|pdf|id)\b/i',static fn($m)=>strtoupper($m[0]),$label);
    }

    private static function fields(mixed $value,string $path='$',string $readable=''): \Generator
    {
        if(is_object($value)||is_array($value)) {
            if(count((array)$value)===0)yield [$readable?:'Root',is_array($value)?'List':'Object',is_array($value)?'[]':'{}',$path];
            foreach($value as $key=>$item) {
                $label=is_array($value)?(($readable?:'Item').' ('.($key+1).')'):($readable!==''?$readable.' → ':'').self::fieldLabel((string)$key);
                yield from self::fields($item,$path.'['.json_encode($key,JSON_UNESCAPED_UNICODE).']',$label);
            }
        } else {
            $type=match(get_debug_type($value)){'string'=>'Text','int'=>'Whole number','float'=>'Decimal number','bool'=>'True / False','null'=>'Null (no value)',default=>get_debug_type($value)};
            yield [$readable?:'Root',$type,is_bool($value)?($value?'true':'false'):($value===null?'null':(string)$value),$path];
        }
    }

    public static function excel(array $jsonFiles,ReturnStore $store): array
    {
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="32" customWidth="1"/><col min="2" max="2" width="65" customWidth="1"/><col min="3" max="3" width="22" customWidth="1"/><col min="4" max="4" width="60" customWidth="1"/><col min="5" max="5" width="65" customWidth="1"/></cols><sheetData>';
        $index=0;
        $row=static function(array $values) use(&$xml,&$index) {
            if(++$index>100000)throw new RuntimeException('Excel export exceeds 100,000 rows. Download JSON instead.');
            $xml.='<row r="'.$index.'">';
            foreach($values as $column=>$value) {
                $value=(string)$value;
                if(mb_strlen($value)>32767)throw new RuntimeException('A field exceeds Excel’s cell limit. Download JSON instead.');
                $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$value);
                // inlineStr prevents formula execution and preserves GSTINs/leading zeroes.
                $xml.='<c r="'.chr(65+$column).$index.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
            }
            $xml.='</row>';
        };
        $row(['Source file','Readable field path','Value type','Value','Original JSON path']);
        foreach($jsonFiles as $source=>$bytes) {
            try {$data=json_decode($bytes,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new RuntimeException('Provider data is not valid JSON. Download the original ZIP instead.');}
            foreach(self::fields($data) as $field)$row([$source,...$field]);
        }
        $xml.='</sheetData><autoFilter ref="A1:E'.$index.'"/></worksheet>';
        $files=[
            '[Content_Types].xml'=>'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="GST Data" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'=>$xml,
        ];
        $result=self::archive($files,$store);$result['extension']='xlsx';$result['mime']='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';return $result;
    }
}
