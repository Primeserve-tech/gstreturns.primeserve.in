<?php
namespace App\Libraries;

final class DocumentResponse
{
    public const API_ACCESS_DISABLED = 'API access is not enabled for your GSTIN. Please log in to the GST Portal and enable API access under My Profile → Manage API Access, then try again.';

    public static function accessDisabled(string $text): bool
    {
        return (bool)preg_match('/api.{0,30}access.{0,30}(not enabled|disabled)|(?:disabled|not enabled).{0,15}api.{0,15}access|enable.{0,30}api.{0,15}access/i',$text);
    }

    public static function normalize(array $response,array $secrets): array
    {
        $json=json_decode($response['body'],true);
        // Certificate documentation returns a Base64 PDF, sometimes JSON quoted.
        $encoded=is_string($json)?$json:trim($response['body']);
        if (str_starts_with($encoded,'JVBERi0')) {
            $pdf=base64_decode(preg_replace('/\s+/','',$encoded),true);
            if ($pdf===false || !str_starts_with($pdf,'%PDF-') || !str_contains(substr($pdf,-2048),'%%EOF')) throw new \RuntimeException('Primeserve returned an invalid certificate PDF.');
            $response['body']=$pdf;
            $response['format']='pdf';
            return $response;
        }
        if (is_array($json)) {
            if (self::accessDisabled($response['body'])) throw new \RuntimeException(self::API_ACCESS_DISABLED);
            array_walk_recursive($json,static function(&$value,$key) use($secrets) {
                if (in_array(strtolower((string)$key),['password','apikey','api_key','otp'],true)) $value='[redacted]';
                elseif (is_string($value)) foreach($secrets as $secret) if($secret!=='')$value=str_replace($secret,'[redacted]',$value);
            });
            $response['body']=json_encode($json,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $response['format']='json';
            $response['reportedError']=!empty($json['error']) || ($json['status']??null)===false;
            $response['confirmedSuccess']=($json['status']??null)===true && ! $response['reportedError'];
        } elseif (str_starts_with($response['body'],'%PDF-')) $response['format']='pdf';
        elseif (str_starts_with($response['body'],"PK\x03\x04") || str_starts_with($response['body'],"PK\x05\x06")) $response['format']='zip';
        else throw new \RuntimeException('Primeserve returned an unsupported document response. Please contact support with the API operation and time.');
        return $response;
    }
}
