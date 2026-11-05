<?php
namespace App\Libraries;
use RuntimeException;

/** Manual refunds: no email dispatch and no payment provider calls. Amounts are integer paise. */
final class WalletRefunds
{
    public function __construct(private CustomerAccounts $a){}
    public function eligible(string $id):int
    {
        $s=$this->a->store;
        $balance=(int)$s->query('SELECT balance FROM customers WHERE id=?',[$id])->fetchColumn();
        $paid=(int)$s->query("SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE customer_id=? AND kind='paid-recharge'",[$id])->fetchColumn();
        // Conservatively allocate API consumption and unresolved reservations against paid credits first.
        $used=(int)$s->query("SELECT COALESCE(SUM(gross),0) FROM billing_usage WHERE customer_id=? AND mode='PREPAID' AND status IN ('charged','reserved','review')",[$id])->fetchColumn();
        $refunds=(int)$s->query("SELECT COALESCE(SUM(CASE WHEN status='pending' THEN requested WHEN status IN ('approved','paid') THEN approved ELSE 0 END),0) FROM refund_requests WHERE customer_id=?",[$id])->fetchColumn();
        $corrections=-(int)$s->query("SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE customer_id=? AND kind='admin-debit'",[$id])->fetchColumn();
        return max(0,min($balance,$paid-$used-$refunds-$corrections));
    }
    private function ledger(string $customer,int $amount,string $kind,string $ref):void
    {
        $s=$this->a->store;
        $r=$s->query('UPDATE customers SET balance=balance+? WHERE id=? AND balance+?>=0',[$amount,$customer,$amount]);
        if($r->rowCount()!==1)throw new RuntimeException('Insufficient available wallet balance.');
        $balance=(int)$s->query('SELECT balance FROM customers WHERE id=?',[$customer])->fetchColumn();
        $s->query('INSERT INTO customer_ledger VALUES(?,?,?,?,?,?,?)',[CustomerAccounts::id(),$customer,$kind,$amount,$balance,$ref,time()]);
    }
    public function request(string $owner,array $d):string
    {
        $amount=CustomerAccounts::paise((string)($d['amount']??''));$reason=trim((string)($d['reason']??''));$upi=trim((string)($d['upi']??''));$key=(string)($d['requestId']??'');
        if(($d['emailRequired']??false)!==true)throw new RuntimeException('You must also email complete details and a valid reason to support@primeserve.in.');
        if($amount<1||mb_strlen($reason)<10||mb_strlen($reason)>1000||!preg_match('/^[a-zA-Z0-9._-]{2,128}@[a-zA-Z]{2,64}$/D',$upi)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$key))throw new RuntimeException('Enter a valid amount, UPI ID and detailed refund reason.');
        return $this->a->atomic(function()use($owner,$amount,$reason,$upi,$key){
            $s=$this->a->store;$c=$this->a->customer($owner);
            $old=$s->query('SELECT * FROM refund_requests WHERE request_key=?',[$key])->fetch();
            if($old){if($old['customer_id']!==$c['id']||(int)$old['requested']!==$amount||$old['reason']!==$reason||$s->open($old['destination'])['upi']!==$upi)throw new RuntimeException('Refund request reference already used.');return $old['id'];}
            if($c['mode']!=='PREPAID')throw new RuntimeException('Wallet refunds are available for prepaid accounts only.');
            if($s->query("SELECT id FROM refund_requests WHERE customer_id=? AND status IN ('pending','approved')",[$c['id']])->fetchColumn())throw new RuntimeException('You already have an open refund request.');
            if($amount>$this->eligible($c['id']))throw new RuntimeException('Requested amount exceeds your eligible unused paid balance. Bonus and Admin-granted credits are not refundable.');
            $id=CustomerAccounts::id();$s->query('INSERT INTO refund_requests(id,customer_id,request_key,requested,destination,reason,created) VALUES(?,?,?,?,?,?,?)',[$id,$c['id'],$key,$amount,$s->seal(['upi'=>$upi]),$reason,time()]);
            $this->ledger($c['id'],-$amount,'refund-hold','refund-hold:'.$id);$this->a->audit($c['id'],'refund-requested',$id,['amount'=>$amount]);return $id;
        });
    }
    public function review(string $id,bool $approve,int $amount,string $note,bool $emailReceived):void
    {
        if(mb_strlen(trim($note))<5||mb_strlen($note)>1000)throw new RuntimeException('Enter your review reason.');
        if($approve&&!$emailReceived)throw new RuntimeException('Confirm the customer email was received at support@primeserve.in before approval.');
        $this->a->atomic(function()use($id,$approve,$amount,$note,$emailReceived){
            $s=$this->a->store;$r=$s->query('SELECT * FROM refund_requests WHERE id=?',[$id])->fetch();
            if(!$r||$r['status']!=='pending')throw new RuntimeException('This refund is no longer awaiting review. Refresh the page.');
            $amount=$approve?$amount:0;if($approve&&($amount<1||$amount>(int)$r['requested']))throw new RuntimeException('Approved amount must be positive and no more than requested.');
            $release=(int)$r['requested']-$amount;if($release)$this->ledger($r['customer_id'],$release,'refund-release','refund-release:'.$id);
            $s->query('UPDATE refund_requests SET approved=?,status=?,email_received=?,review_note=?,reviewed=? WHERE id=?',[$amount,$approve?'approved':'rejected',$emailReceived?1:0,trim($note),time(),$id]);
            $this->a->audit('admin','refund-reviewed',$id,['approved'=>$amount,'email_received'=>$emailReceived,'decision'=>$approve?'approved':'rejected']);
        });
    }
    public function paid(string $id,string $reference,bool $confirmed):void
    {
        $reference=strtoupper(trim($reference));if(!$confirmed||!preg_match('/^[A-Z0-9-]{6,64}$/D',$reference))throw new RuntimeException('Complete the manual transfer, verify it succeeded and enter its bank / UTR reference.');
        $this->a->atomic(function()use($id,$reference){
            $s=$this->a->store;$r=$s->query('SELECT * FROM refund_requests WHERE id=?',[$id])->fetch();
            if($r&&$r['status']==='paid'&&$r['payment_reference']===$reference)return;
            if(!$r||$r['status']!=='approved'||!(int)$r['email_received'])throw new RuntimeException('Approve the refund after receiving the mandatory customer email first.');
            if($s->query('SELECT id FROM refund_requests WHERE payment_reference=?',[$reference])->fetchColumn())throw new RuntimeException('This payment reference has already been recorded.');
            $s->query("UPDATE refund_requests SET status='paid',payment_reference=?,paid=? WHERE id=?",[$reference,time(),$id]);
            // Funds were deducted once at request; never deduct again on payment.
            $this->a->audit('admin','refund-paid',$id,['amount'=>(int)$r['approved'],'reference'=>$reference]);
        });
    }
    public function adminRows(bool $openOnly=false):array
    {
        $rows=$this->a->store->query("SELECT r.id,c.name,c.email,c.company,r.requested,r.approved,r.status,r.email_received,r.reason,r.review_note,r.payment_reference,r.created,r.destination FROM refund_requests r JOIN customers c ON c.id=r.customer_id ".($openOnly?"WHERE r.status IN ('pending','approved') ORDER BY r.created":"ORDER BY r.created DESC LIMIT 50"))->fetchAll();
        foreach($rows as &$r){$r['upi']=$this->a->store->open($r['destination'])['upi'];unset($r['destination']);}return $rows;
    }
}
