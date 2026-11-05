<?php
require __DIR__.'/../vendor/autoload.php';require __DIR__.'/../vendor/codeigniter4/framework/system/Common.php';
define('ROOTPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('APPPATH',ROOTPATH.'app/');define('WRITEPATH',sys_get_temp_dir().'/ps-migration-review-'.bin2hex(random_bytes(6)).'/');mkdir(WRITEPATH.'data',0700,true);putenv('GST_MODE=fixture');putenv('GST_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
use App\Libraries\ReturnStore;use App\Libraries\CustomerAccounts;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$s=new ReturnStore();for($i=1;$i<=4;$i++)$s->db->exec(file_get_contents(APPPATH.'Database/customer-v'.$i.'.sql'));
foreach(['legacy-standard','legacy-special','legacy-equal']as$i=>$id){
 $s->query('INSERT INTO customers(id,name,email,mobile,company,password_hash,balance,created) VALUES(?,?,?,?,?,?,?,?)',[$id,'Migration fixture',$id.'@example.invalid','+91987650100'.$i,'Fixture company',password_hash('FixturePassword123!',PASSWORD_DEFAULT),12345,time()]);
 $s->query('INSERT INTO customer_prices VALUES(?,?,?,1)',[$id,'returns',$id==='legacy-special'?250:300]);
}
$s->query('INSERT INTO customer_audit VALUES(?,?,?,?,?,?)',['legacy-price-audit','admin','client-price-changed','legacy-equal',json_encode(['service'=>'returns','after_net'=>300]),time()]);
$s->query('INSERT INTO customer_ledger VALUES(?,?,?,?,?,?,?)',['legacy-ledger','legacy-standard','paid-recharge',12345,12345,'legacy-payment-ref',time()]);
$s->query("INSERT INTO customer_settings VALUES('prepaid_key',?)",[$s->seal(['key'=>'fixture-preserved-key'])]);
$before=$s->query('SELECT * FROM customers ORDER BY id')->fetchAll();$ledger=$s->query('SELECT * FROM customer_ledger')->fetchAll();$key=$s->query("SELECT value FROM customer_settings WHERE name='prepaid_key'")->fetchColumn();
$a=new CustomerAccounts($s,fn()=>null);new CustomerAccounts($s,fn()=>null);
foreach($before as$old){$now=$s->query('SELECT * FROM customers WHERE id=?',[$old['id']])->fetch();foreach($old as$k=>$v)check($now[$k]===$v,'legacy customer field preserved: '.$k);}
check($s->query('SELECT * FROM customer_ledger')->fetchAll()===$ledger,'financial ledger preserved');
check($s->query("SELECT value FROM customer_settings WHERE name='prepaid_key'")->fetchColumn()===$key,'encrypted backend key unchanged');
check((int)$s->query('SELECT COUNT(*) FROM customer_migrations')->fetchColumn()===9,'all migrations applied exactly once');
foreach(['legacy-standard'=>'standard','legacy-special'=>'special','legacy-equal'=>'special']as$id=>$mode)check($s->query('SELECT pricing_mode FROM customer_prices WHERE customer_id=?',[$id])->fetchColumn()===$mode,'legacy price classified safely: '.$id);
check((int)$s->query('SELECT COUNT(*) FROM customer_consents')->fetchColumn()===0,'migration does not fabricate consent');
check($s->query('PRAGMA integrity_check')->fetchColumn()==='ok','migrated database integrity');
check(!$s->query('PRAGMA foreign_key_check')->fetch(),'migrated foreign keys valid');
