<?php
require __DIR__.'/../vendor/autoload.php';
use App\Libraries\DocumentResponse;
use App\Libraries\ReturnGateway;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS: '.$name.PHP_EOL;}
$input=['body'=>json_encode(['records'=>[['cin'=>'123456','amount'=>123.45]],'password'=>'quote"pass','note'=>'secret-api-key','url'=>'https://example.invalid/file.pdf?sig=sample']),'contentType'=>'application/json'];
$out=DocumentResponse::normalize($input,['secret-api-key','quote"pass']);$json=json_decode($out['body'],true,512,JSON_THROW_ON_ERROR);
check($json['records'][0]['amount']===123.45&&$json['records'][0]['cin']==='123456','actual nested records retain values and types');
check($json['password']==='[redacted]'&&$json['note']==='[redacted]','credential echoes redacted without invalidating JSON');
check($json['url']==='https://example.invalid/file.pdf?sig=sample','unknown URLs preserved as data and never fetched');
check(DocumentResponse::normalize(['body'=>'[]'],[])['format']==='json','empty record response supported');
check(DocumentResponse::normalize(['body'=>"%PDF-1.7\noriginal PDF bytes"],[])['body']==="%PDF-1.7\noriginal PDF bytes",'direct PDF bytes unchanged');
check(DocumentResponse::normalize(['body'=>"PK\x05\x06".str_repeat("\0",18)],[])['format']==='zip','direct ZIP identified');
try{DocumentResponse::normalize(['body'=>'<html>upstream error</html>'],[]);throw new LogicException('Accepted HTML');}catch(RuntimeException $e){check(!($e instanceof LogicException),'HTML response rejected');}
try{DocumentResponse::normalize(['body'=>'{"message":"API access is not enabled"}'],[]);throw new LogicException('Accepted disabled API');}catch(RuntimeException $e){check($e->getMessage()===DocumentResponse::API_ACCESS_DISABLED,'disabled-access message mapped from actual error text');}
check(ReturnGateway::DOCUMENT_PATHS===['certificate'=>'/api/spark/prime/gst/regcert','challan-yearly'=>'/api/spark/prime/gst/paymentchallansfy','challan-monthly'=>'/api/spark/prime/gst/paymentchallans'],'only documented request paths allowed');
