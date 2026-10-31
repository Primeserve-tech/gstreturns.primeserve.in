<?php
namespace App\Libraries;

final class ClientIp
{
    public static function resolve(string $peer,string $forwarded='',?array $trusted=null): string
    {
        if (!filter_var($peer,FILTER_VALIDATE_IP)) return 'unknown';
        $trusted??=array_filter(array_map('trim',explode(',',(string)env('GST_TRUSTED_PROXIES',''))));
        if (!self::trusted($peer,$trusted)) return $peer;
        $chain=array_map('trim',explode(',',$forwarded));$chain[]=$peer;
        foreach (array_reverse($chain) as $ip) {
            if (!filter_var($ip,FILTER_VALIDATE_IP)) return $peer;
            if (!self::trusted($ip,$trusted)) return $ip;
        }
        return $peer;
    }
    private static function trusted(string $ip,array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network,$bits]=array_pad(explode('/',$range,2),2,null);
            $address=inet_pton($ip);$base=@inet_pton($network);
            if ($base===false || $address===false || strlen($address)!==strlen($base)) continue;
            $bits=$bits===null?strlen($base)*8:(int)$bits;
            if ($bits<0 || $bits>strlen($base)*8) continue;
            $whole=intdiv($bits,8);$remaining=$bits%8;
            if (substr($address,0,$whole)===substr($base,0,$whole) && ($remaining===0 || (ord($address[$whole]) & (255<<(8-$remaining))) === (ord($base[$whole]) & (255<<(8-$remaining))))) return true;
        }
        return false;
    }
}
