<?php
require __DIR__.'/customers.php';
$s->query("UPDATE customers SET mode='PREPAID',balance=5000 WHERE id=?",[$c['id']]);
$beforeMails=count($mail);
check($a->adminDebit($c['id'],1000,'Correct mistaken credit','correction-test-001','admin'),'Admin reduces wallet');
check(!$a->adminDebit($c['id'],1000,'Correct mistaken credit','correction-test-001','admin'),'duplicate correction does not deduct twice');
check((int)$s->query('SELECT balance FROM customers WHERE id=?',[$c['id']])->fetchColumn()===4000,'wallet reduced exactly once');
check(count($mail)===$beforeMails+1,'customer notified once');
rejects(fn()=>$a->adminDebit($c['id'],1001,'Correct mistaken credit','correction-test-001','admin'),'reference cannot change amount');
rejects(fn()=>$a->adminDebit($c['id'],5000,'Correct mistaken credit','correction-test-002','admin'),'cannot overdraw wallet');
rejects(fn()=>$a->adminDebit($c['id'],0,'Correct mistaken credit','correction-test-003','admin'),'zero deduction rejected');
rejects(fn()=>$a->adminDebit($c['id'],1,'','correction-test-003','admin'),'reason mandatory');
check((int)$s->query("SELECT COUNT(*) FROM customer_ledger WHERE reference='admin-debit:correction-test-001' AND amount=-1000 AND kind='admin-debit'")->fetchColumn()===1,'negative ledger entry preserved');
check((int)$s->query("SELECT COUNT(*) FROM customer_audit WHERE action='admin-wallet-debit' AND subject=?",[$c['id']])->fetchColumn()===1,'correction audited');
$s->query("UPDATE customers SET mode='POSTPAID' WHERE id=?",[$c['id']]);
rejects(fn()=>$a->adminDebit($c['id'],1,'Correct mistaken credit','correction-test-004','admin'),'postpaid debit rejected');
