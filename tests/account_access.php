<?php
require __DIR__.'/customers.php';
$s->query("UPDATE customers SET enabled=1,mode='PREPAID' WHERE id=?",[$c['id']]);
$before=$s->query('SELECT balance,outstanding FROM customers WHERE id=?',[$c['id']])->fetch();
foreach(['access-owner-a','access-owner-b']as$owner)$s->query('INSERT INTO customer_sessions(owner,customer_id,expires,api_secret) VALUES(?,?,?,?)',[$owner,$c['id'],time()+3600,$s->seal(['key'=>'fixture-session-key'])]);
check($a->customer('access-owner-a')['id']===$c['id'],'active customer session works');
$mailBefore=count($mail);
check($a->setAccess($c['id'],false,'Account misuse reviewed','admin')===true,'block status email accepted');
check(count($mail)===$mailBefore+1&&str_contains(end($mail),'Account status: BLOCKED')&&str_contains(end($mail),'Account misuse reviewed'),'block email includes status and Admin reason');
check($a->setAccess($c['id'],false,'Account misuse reviewed','admin')===null&&count($mail)===$mailBefore+1,'repeated block does not duplicate email');
try{$a->login('customer@example.com','LongPassword!123','blocked-details','block-details-ip');throw new Exception('Blocked login succeeded');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'blocked by Admin')&&str_contains($e->getMessage(),'Account misuse reviewed'),'correct credentials show Admin blocking reason');}
try{$a->login('customer@example.com','wrong-password','blocked-wrong','block-wrong-ip');throw new Exception('Wrong login succeeded');}catch(RuntimeException $e){check($e->getMessage()==='Email or password is incorrect.','wrong password does not disclose blocked status or reason');}
foreach(['access-owner-a','access-owner-b']as$owner)rejects(fn()=>$a->customer($owner),'blocked customer session revoked');
check((int)$s->query('SELECT COUNT(*) FROM customer_sessions WHERE customer_id=?',[$c['id']])->fetchColumn()===0,'all customer sessions and temporary keys deleted');
rejects(fn()=>$a->login('customer@example.com','LongPassword!123','blocked-login','block-ip'),'blocked customer cannot log in');
check($a->setAccess($c['id'],true,'Account review complete','admin')===true,'unblock email accepted');
check(count($mail)===$mailBefore+2&&str_contains(end($mail),'UNBLOCKED / ACTIVE')&&str_contains(end($mail),'Account review complete'),'unblock email includes active status and Admin reason');
check($a->login('customer@example.com','LongPassword!123','unblocked-login','unblock-ip')['id']===$c['id'],'unblocked customer can log in again');
check($s->query('SELECT balance,outstanding FROM customers WHERE id=?',[$c['id']])->fetch()===$before,'block and unblock preserve financial balances');
rejects(fn()=>$a->customer('access-owner-a'),'unblocking does not restore revoked session');
check((int)$s->query("SELECT COUNT(*) FROM customer_audit WHERE subject=? AND action IN ('customer-blocked','customer-unblocked')",[$c['id']])->fetchColumn()===2,'block and unblock audited');
rejects(fn()=>$a->setAccess($c['id'],false,'','admin'),'access change reason required');

// Email failures must not reverse the access restriction or report successful delivery.
$failing=new App\Libraries\CustomerAccounts($s,function(){throw new RuntimeException('Fixture mail failure');});
check($failing->setAccess($c['id'],false,'Fixture mail failure review','admin')===false,'failed status email reported accurately');
check((int)$s->query('SELECT enabled FROM customers WHERE id=?',[$c['id']])->fetchColumn()===0,'email failure does not undo account block');
check($s->query("SELECT status FROM email_events WHERE customer_id=? AND kind='account-blocked' ORDER BY rowid DESC LIMIT 1",[$c['id']])->fetchColumn()==='failed','failed delivery recorded for Admin');
