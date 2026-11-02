<?php
namespace App\Libraries;
use RuntimeException;

final class ZohoMailer
{
    public static function accepted(int $status,string $body):bool
    {
        $result=json_decode($body,true);
        return $status>=200&&$status<300&&($result['data'][0]['code']??null)==='EM_104';
    }
    public function configured(): bool
    {
        return (string)env('ZOHO_CPAAS_HOST','https://cpaas.zoho.in')==='https://cpaas.zoho.in'
            && trim((string)env('ZOHO_CPAAS_AGENT_ALIAS',''))!==''
            && preg_match('/^(?:Zoho-enczapikey[ \t]+)?[A-Za-z0-9._+\/=-]{20,1024}$/iD',(string)env('ZOHO_CPAAS_API_KEY',''))===1
            && preg_match('/^[A-Za-z0-9._+-]+@primeserve\.in$/i',(string)env('ZOHO_CPAAS_FROM',''))===1;
    }

    public function send(string $email,string $name,string $subject,string $message): void
    {
        if((string)env('GST_MODE','disabled')==='fixture')throw new RuntimeException('Live email delivery is disabled in fixture mode.');
        if(!$this->configured())throw new RuntimeException('Email delivery is not configured. Please contact support.');
        $key=preg_replace('/^Zoho-enczapikey\s+/i','',(string)env('ZOHO_CPAAS_API_KEY',''));
        $escape=static fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body=['from'=>['address'=>(string)env('ZOHO_CPAAS_FROM',''),'name'=>'Primeserve Global Solution Private Limited'],'to'=>[['email_address'=>['address'=>$email,'name'=>$name]]],'subject'=>$subject,'htmlbody'=>'<html><body style="background:#edf4ff;font-family:Arial,sans-serif;color:#142853;padding:24px"><div style="max-width:600px;margin:auto;background:white;padding:32px;border-top:5px solid #174fbe"><h2>Primeserve GST Portal</h2><p>Hello '.$escape($name).',</p><p>'.nl2br($escape($message)).'</p><p>Primeserve Global Solution Private Limited</p></div></body></html>'];
        $ch=curl_init('https://cpaas.zoho.in/v1.1/email');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Zoho-enczapikey '.$key,'Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $response=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        // Never expose/log upstream response bodies, request headers or the OTP.
        if(!is_string($response)||!self::accepted($status,$response))throw new RuntimeException('Email delivery could not be confirmed. Please try again later.');
    }
}
