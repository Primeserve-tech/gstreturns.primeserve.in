<?php
namespace App\Libraries;

final class DownloadUrlPolicy
{
    public static function validate(string $url,array $allowedHosts): array
    {
        $parts=parse_url($url);
        if (!$parts || !filter_var($url,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443) || !in_array(strtolower($parts['host']??''),array_map('strtolower',$allowedHosts),true)) throw new \RuntimeException('Provider returned an unauthorized download URL.');
        return $parts;
    }
}
