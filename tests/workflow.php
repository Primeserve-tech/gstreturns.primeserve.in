<?php
// Isolated fixture-only tests. Never contacts Primeserve.
declare(strict_types=1);
define('ROOTPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);
define('WRITEPATH',sys_get_temp_dir().DIRECTORY_SEPARATOR.'ps-returns-test-'.bin2hex(random_bytes(5)).DIRECTORY_SEPARATOR);
mkdir(WRITEPATH.'data',0700,true);
require ROOTPATH.'vendor/autoload.php';
require ROOTPATH.'vendor/codeigniter4/framework/system/Common.php';
putenv('GST_MODE=fixture'); putenv('GST_ENCRYPTION_KEY='.base64_encode(random_bytes(32))); putenv('GST_POLL_SECONDS=1');
use App\Libraries\ReturnStore;
use App\Libraries\ReturnGateway;
use App\Libraries\ReturnWorker;
function check(bool $test,string $label): void { if (!$test) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label.PHP_EOL; }
$store=new ReturnStore(); $gateway=new ReturnGateway(); $worker=new ReturnWorker($store,$gateway);
$credentials=['apiKey'=>'secret-fixture-key','username'=>'fixture-user','gstin'=>'27ABCDE1234F1Z5'];
$sealed=$store->seal($credentials); check(!str_contains($sealed,'secret-fixture-key') && $store->open($sealed)===$credentials,'credentials encrypted and recoverable');
$gateway->otp($credentials); check($gateway->connect($credentials,'123456')===['fixture'=>true],'OTP request and connection');
foreach (['999999'=>'invalid','000000'=>'expired'] as $otp=>$reason) { try { $gateway->connect($credentials,(string)$otp); throw new LogicException('OTP unexpectedly accepted'); } catch (RuntimeException $e) { check(str_contains(strtolower($e->getMessage()),$reason),'OTP '.$reason.' rejected'); } }
check($gateway->period('GSTR-1','2026-27','01')==='012027','financial year January maps to next calendar year');
check($gateway->period('GSTR-9','2026-27','10')==='102026','GSTR-9 uses the documented monthly period');
$store->startWindow('owner','203.0.113.42');
$store->query("INSERT INTO connections(owner,gstin,username,secret,state,expires) VALUES('owner',?,?,?,'connected',?)",[$credentials['gstin'],$credentials['username'],$sealed,time()+86400]);
function batch(ReturnStore $store,array $types,string $name): array {
    $store->query('INSERT INTO batches(id,owner,request_key,created) VALUES(?,?,?,?)',[$name,'owner',$name,time()]); $ids=[];
    foreach ($types as $type) { $id=bin2hex(random_bytes(8));$ids[]=$id; $store->query("INSERT INTO jobs(id,batch_id,owner,return_type,gstin,financial_year,period,status,created,updated) VALUES(?,?,? ,?,?,?,?,'queued',?,?)",[$id,$name,'owner',$type,'27ABCDE1234F1Z5','2026-27','102026',time()-20,time()]); } return $ids;
}
foreach ([['GSTR-1'],['GSTR-1','GSTR-2B','GSTR-3B'],ReturnGateway::TYPES] as $i=>$types) {
    $ids=batch($store,$types,'batch-'.$i);
    foreach ($ids as $index=>$id) {
        $worker->tick(); $job=$store->job('owner',$id); check($job['status']==='processing' && str_starts_with($job['job_id'],'LOCAL-'),'sequential initiation '.$types[$index]);
        foreach (array_slice($ids,$index+1) as $pending) check($store->job('owner',$pending)['status']==='queued','later selected returns remain queued');
    }
    $jobIds=array_map(fn($id)=>$store->job('owner',$id)['job_id'],$ids); check(count(array_unique($jobIds))===count($ids),'independent job ID mapping for '.count($types).' returns');
    $store->query("UPDATE jobs SET next_poll=0 WHERE batch_id=?",['batch-'.$i]);
    foreach ($ids as $id) $worker->tick();
    foreach ($ids as $id) check($store->job('owner',$id)['status']==='completed','pending to completed transition');
}
$job=$store->job('owner',$ids[0]); $bytes=$gateway->download($credentials,$job);
$path=WRITEPATH.'download.zip';file_put_contents($path,$bytes);$zip=new ZipArchive();check($zip->open($path,ZipArchive::CHECKCONS)===true,'individual download is valid ZIP');check(str_contains($zip->getFromName('LOCAL-TEST-ONLY.txt'),'NOT A GST RETURN'),'test files explicitly labelled');$zip->close();
$archive=WRITEPATH.'all.zip';$zip=new ZipArchive();$zip->open($archive,ZipArchive::CREATE);$zip->addFromString('return.zip',$bytes);$zip->close();$zip->open($archive);check($zip->getFromName('return.zip')===$bytes,'nested ZIP original bytes preserved');$zip->close();
try { $store->job('another-user',$ids[0]);throw new LogicException('Access leaked'); } catch(RuntimeException) { check(true,'cross-user job lookup denied'); }
$ids=batch($store,['GSTR-2A','GSTR-3B'],'failure-batch');$credentials['username']='fixture-failure';$store->query('UPDATE connections SET secret=? WHERE owner=?',[$store->seal($credentials),'owner']);$worker->tick();$worker->tick();check($store->job('owner',$ids[0])['status']==='failed' && $store->job('owner',$ids[1])['status']==='processing','one failed initiation does not block the next return');
$store->update($ids[1],['deadline'=>time()-1,'next_poll'=>0]);$worker->tick();check($store->job('owner',$ids[1])['status']==='timeout','bounded monitoring timeout');
$ids=batch($store,['GSTR-1'],'crashed-batch');$store->update($ids[0],['status'=>'initiating']);$worker->tick();check($store->job('owner',$ids[0])['status']==='attention' && $store->job('owner',$ids[0])['retry_allowed']==0,'interrupted initiation never automatically reissued');
$credentials['username']='fixture-timeout';$store->query('UPDATE connections SET secret=? WHERE owner=?',[$store->seal($credentials),'owner']);$ids=batch($store,['GSTR-3B'],'backoff-batch');$worker->tick();
for($attempt=0;$attempt<4;$attempt++) { $store->update($ids[0],['next_poll'=>0]);$worker->tick(); }
check($store->job('owner',$ids[0])['status']==='monitoring_error' && $store->job('owner',$ids[0])['poll_errors']==4,'status errors stop after bounded retries');
$store2=new ReturnStore();check(count($store2->jobs('owner'))>0,'job records survive store reopening');
check(count($store->auditRows())>0,'worker API operations are audited');
check($store->downloadExpiry('owner')===null,'OTP, initiation, processing and completion do not start download deletion');
$completed=$store->query("SELECT id FROM jobs WHERE owner='owner' AND status='completed' LIMIT 1")->fetchColumn();
$receipt=$store->receipt('owner',[$completed]);$store->acknowledge('owner',$receipt);$downloaded=$store->job('owner',$completed);
check((int)$downloaded['expires_at']-(int)$downloaded['downloaded_at']===600,'10-minute timer starts after successful download acknowledgement');
$expiry=(int)$downloaded['expires_at'];$store->acknowledge('owner',$receipt);check((int)$store->job('owner',$completed)['expires_at']===$expiry,'repeat downloads do not extend deletion deadline');
try{$store->acknowledge('another-user',$receipt);throw new LogicException('Receipt leaked');}catch(RuntimeException){check(true,'download acknowledgement bound to owner');}
$before=count($store->auditRows());$temp=$store->temporaryZip(time()+60);file_put_contents($temp,'synthetic ZIP payload');$expiredFile=dirname($temp).'/ttl-'.(time()-1).'-'.bin2hex(random_bytes(12)).'.zip';rename($temp,$expiredFile);
$store->query('UPDATE jobs SET expires_at=? WHERE id=?',[time()-1,$completed]);$store->cleanup();
check(!$store->query('SELECT id FROM jobs WHERE id=?',[$completed])->fetch() && !is_file($expiredFile),'post-download expiry purges downloaded record and temporary ZIP payloads');
check(count($store->jobs('owner'))>0,'undownloaded returns are not deleted with another return');
check(count($store->auditRows())===$before,'content-free admin audit survives ZIP-data deletion');
check($store->connection('owner')['state']==='connected','GST authentication retained independently from ZIP-data retention');
check((int)$store->query('PRAGMA secure_delete')->fetchColumn()===1 && strtolower($store->query('PRAGMA journal_mode')->fetchColumn())==='delete','secure deletion enabled without a persistent WAL');
check(\App\Libraries\ClientIp::resolve('198.51.100.8','1.2.3.4',[])==='198.51.100.8','spoofed forwarding header ignored');
check(\App\Libraries\ClientIp::resolve('172.18.0.5','203.0.113.9, 172.18.0.2',['172.18.0.0/16'])==='203.0.113.9','trusted proxy chain resolves originating IP');
putenv('GST_MODE=live');$live=new ReturnGateway();check($live->ready(),'documented production contract loads without an API key');
check($live->period('GSTR-9','2026-27','10')==='102026','live GSTR-9 documented MMYYYY formatter');
check($live->period('GSTR-1','2026-27','04','0426')==='042026','shared selection generates six-digit period instead of four-digit manual input');
$year=$live->periods('GSTR-1','2025-26','04','yearly');check(count($year)===12 && $year[0]==='042025' && $year[11]==='032026','yearly selection expands financial year April through March');
check($live->periods('GSTR-1','2025-26','04','monthly')===['042025'],'monthly selection creates one period');
$hosts=$live->contract['download']['allowedHosts'];
foreach(['gst-cache.s3.ap-south-1.amazonaws.com','octa-gst-cache.s3.ap-south-1.amazonaws.com'] as $host) check(\App\Libraries\DownloadUrlPolicy::validate('https://'.$host.'/example.zip?X-Amz-Signature=fixture',$hosts)['host']===$host,'documented and actual provider download hosts accepted');
foreach(['http://octa-gst-cache.s3.ap-south-1.amazonaws.com/example.zip','https://octa-gst-cache.s3.ap-south-1.amazonaws.com.attacker.test/example.zip','https://user:secret@octa-gst-cache.s3.ap-south-1.amazonaws.com/example.zip','https://octa-gst-cache.s3.ap-south-1.amazonaws.com:444/example.zip'] as $badUrl){try{\App\Libraries\DownloadUrlPolicy::validate($badUrl,$hosts);throw new LogicException('Unsafe download URL accepted');}catch(RuntimeException){check(true,'unsafe or unrelated download URL rejected');}}
$method=new ReflectionMethod($live,'safeError');$safe=$method->invoke($live,json_encode(['message'=>'Invalid period, key SECRET-KEY, GSTIN 27ABCDE1234F1Z5, https://example.com/secret?token=abc']),['Apikey: SECRET-KEY'],null);
check(!str_contains($safe,'SECRET-KEY')&&!str_contains($safe,'27ABCDE1234F1Z5')&&!str_contains($safe,'token='),'provider error detail redacts credentials and signed URLs');
$live->contract['verified']=false;check(!$live->ready(),'unverified contract blocks live API calls');
$liveStore=new ReturnStore();check($liveStore->jobs('owner')===[],'fixture credentials and jobs isolated from live storage');unset($liveStore);
echo 'All isolated workflow checks passed.'.PHP_EOL;
// Only files in this freshly generated test directory are removed.
unset($store2,$store,$worker);gc_collect_cycles();foreach(glob(WRITEPATH.'data/*') as $file) @unlink($file);foreach(glob(WRITEPATH.'*.zip') as $file) @unlink($file);@rmdir(WRITEPATH.'data');@rmdir(WRITEPATH);
