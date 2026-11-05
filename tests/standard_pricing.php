<?php
require __DIR__.'/customers.php';
// Existing isolated fixture database, no production calls.
$id=$c['id'];$s->query("UPDATE customers SET mode='PREPAID',enabled=1 WHERE id=?",[$id]);
$s->query('INSERT INTO customer_sessions(owner,customer_id,expires) VALUES(?,?,?)',['pricing-owner',$id,time()+3600]);
$a->clientPrice($id,'returns','standard',0,true,'admin');
$history=$s->query('SELECT * FROM billing_usage')->fetchAll();
$a->standardPrice('returns',400,'admin');
check($a->quote('pricing-owner','returns')['net']===400,'standard clients follow changed global rate');
check($a->quote('pricing-owner','returns')['gross']===472,'GST applies exactly to new rate');
$a->clientPrice($id,'returns','special',250,true,'admin');$a->standardPrice('returns',500,'admin');
check($a->quote('pricing-owner','returns')['net']===250,'special client rate protected');
$a->clientPrice($id,'returns','special',500,true,'admin');$a->standardPrice('returns',600,'admin');
check($a->quote('pricing-owner','returns')['net']===500,'explicit special rate equal to old default still protected');
$a->clientPrice($id,'returns','standard',0,true,'admin');check($a->quote('pricing-owner','returns')['net']===600,'restore standard adopts current rate');
$a->standardPrice('returns',700,'admin');check($a->quote('pricing-owner','returns')['net']===700,'restored client follows future standard changes');
$a->clientPrice($id,'certificate','standard',0,false,'admin');$a->standardPrice('certificate',300,'admin');rejects(fn()=>$a->quote('pricing-owner','certificate'),'global price change does not enable disabled service');
check($s->query('SELECT * FROM billing_usage')->fetchAll()===$history,'all historical usage snapshots preserved');
rejects(fn()=>$a->standardPrice('invalid',100,'admin'),'unknown service rejected');
rejects(fn()=>$a->clientPrice($id,'returns','invalid',100,true,'admin'),'invalid pricing mode rejected');
// Migration preserves old explicitly assigned prices before adding the mode column.
$s->db->exec('ALTER TABLE customer_prices DROP COLUMN pricing_mode');$s->query('DELETE FROM customer_migrations WHERE version=8');
new App\Libraries\CustomerAccounts($s,fn()=>null);
check($s->query('SELECT pricing_mode FROM customer_prices WHERE customer_id=? AND service=?',[$id,'returns'])->fetchColumn()==='special','migration protects previous Admin assigned rates');
echo "Standard pricing checks complete.\n";
