<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class ObjectStore
{
    public function __construct(private Config $config) {}
    private function id(string $id): void { if(!preg_match('/^[a-f0-9]{32}$/D',$id)){throw new ApiError(503,'invalid_object_reference');} }
    public function localPath(string $id): string
    {
        $this->id($id);$dir=$this->config->dataDir.'/objects';if(!is_dir($dir)){mkdir($dir,0700,true);}return $dir.'/'.$id.'.bin';
    }
    public function put(string $tenant,string $id,string $ciphertext): void
    {
        $this->id($id);
        if(getenv('SV_OBJECT_PROVIDER')==='s3'){$this->request('PUT',$tenant,$id,$ciphertext);return;}
        $path=$this->localPath($id);$f=fopen($path,'x');if(!$f){throw new ApiError(503,'storage_unavailable');}chmod($path,0600);
        try{$offset=0;while($offset<strlen($ciphertext)){$n=fwrite($f,substr($ciphertext,$offset));if(!$n){throw new ApiError(503,'storage_unavailable');}$offset+=$n;}if(!fflush($f)||(function_exists('fsync')&&!fsync($f))){throw new ApiError(503,'storage_unavailable');}}
        catch(\Throwable $e){@unlink($path);throw $e;}finally{fclose($f);}
    }
    public function get(string $tenant,string $id): string
    {
        $this->id($id);
        if(getenv('SV_OBJECT_PROVIDER')==='s3'){return $this->request('GET',$tenant,$id);}
        $bytes=@file_get_contents($this->localPath($id));if($bytes===false){throw new ApiError(503,'object_unavailable');}return $bytes;
    }
    public function rollbackLocal(string $id): void
    {
        // S3 objects have no application deletion permission. Crash/rollback orphans remain encrypted.
        if(getenv('SV_OBJECT_PROVIDER')!=='s3'){@unlink($this->localPath($id));}
    }
    public static function objectKey(string $tenant,string $id): string { return 'tenants/'.hash('sha256',$tenant).'/objects/'.$id.'.bin'; }
    private function request(string $method,string $tenant,string $id,?string $body=null): string
    {
        $endpoint=HttpClient::validateUrl(getenv('SV_S3_ENDPOINT')?:'');$bucket=getenv('SV_S3_BUCKET')?:'';$region=getenv('SV_S3_REGION')?:'';
        if(!preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/D',$bucket)||!preg_match('/^[a-z0-9-]{1,40}$/D',$region)){throw new ApiError(503,'object_configuration_invalid');}
        $url=$endpoint.'/'.$bucket.'/'.self::objectKey($tenant,$id);$h=curl_init($url);$response='';
        $headers=['x-amz-content-sha256: '.hash('sha256',$body??''),'Content-Type: application/octet-stream'];
        if($method==='PUT'){$headers[]='If-None-Match: *';}
        curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_AWS_SIGV4=>'aws:amz:'.$region.':s3',CURLOPT_USERPWD=>Secrets::required('SV_S3_ACCESS_KEY').':'.Secrets::required('SV_S3_SECRET_KEY'),CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($curl,string $bytes) use (&$response):int{if(strlen($response)+strlen($bytes)>Config::MAX_FILE_BYTES+65536){return 0;}$response.=$bytes;return strlen($bytes);}]);
        if($body!==null){curl_setopt($h,CURLOPT_POSTFIELDS,$body);}
        $ok=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
        if($ok===false||$status<200||$status>=300){
            preg_match('~<Code>([A-Za-z0-9_]+)</Code>~',$response,$error);
            error_log('svkms_object_failure status='.$status.' code='.($error[1]??'transport').' errno='.curl_errno($h));
            throw new ApiError(503,$status===412?'immutable_object_exists':'object_store_unavailable');
        }return $response;
    }
}
