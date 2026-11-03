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
check(DocumentResponse::normalize(['body'=>'{"status":true,"data":[]}'],[])['confirmedSuccess']===true,'documented successful challan response recognized');
check(DocumentResponse::normalize(['body'=>'{"status":false,"data":[]}'],[])['reportedError']===true,'unsuccessful challan response never treated as success');
$pdf="%PDF-1.4\noriginal certificate\n%%EOF";
check(DocumentResponse::accessDisabled('GSTIN has disabled API access, or your GSP/ASP name has not been allowed access.'),'actual GST disabled API access wording recognized');
foreach([base64_encode($pdf),json_encode(base64_encode($pdf))] as $encoded)check(DocumentResponse::normalize(['body'=>$encoded],[])['body']===$pdf,'documented Base64 certificate decodes without changing PDF bytes');
check(DocumentResponse::normalize(['body'=>"%PDF-1.7\noriginal PDF bytes"],[])['body']==="%PDF-1.7\noriginal PDF bytes",'direct PDF bytes unchanged');
check(DocumentResponse::normalize(['body'=>"PK\x05\x06".str_repeat("\0",18)],[])['format']==='zip','direct ZIP identified');
try{DocumentResponse::normalize(['body'=>'<html>upstream error</html>'],[]);throw new LogicException('Accepted HTML');}catch(RuntimeException $e){check(!($e instanceof LogicException),'HTML response rejected');}
try{DocumentResponse::normalize(['body'=>'{"message":"API access is not enabled"}'],[]);throw new LogicException('Accepted disabled API');}catch(RuntimeException $e){check($e->getMessage()===DocumentResponse::API_ACCESS_DISABLED,'disabled-access message mapped from actual error text');}
check(ReturnGateway::DOCUMENT_PATHS===['certificate'=>'/api/spark/prime/gst/regcert','challan-yearly'=>'/api/spark/prime/gst/paymentchallansfy','challan-monthly'=>'/api/spark/prime/gst/paymentchallans'],'only documented request paths allowed');

$gateway=(new ReflectionClass(ReturnGateway::class))->newInstanceWithoutConstructor();$safeError=new ReflectionMethod(ReturnGateway::class,'safeError');
$missing=DocumentResponse::normalize(['body'=>'{"status":false,"error":"Registration certificate not found"}'],[]);
check($missing['reportedError']===true&&$missing['format']==='json','missing certificate remains an error response, never a PDF');
check($safeError->invoke($gateway,$missing['body'],[],[])==='Registration certificate not found','actual certificate error is preserved for the popup');
try{DocumentResponse::normalize(['body'=>base64_encode("%PDF-1.4\ntruncated")],[]);throw new LogicException('Accepted truncated PDF');}catch(RuntimeException $e){check(!($e instanceof LogicException),'truncated Base64 PDF rejected');}
foreach([['error'=>'Invalid OTP'],['data'=>['ErrorMessage'=>'OTP rejected']],['error'=>['message'=>'OTP expired']]]as$body){$detail=$safeError->invoke($gateway,json_encode($body),[],[]);check(str_contains($detail,'OTP'),'provider error extracted from explicit message fields');}
$detail=$safeError->invoke($gateway,json_encode(['error'=>['message'=>'OTP 123456 for sampleuser with secret-api-key rejected']]),['Apikey: secret-api-key'],['Otp'=>'123456','userName'=>'sampleuser']);check(!str_contains($detail,'123456')&&!str_contains($detail,'secret-api-key')&&!str_contains($detail,'sampleuser'),'provider error extraction never exposes OTP or credentials');

foreach([['error'=>['error_msg'=>'OTP is invalid']],['status_desc'=>'Provider verification failed'],['title'=>'Invalid request']]as$body)check($safeError->invoke($gateway,json_encode($body),[],[])!=='','GST and validation response messages are extracted');
check($safeError->invoke($gateway,json_encode('OTP verification failed'),[],[])==='OTP verification failed','JSON string error response is readable');
