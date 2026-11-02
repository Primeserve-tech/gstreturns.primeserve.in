<?php
// Runs only isolated fixture transports with a fresh disposable database and random encryption key.
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/primeserve-customer-stage-'.bin2hex(random_bytes(8));foreach(['data','logs','cache','session','debugbar']as$child)mkdir($dir.'/'.$child,0700,true);
$env=getenv();$env['GST_TEST_WRITEPATH']=$dir;$env['GST_MODE']='fixture';$env['GST_CUSTOMERS_ENABLED']='1';$env['GST_BILLING_VERIFIED']='1';$env['GST_ENCRYPTION_KEY']=base64_encode(random_bytes(32));$env['app_baseURL']='http://127.0.0.1:8087/';$env['ZOHO_CPAAS_API_KEY']='*fixture-email-disabled*';$env['ZOHO_CPAAS_AGENT_ALIAS']='fixture-agent';$env['ZOHO_CPAAS_FROM']='fixture@example.invalid';
$env['session_cookieName']='ps_customer_fixture_session';
$env['GST_ADMIN_USERNAME']='fixture-admin';$env['GST_ADMIN_PASSWORD_HASH']=password_hash('FixtureAdmin123!',PASSWORD_DEFAULT);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:8087','-t','public','tests/customer_router.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server-error.log','a']],$pipes,$root,$env);
if(!is_resource($server))throw new RuntimeException('Unable to start isolated fixture server.');
try{
    usleep(500000);$running=proc_get_status($server);if(!$running['running'])throw new RuntimeException('Fixture server could not bind port 8087.');
    $test=proc_open([PHP_BINARY,'tests/customer_http.php'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$testPipes,$root,$env);$exit=proc_close($test);
    if($exit===0&&in_array('--preview',$argv,true)){echo "Isolated local account preview: http://127.0.0.1:8087/register\nFixture transport only; no production API/email calls.\n";while(true)sleep(1);}
}finally{proc_terminate($server);proc_close($server);}
exit($exit);
