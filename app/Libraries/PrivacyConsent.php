<?php
namespace App\Libraries;
use RuntimeException;
final class PrivacyConsent
{
    public const VERSION='2026-11-04-v1';
    public const PURPOSE='gst-document-services';
    public const WORDING='I consent to Primeserve processing my account contact details, GST credentials and requested GST documents to authenticate my GST account and retrieve and deliver returns, challans and certificates as explained in the Privacy Notice. I can withdraw this consent in My Account. This does not include marketing.';
    public function __construct(private CustomerAccounts $a){}
    public function status(string $id):array
    {
        $r=$this->a->store->query('SELECT version,granted,withdrawn FROM customer_consents WHERE customer_id=? AND purpose=?',[$id,self::PURPOSE])->fetch();
        return ['active'=>$r&&$r['version']===self::VERSION&&$r['withdrawn']===null,'version'=>self::VERSION,'wording'=>self::WORDING,'granted'=>$r['granted']??null,'withdrawn'=>$r['withdrawn']??null];
    }
    public function require(string $id):void{if(!$this->status($id)['active'])throw new RuntimeException('Please review your privacy consent in My Account before using GST services.');}
    /** Caller owns the transaction: registration and account actions are atomic. */
    public function grant(string $id):void
    {
        if($this->status($id)['active'])return;
        $s=$this->a->store;$s->query('INSERT INTO customer_consents VALUES(?,?,?,?,?,NULL) ON CONFLICT(customer_id,purpose) DO UPDATE SET version=excluded.version,wording=excluded.wording,granted=excluded.granted,withdrawn=NULL',[$id,self::PURPOSE,self::VERSION,self::WORDING,time()]);
        $s->query('INSERT INTO consent_events VALUES(?,?,?,?,?,?,?)',[CustomerAccounts::id(),$id,self::PURPOSE,self::VERSION,self::WORDING,'granted',time()]);
    }
    public function withdraw(string $id):void
    {
        if(!$this->status($id)['active'])return;
        $s=$this->a->store;$s->query('UPDATE customer_consents SET withdrawn=? WHERE customer_id=? AND purpose=?',[time(),$id,self::PURPOSE]);
        $s->query('INSERT INTO consent_events VALUES(?,?,?,?,?,?,?)',[CustomerAccounts::id(),$id,self::PURPOSE,self::VERSION,self::WORDING,'withdrawn',time()]);
        // Stop use of saved GST connections; keep account and financial access available.
        foreach($s->query('SELECT owner FROM customer_sessions WHERE customer_id=?',[$id])->fetchAll()as$r)$s->query('DELETE FROM connections WHERE owner=?',[$r['owner']]);
        $this->a->audit($id,'privacy-consent-withdrawn',$id);
    }
}
