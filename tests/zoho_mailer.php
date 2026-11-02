<?php
require __DIR__.'/../vendor/autoload.php';require __DIR__.'/../vendor/codeigniter4/framework/system/Common.php';
use App\Libraries\ZohoMailer;
function expectMail($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$accepted='{"data":[{"code":"EM_104","message":"Email request received"}],"message":"OK"}';
foreach([200,201,202]as$status)expectMail(ZohoMailer::accepted($status,$accepted),'explicit email acceptance with HTTP '.$status);
foreach([400,401,403,429,500]as$status)expectMail(!ZohoMailer::accepted($status,$accepted),'HTTP '.$status.' cannot become success');
foreach(['{}','{"message":"OK"}','{"data":[{"code":"TM_3004"}]}','<html>Bad gateway</html>']as$body)expectMail(!ZohoMailer::accepted(200,$body),'unknown or error response rejected');
putenv('ZOHO_CPAAS_HOST=https://cpaas.zoho.in');putenv('ZOHO_CPAAS_AGENT_ALIAS=fixture-agent');putenv('ZOHO_CPAAS_FROM=info@primeserve.in');
// Synthetic token only. No credentials or messages are sent by this test.
putenv('ZOHO_CPAAS_API_KEY=Zoho-enczapikey '.str_repeat('Ab9+/',12).'==');expectMail((new ZohoMailer())->configured(),'Zoho token accepts base64 characters and padding');
putenv("ZOHO_CPAAS_API_KEY=Zoho-enczapikey ".str_repeat('a',40)."\r\nInjected: invalid");expectMail(!(new ZohoMailer())->configured(),'token header injection rejected');
putenv('ZOHO_CPAAS_API_KEY=k=rsa; p=PUBLIC_DKIM_RECORD');expectMail(!(new ZohoMailer())->configured(),'DKIM public record is not a send-mail credential');
putenv('GST_MODE=fixture');
try{(new ZohoMailer())->send('fixture@example.invalid','Fixture','Test','Test');throw new LogicException('Fixture email was allowed');}
catch(RuntimeException $e){expectMail(str_contains($e->getMessage(),'fixture mode'),'fixture mode blocks real email transport before any request');}
