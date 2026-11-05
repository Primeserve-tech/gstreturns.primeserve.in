<?php
require __DIR__.'/customers.php';
use App\Libraries\WalletRefunds; use App\Libraries\CustomerAccounts as Accounts;
// Isolated fixture database and injected mail transport inherited above; no live email or transfer.
$s->query("UPDATE customers SET mode='PREPAID',balance=10000 WHERE id=?",[$c['id']]);
$s->query('INSERT INTO customer_sessions(owner,customer_id,expires) VALUES(?,?,?)',['refund-owner',$c['id'],time()+3600]);
$f=new WalletRefunds($a);$eligible=$f->eligible($c['id']);check($eligible<=1000,'bonus and Admin credits excluded from refundable paid pool');
$s->query("INSERT INTO customer_ledger VALUES(?,?,?,?,?,?,?)",[Accounts::id(),$c['id'],'paid-recharge',5000,10000,'refund-fixture-paid',time()]);
$before=(int)$a->customer('refund-owner')['balance'];$mailCount=count($mail);
$d=['amount'=>'10.00','reason'=>'Unused paid balance requested back','upi'=>'fixture@hdfc','requestId'=>'refund-fixture-000001','emailRequired'=>false];
rejects(fn()=>$f->request('refund-owner',$d),'mandatory separate support email acknowledgement');
$d['emailRequired']=true;$id=$f->request('refund-owner',$d);check($f->request('refund-owner',$d)===$id,'duplicate refund request is idempotent');
check((int)$a->customer('refund-owner')['balance']===$before-1000,'requested funds held once');
rejects(fn()=>$f->review($id,true,800,'Reviewed payment',false),'approval blocked without received support email');
rejects(fn()=>$f->review($id,true,1100,'Reviewed payment',true),'approval cannot exceed request');
$f->review($id,true,800,'Paid balance verified',true);check((int)$a->customer('refund-owner')['balance']===$before-800,'partial approval releases unapproved remainder');
rejects(fn()=>$f->paid($id,'REFUND12345',false),'manual transfer confirmation mandatory');
$f->paid($id,'REFUND12345',true);$f->paid($id,'REFUND12345',true);check((int)$a->customer('refund-owner')['balance']===$before-800,'paid recording idempotent with no second deduction');
$d['requestId']='refund-fixture-000002';$id2=$f->request('refund-owner',$d);$f->review($id2,false,0,'Request rejected',false);check((int)$a->customer('refund-owner')['balance']===$before-800,'rejection restores full held amount');
check(count($mail)===$mailCount,'refund workflow sends no automatic emails');
check(!str_contains($s->query('SELECT destination FROM refund_requests WHERE id=?',[$id])->fetchColumn(),'fixture@hdfc'),'refund destination encrypted at rest');
check($f->adminRows()[0]['upi']==='fixture@hdfc','authorized Admin receives refund destination');
check(str_starts_with(App\Libraries\AdminReports::build($s,'refunds',date('Y-m-d'),date('Y-m-d'),$c['id']),'PK'),'refund date and client Excel export');
rejects(fn()=>$f->request('other-owner',$d),'refund request requires valid customer session');
echo "Refund tests complete. No automatic email or bank transfer.\n";
