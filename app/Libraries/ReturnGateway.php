<?php
namespace App\Libraries;
use RuntimeException;
use ZipArchive;

final class ReturnGateway
{
    public const TYPES = ['GSTR-1','GSTR-1A','GSTR-2A','GSTR-2B','GSTR-3B','GSTR-6','GSTR-6A','GSTR-9'];
    public array $contract = [];
    public bool $fixture;
    private ?ReturnStore $store=null;
    private string $owner='';
    private string $clientIp='unknown';
    private ?int $lastHttpStatus=null;
    private ?string $currentApiKey=null;

    public function audited(ReturnStore $store,string $owner,string $ip): self
    {
        $copy=clone $this; $copy->store=$store;$copy->owner=$owner;$copy->clientIp=$ip;return $copy;
    }

    private function track(string $operation,?string $type,callable $action,?string $apiKey=null): mixed
    {
        $section=match($operation) {'OTP request'=>'otp','GST connect'=>'connect','GST disconnect'=>'disconnect','Return initiation'=>'returns','Job status'=>'status',default=>'download'};
        $spec=$section==='returns'?($this->contract['returns'][$type]??[]):($this->contract[$section]??[]);
        $method=$spec['method']??(in_array($section,['otp','connect','disconnect'],true)?'POST':'GET');
        $path=$spec['path']??($this->fixture?'local-fixture/'.$section:'unconfigured');
        $this->currentApiKey=$apiKey;
        $id=$this->store?->auditStart($this->owner,$this->clientIp,$operation,$type,$method,$path,$apiKey);
        $started=microtime(true);$this->lastHttpStatus=null;
        try {
            if (!$this->fixture && !$this->store) throw new RuntimeException('Live API calls require an audited data window.');
            $result=$action();
            if ($this->store) $this->store->assertWindow($this->owner);
            if ($id) $this->store->auditFinish($id,is_array($result)&&isset($result['status'])?$result['status']:'success',$this->lastHttpStatus,(int)((microtime(true)-$started)*1000));
            return $result;
        } catch (\Throwable $e) {
            if ($id) $this->store->auditFinish($id,'failed',$this->lastHttpStatus,(int)((microtime(true)-$started)*1000));
            throw $e;
        }
    }

    public function otp(array $credentials): void { $this->track('OTP request',null,fn()=>$this->doOtp($credentials),$credentials['apiKey']); }
    public function connect(array $credentials,string $otp): array { return $this->track('GST connect',null,fn()=>$this->doConnect($credentials,$otp),$credentials['apiKey']); }
    public function initiate(array $credentials,array $job): string { return $this->track('Return initiation',$job['return_type'],fn()=>$this->doInitiate($credentials,$job),$credentials['apiKey']); }
    public function status(array $credentials,array $job): array { return $this->track('Job status',$job['return_type'],fn()=>$this->doStatus($credentials,$job),$credentials['apiKey']); }
    public function download(array $credentials,array $job): string { return $this->track('ZIP download',$job['return_type'],fn()=>$this->doDownload($credentials,$job),$credentials['apiKey']); }
    public function disconnect(array $credentials): void { $this->track('GST disconnect',null,function() use($credentials) { if (!$this->fixture) $this->api('disconnect',$credentials,['userName'=>$credentials['username'],'gstin'=>$credentials['gstin']]); },$credentials['apiKey']); }

    public function __construct()
    {
        $mode = (string) env('GST_MODE', 'disabled');
        if (!in_array($mode, ['disabled','fixture','live'], true)) throw new RuntimeException('Unknown GST_MODE.');
        $this->fixture = $mode === 'fixture';
        if ($mode === 'live') {
            $path = ROOTPATH . 'config/contract.json';
            if (!is_file($path)) $path=ROOTPATH.'config/primeserve.documented.json';
            if (is_file($path)) $this->contract = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    public function ready(): bool
    {
        if ($this->fixture) return true;
        try { $this->validateContract(); return true; } catch (RuntimeException) { return false; }
    }

    public function validateContract(): void
    {
        $c = $this->contract;
        if (($c['verified'] ?? false) !== true || ($c['baseUrl'] ?? '') !== 'https://api.primeserve.in') throw new RuntimeException('Live API integration awaits the verified Postman response and ZIP download contract.');
        foreach (['otp','connect','disconnect'] as $name) {
            foreach (['method','path','successPath','successValue'] as $key) if (!isset($c[$name][$key])) throw new RuntimeException('Complete the verified authentication contract first.');
            if ($c[$name]['method'] !== 'POST') throw new RuntimeException('Authentication method must match documented POST requests.');
        }
        foreach (self::TYPES as $type) {
            foreach (['path','method','jobIdPath','periodFormat','periodKind'] as $key) if (!isset($c['returns'][$type][$key])) throw new RuntimeException('Complete the verified return contract first.');
            $spec = $c['returns'][$type];
            if ($spec['method'] !== 'GET' || !in_array($spec['periodKind'], ['month','year','raw'], true) || !in_array($spec['periodFormat'], ['MMYYYY','YYYYMM','YYYY-MM','YYYY-YY','raw'], true)) throw new RuntimeException('Unsupported return contract.');
        }
        foreach (['path','method','statusPath','pendingValues','completedValues','failedValues','emptyValues'] as $key) if (!isset($c['status'][$key])) throw new RuntimeException('Complete the verified status contract first.');
        if (!$c['status']['completedValues'] || $c['status']['method'] !== 'GET') throw new RuntimeException('Document completed status values first.');
        foreach (['method','path','jobIdParameter','responseKind'] as $key) if (empty($c['download'][$key])) throw new RuntimeException('Complete the verified ZIP download contract first.');
        if (!in_array($c['download']['responseKind'], ['binary','url','jobStatusUrl'], true) || !in_array($c['download']['method'], ['GET','POST'], true)) throw new RuntimeException('Unsupported download contract.');
        if (in_array($c['download']['responseKind'],['url','jobStatusUrl'],true) && (empty($c['download']['urlPath']) || empty($c['download']['allowedHosts']))) throw new RuntimeException('Document allowed ZIP URL hosts first.');
        foreach (['otp','connect','disconnect','status','download'] as $name) $this->safePath($c[$name]['path']);
        foreach (self::TYPES as $type) $this->safePath($c['returns'][$type]['path']);
    }

    private function safePath(string $path): void
    {
        if (!preg_match('~^/api/[a-zA-Z0-9/_-]+$~', $path)) throw new RuntimeException('API contract path is invalid.');
    }

    public function spec(string $type): array
    {
        if (!in_array($type, self::TYPES, true)) throw new RuntimeException('Unknown return type.');
        // Fixture period conventions are local test choices, not Primeserve API specifications.
        return $this->fixture ? ['periodKind'=>'month','periodFormat'=>'MMYYYY'] : ($this->contract['returns'][$type] ?? ['periodKind'=>'unverified','periodFormat'=>null]);
    }

    public function period(string $type, string $fy, string $month,string $raw=''): string
    {
        if (!preg_match('/^(20\d{2})-(\d{2})$/', $fy, $m) || ((int)$m[1]+1)%100 !== (int)$m[2]) throw new RuntimeException('Select a valid financial year.');
        $spec = $this->spec($type);
        if ($spec['periodKind']==='raw') {
            if (!preg_match('~^[A-Za-z0-9_/-]{1,32}$~',$raw)) throw new RuntimeException('Enter the exact API period for '.$type.'. Its format is not specified in the documentation.');
            return $raw;
        }
        if ($spec['periodKind'] === 'unverified') throw new RuntimeException('Return period requirements are not verified yet.');
        if ($spec['periodKind'] === 'year') {
            if ($spec['periodFormat'] !== 'YYYY-YY') throw new RuntimeException('Annual period formatter needs documented configuration.');
            return $fy;
        }
        if (!preg_match('/^\d{2}$/', $month) || (int)$month < 1 || (int)$month > 12) throw new RuntimeException('Select a valid return month.');
        $year = (int)$m[1] + ((int)$month < 4 ? 1 : 0);
        return match ($spec['periodFormat']) { 'MMYYYY' => $month.$year, 'YYYYMM' => $year.$month, 'YYYY-MM' => $year.'-'.$month, default => throw new RuntimeException('Monthly period format is not verified.') };
    }

    public function periods(string $type,string $fy,string $month,string $scope): array
    {
        if (!in_array($scope,['monthly','yearly'],true)) throw new RuntimeException('Choose Monthly or Yearly.');
        $months=$scope==='yearly'?['04','05','06','07','08','09','10','11','12','01','02','03']:[$month];
        $periods=[];$today=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata'));
        foreach ($months as $value) {
            $period=$this->period($type,$fy,$value);
            if ($scope==='yearly' && substr($period,2).substr($period,0,2)>$today->format('Ym')) continue;
            $periods[]=$period;
        }
        if (!$periods) throw new RuntimeException('The selected financial year has no available periods yet.');
        return $periods;
    }

    private function at(array $json, string $path): mixed
    {
        $value = $json;
        foreach (explode('.', $path) as $key) { if (!is_array($value) || !array_key_exists($key, $value)) return null; $value=$value[$key]; }
        return $value;
    }

    private function headers(array $credentials): array
    {
        $headers = ['Apikey: '.$credentials['apiKey'], 'Accept: application/json'];
        foreach (($this->contract['connect']['sessionHeaders'] ?? []) as $name => $path) {
            $value = $this->at($credentials['session'] ?? [], $path);
            if (!is_scalar($value) || preg_match('/[\r\n]/', (string)$value) || !preg_match('/^[A-Za-z0-9-]+$/', $name)) throw new RuntimeException('Session header is unavailable. Reconnect GST.');
            $headers[] = $name.': '.$value;
        }
        return $headers;
    }

    private function request(string $method, string $url, array $headers, ?array $body = null, int $limit = 2097152, array $resolve = []): array
    {
        $remaining=$this->store?$this->store->assertWindow($this->owner)-time():35;
        $curl = curl_init($url); $bytes = ''; $overflow = false;
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>35, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_WRITEFUNCTION=>static function($handle,$chunk) use (&$bytes,&$overflow,$limit) { if (strlen($bytes)+strlen($chunk)>$limit) { $overflow=true; return 0; } $bytes.=$chunk; return strlen($chunk); }]);
        if ($resolve) curl_setopt($curl,CURLOPT_RESOLVE,$resolve);
        curl_setopt($curl,CURLOPT_TIMEOUT,max(1,min(35,$remaining)));
        if ($body !== null) { $headers[]='Content-Type: application/json'; curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
        $ok = curl_exec($curl); $code=(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $contentType=(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE); curl_close($curl);
        $this->lastHttpStatus=$code?:null;
        if ($ok === false) throw new RuntimeException($overflow ? 'API response exceeded the size limit.' : 'Network timeout or interruption. The request outcome may be unknown.');
        if ($code < 200 || $code >= 300) {
            $message=match(true) { $code===401||$code===403=>'Primeserve rejected authentication. Check your API key and GST connection.', $code===429=>'Primeserve rate limit reached. Please wait.', $code>=500=>'Primeserve is temporarily unavailable.', default=>'Primeserve rejected the request (HTTP '.$code.').' };
            $detail=$this->safeError($bytes,$headers,$body);
            if ($detail!=='') $message.=' '.$detail;
            throw new ProviderException($message,$code,in_array($code,[400,401,403,404,422],true));
        }
        return ['body'=>$bytes,'contentType'=>$contentType];
    }

    private function safeError(string $body,array $headers,?array $request): string
    {
        $json=json_decode($body,true);if (!is_array($json)) return '';
        $candidate=$json['message']??$json['error_description']??$json['error']['message']??null;
        if (!is_string($candidate)) return '';
        foreach ($headers as $header) if (str_starts_with(strtolower($header),'apikey:')) $candidate=str_replace(trim(substr($header,7)),'[redacted]',$candidate);
        foreach (['Otp','userName','gstin'] as $key) if (!empty($request[$key])) $candidate=str_replace((string)$request[$key],'[redacted]',$candidate);
        $candidate=preg_replace('~https?://\S+|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\b[A-Z0-9]{15}\b|\bPS[A-Za-z0-9]{12,}\b~i','[redacted]',$candidate);
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/',' ',strip_tags($candidate))),0,240);
    }

    private function api(string $section, array $credentials, array $params): array
    {
        $this->validateContract(); $spec=$this->contract[$section];
        $response=$this->request($spec['method'], $this->contract['baseUrl'].$spec['path'].($spec['method']==='GET'?'?'.http_build_query($params):''), in_array($section,['otp','connect','disconnect'],true)?['Apikey: '.$credentials['apiKey']]:$this->headers($credentials), $spec['method']==='GET'?null:$params);
        $json=json_decode($response['body'],true);
        if (!is_array($json)) throw new RuntimeException('Primeserve returned an unexpected response format.');
        if (isset($spec['successPath']) && $this->at($json,$spec['successPath']) !== $spec['successValue']) throw new RuntimeException($section==='connect'?'GST connection was not confirmed. The OTP may be invalid or expired.':'Primeserve did not confirm success. Check your GST details.');
        return $json;
    }

    private function doOtp(array $credentials): void
    {
        if ($this->fixture) return;
        $this->api('otp',$credentials,['userName'=>$credentials['username'],'gstin'=>$credentials['gstin']]);
    }

    private function doConnect(array $credentials, string $otp): array
    {
        if ($this->fixture) {
            if ($otp==='000000') throw new RuntimeException('Fixture OTP expired. Request a new OTP.');
            if ($otp!=='123456') throw new RuntimeException('Invalid fixture OTP. Use 123456 in local test mode.');
            return ['fixture'=>true];
        }
        return $this->api('connect',$credentials,['userName'=>$credentials['username'],'gstin'=>$credentials['gstin'],'Otp'=>$otp,'KeepAliveDays'=>30]);
    }

    private function doInitiate(array $credentials, array $job): string
    {
        if ($this->fixture) {
            if (($credentials['username']??'')==='fixture-failure' && $job['return_type']==='GSTR-2A') throw new RuntimeException('Fixture: return initiation rejected. Other returns continue.');
            return 'LOCAL-'.strtoupper(bin2hex(random_bytes(6)));
        }
        $this->validateContract(); $spec=$this->contract['returns'][$job['return_type']];
        $response=$this->request('GET',$this->contract['baseUrl'].$spec['path'].'?'.http_build_query(['gstin'=>$job['gstin'],'period'=>$job['period']]),$this->headers($credentials));
        $json=json_decode($response['body'],true);
        if (!is_array($json) || (isset($spec['successPath']) && $this->at($json,$spec['successPath']) !== $spec['successValue'])) throw new RuntimeException('Return initiation was not confirmed. Review the provider outcome before retrying.');
        $id=$this->at($json,$spec['jobIdPath']);
        if ((!is_string($id) && !is_int($id)) || (string)$id==='' || strlen((string)$id)>200) throw new RuntimeException('No valid job ID received. Review the provider outcome before retrying.');
        return (string)$id;
    }

    private function doStatus(array $credentials, array $job): array
    {
        if ($this->fixture) {
            if (($credentials['username']??'')==='fixture-timeout') throw new RuntimeException('Fixture transient network interruption.');
            $status=time()-(int)$job['created']>=12?'completed':'processing';
            return ['status'=>$status,'raw'=>$status];
        }
        $json=$this->api('status',$credentials,['jobid'=>$job['job_id']]); $spec=$this->contract['status']; $raw=$this->at($json,$spec['statusPath']);
        $normalized=is_string($raw)&&ctype_digit($raw)?(int)$raw:$raw;
        foreach (['pendingValues'=>'processing','completedValues'=>'completed','failedValues'=>'failed','emptyValues'=>'empty'] as $key=>$status) if (in_array($normalized,$spec[$key],true)) return ['status'=>$status,'raw'=>is_scalar($raw)?(string)$raw:''];
        $safeStatus=is_scalar($raw)&&preg_match('/^[A-Za-z0-9_. -]{1,60}$/',(string)$raw)?(string)$raw:'unrecognized';
        throw new ProviderException('Provider job status: '.$safeStatus.'. This status is not documented; checks use bounded retries without a new return request.',200,false,$safeStatus);
    }

    private function doDownload(array $credentials, array $job): string
    {
        if ($this->fixture) {
            $expires=$this->store?$this->store->assertWindow($this->owner):time()+ReturnStore::RETENTION_SECONDS;
            $path=$this->store?$this->store->temporaryZip($expires):WRITEPATH.'data/ttl-'.$expires.'-'.bin2hex(random_bytes(12)).'.zip';
            try {
                $zip=new ZipArchive(); $zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);
                $zip->addFromString('LOCAL-TEST-ONLY.txt',"LOCAL FIXTURE DATA — NOT A GST RETURN\n".$job['return_type']."\n".$job['gstin']."\n".$job['period']."\n".$job['job_id']); $zip->close();
                return file_get_contents($path);
            } finally { @unlink($path); }
        }
        $this->validateContract(); $spec=$this->contract['download']; $params=[$spec['jobIdParameter']=>$job['job_id']];
        $url=$this->contract['baseUrl'].$spec['path'].($spec['method']==='GET'?'?'.http_build_query($params):'');
        $response=$this->request($spec['method'],$url,$this->headers($credentials),$spec['method']==='POST'?$params:null,41943040);
        if (in_array($spec['responseKind'],['url','jobStatusUrl'],true)) {
            $json=json_decode($response['body'],true); $url=is_array($json)?$this->at($json,$spec['urlPath']):null; $parts=is_string($url)?parse_url($url):false;
            $completion=is_array($json)?$this->at($json,$this->contract['status']['statusPath']):null;
            if (is_string($completion)&&ctype_digit($completion)) $completion=(int)$completion;
            if ($spec['responseKind']==='jobStatusUrl' && (!is_array($json) || !in_array($completion,$this->contract['status']['completedValues'],true))) throw new RuntimeException('The provider has not confirmed that this ZIP is ready.');
            if (!is_string($url)) throw new RuntimeException('Provider did not return a ZIP download URL.');
            $parts=DownloadUrlPolicy::validate($url,$spec['allowedHosts']);
            $addresses=gethostbynamel($parts['host']);
            if (!$addresses) throw new RuntimeException('Download host is unavailable.');
            foreach ($addresses as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Download host is not public.');
            // Pin the verified public address so DNS cannot change between validation and connection.
            $statusHttp=$this->lastHttpStatus;
            $fetchId=$this->store?->auditStart($this->owner,$this->clientIp,'ZIP object fetch',$job['return_type'],'GET','https://'.$parts['host'].'/[signed-object]',$this->currentApiKey);$fetchStarted=microtime(true);
            try {
                $response=$this->request('GET',$url,['Accept: application/zip'],null,41943040,[$parts['host'].':443:'.$addresses[0]]);
                if ($fetchId) $this->store->auditFinish($fetchId,'success',$this->lastHttpStatus,(int)((microtime(true)-$fetchStarted)*1000));
            } catch (\Throwable $e) {
                if ($fetchId) $this->store->auditFinish($fetchId,'failed',$this->lastHttpStatus,(int)((microtime(true)-$fetchStarted)*1000));throw $e;
            } finally { $this->lastHttpStatus=$statusHttp; }
        }
        if (!str_contains(strtolower($response['contentType']),'zip') && !str_contains(strtolower($response['contentType']),'octet-stream')) throw new RuntimeException('Expected a ZIP response. The download may be expired.');
        return $response['body'];
    }
}
