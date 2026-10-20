<?php
declare(strict_types=1);
namespace SubsidiaryVault;

/** Bounded TLS client. Configured service URLs only; never follows redirects. */
final class HttpClient
{
    public static function validateUrl(string $url): string
    {
        $p=parse_url($url);$test=in_array(getenv('SV_ENV')?:'local',['local','integration'],true)&&getenv('SV_ALLOW_INSECURE_TEST_ENDPOINTS')==='1';
        if (!$p || preg_match('/[\x00-\x20\x7f]/',$url) || !isset($p['scheme'],$p['host']) || !preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D',$p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || ($p['scheme']!=='https' && !($test && $p['scheme']==='http'))) { throw new \RuntimeException('https_service_url_required'); }
        return rtrim($url,'/');
    }
    public static function origin(string $url): string
    {
        self::validateUrl($url);$p=parse_url($url);return $p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
    }
    public static function request(string $method,string $url,array $headers=[],?string $body=null,int $limit=1048576): array
    {
        self::validateUrl($url);$h=curl_init($url);$response='';
        curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS_STR=>'https,http',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$response,$limit):int { if(strlen($response)+strlen($chunk)>$limit){return 0;}$response.=$chunk;return strlen($chunk); }]);
        if($body!==null){curl_setopt($h,CURLOPT_POSTFIELDS,$body);}
        $result=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
        if($result===false||$status<100){throw new ApiError(503,'upstream_unavailable');}
        return [$status,$response];
    }
    public static function json(string $method,string $url,array $headers=[],?array $body=null): array
    {
        [$status,$raw]=self::request($method,$url,[...$headers,'Content-Type: application/json'],$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
        if($status<200||$status>=300){throw new ApiError(503,'upstream_rejected');}
        try{$json=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ApiError(503,'upstream_invalid_response');}
        if(!is_array($json)){throw new ApiError(503,'upstream_invalid_response');}return $json;
    }
}
