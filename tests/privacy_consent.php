<?php
require __DIR__.'/customers.php';
use App\Libraries\PrivacyConsent;
$s->query("UPDATE customers SET enabled=1,mode='PREPAID' WHERE id=?",[$c['id']]);
$p=new PrivacyConsent($a);check($p->status($c['id'])['active'],'verified registration records active consent');
$count=(int)$s->query('SELECT COUNT(*) FROM consent_events WHERE customer_id=?',[$c['id']])->fetchColumn();
$a->atomic(fn()=>$p->grant($c['id']));check((int)$s->query('SELECT COUNT(*) FROM consent_events WHERE customer_id=?',[$c['id']])->fetchColumn()===$count,'repeat consent does not create duplicate events');
$a->atomic(fn()=>$p->withdraw($c['id']));check(!$p->status($c['id'])['active'],'withdrawal persists');rejects(fn()=>$p->require($c['id']),'withdrawal stops consent-gated services');
$a->atomic(fn()=>$p->withdraw($c['id']));check((int)$s->query("SELECT COUNT(*) FROM consent_events WHERE customer_id=? AND action='withdrawn'",[$c['id']])->fetchColumn()===1,'withdrawal idempotent');
$a->atomic(fn()=>$p->grant($c['id']));check($p->status($c['id'])['active'],'customer can voluntarily re-consent');
$s->query('UPDATE customer_consents SET version=? WHERE customer_id=?',['old-purpose-notice',$c['id']]);check(!$p->status($c['id'])['active'],'changed consent version requires fresh consent');
$reg=['name'=>'No Consent','company'=>'Fixture Company','email'=>'no-consent@example.com','mobile'=>'9876503215','password'=>'abc','confirm'=>'abc'];rejects(fn()=>$a->register($reg,'no-consent-ip'),'registration requires explicit consent');
$reg['privacyConsent']=true;$reg['privacyVersion']='old';rejects(fn()=>$a->register($reg,'no-consent-ip'),'stale registration notice rejected');
check($s->query('SELECT wording FROM consent_events WHERE customer_id=? LIMIT 1',[$c['id']])->fetchColumn()===PrivacyConsent::WORDING,'consent wording retained as evidence');

$a->atomic(fn()=>$p->grant($c['id']));
$s->query('INSERT INTO customer_sessions(owner,customer_id,expires) VALUES(?,?,?)',['privacy-worker-owner',$c['id'],time()+3600]);$s->query('UPDATE customers SET balance=10000 WHERE id=?',[$c['id']]);
$a->reserve('privacy-worker-owner','returns','return:privacy-queued');
$s->startWindow('privacy-worker-owner','127.0.0.1');
$s->query('INSERT INTO batches(id,owner,request_key,created) VALUES(?,?,?,?)',['privacy-batch','privacy-worker-owner','privacy-batch-key',time()]);
$s->query("INSERT INTO jobs(id,batch_id,owner,return_type,gstin,financial_year,period,status,created,updated) VALUES(?,?,?,?,?,?,?,'queued',?,?)",['privacy-queued','privacy-batch','privacy-worker-owner','GSTR-1','27ABCDE1234F1Z5','2026-27','102026',time(),time()]);
$a->atomic(fn()=>$p->withdraw($c['id']));$w=new App\Libraries\ReturnWorker($s,new App\Libraries\ReturnGateway());$w->tick();
check($s->query('SELECT status FROM billing_usage WHERE request_key=?',['return:privacy-queued'])->fetchColumn()==='released','withdrawn queued request reservation released before initiation');
check((int)$s->query('SELECT balance FROM customers WHERE id=?',[$c['id']])->fetchColumn()===10000,'queued cancellation restores wallet without a provider call');
check($s->query('SELECT status FROM jobs WHERE id=?',['privacy-queued'])->fetchColumn()==='failed','queued request stops after withdrawal');
