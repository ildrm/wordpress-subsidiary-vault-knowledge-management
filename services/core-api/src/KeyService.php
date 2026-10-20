<?php
declare(strict_types=1);
namespace SubsidiaryVault;

/** OpenBao transit wraps only random data keys. Tenant root key bytes never leave the key service. */
final class KeyService
{
    private string $url;
    public function __construct()
    {
        $this->url=HttpClient::validateUrl(getenv('SV_KEY_SERVICE_URL')?:'');
    }
    public static function name(string $tenant): string { return 'svkms-'.hash('sha256',$tenant); }
    private function request(string $operation,string $tenant,?array $body=null): array
    {
        try{return HttpClient::json($body===null?'GET':'POST',$this->url.'/v1/transit/'.$operation.'/'.self::name($tenant),['X-Vault-Token: '.Secrets::required('SV_KEY_SERVICE_TOKEN')],$body);}
        catch(\Throwable){throw new ApiError(503,'key_service_unavailable');}
    }
    public function verify(string $tenant): void
    {
        $data=$this->request('keys',$tenant)['data']??[];
        if(($data['type']??'')!=='aes256-gcm96'||($data['exportable']??true)!==false||($data['allow_plaintext_backup']??true)!==false||($data['deletion_allowed']??true)!==false){throw new ApiError(503,'key_policy_invalid');}
    }
    public function wrap(string $tenant,string $context,#[\SensitiveParameter] string $key): string
    {
        $this->verify($tenant);
        $result=$this->request('encrypt',$tenant,['plaintext'=>base64_encode($key),'associated_data'=>base64_encode('wrap:'.$context)]);
        $wrapped=$result['data']['ciphertext']??null;
        if(!is_string($wrapped)||!preg_match('/^vault:v[1-9][0-9]*:[A-Za-z0-9+\/=]+$/D',$wrapped)){throw new ApiError(503,'key_service_invalid_response');}return $wrapped;
    }
    public function unwrap(string $tenant,string $context,string $wrapped): string
    {
        $this->verify($tenant);
        $r=$this->request('decrypt',$tenant,['ciphertext'=>$wrapped,'associated_data'=>base64_encode('wrap:'.$context)]);
        $key=base64_decode($r['data']['plaintext']??'',true);
        if($key===false||strlen($key)!==32){throw new ApiError(503,'key_service_invalid_response');}return $key;
    }
}
