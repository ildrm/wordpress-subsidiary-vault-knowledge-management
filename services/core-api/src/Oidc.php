<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Oidc
{
    public function __construct(private Database $db,private Config $config) {}

    public function discovery(): array
    {
        $issuer=HttpClient::validateUrl($this->config->issuer);
        $d=HttpClient::json('GET',$issuer.'/.well-known/openid-configuration');
        if(($d['issuer']??'')!==$issuer){throw new ApiError(503,'identity_issuer_mismatch');}
        foreach(['authorization_endpoint','token_endpoint','introspection_endpoint','revocation_endpoint'] as $key){
            if(!is_string($d[$key]??null)){throw new ApiError(503,'identity_metadata_invalid');}
            HttpClient::validateUrl($d[$key]);
            $a=parse_url($issuer);$b=parse_url($d[$key]);
            if($a['scheme']!==$b['scheme']||$a['host']!==$b['host']||($a['port']??null)!==($b['port']??null)){throw new ApiError(503,'identity_endpoint_origin_denied');}
        }
        if(!in_array('S256',$d['code_challenge_methods_supported']??[],true)){throw new ApiError(503,'identity_pkce_required');}
        return $d;
    }

    public function configuration(): array
    {
        $d=$this->discovery();$client=getenv('SV_OIDC_WEB_CLIENT_ID')?:'';
        if($client===''){throw new ApiError(503,'identity_web_client_missing');}
        return ['enabled'=>true,'issuer'=>$d['issuer'],'client_id'=>$client,'authorization_endpoint'=>$d['authorization_endpoint'],'token_endpoint'=>$d['token_endpoint'],'revocation_endpoint'=>$d['revocation_endpoint'],'end_session_endpoint'=>$d['end_session_endpoint']??null,'scope'=>'openid profile'];
    }

    public function authenticate(#[\SensitiveParameter] string $token): array
    {
        $hash=hash('sha256',$token);
        if($this->db->one('SELECT hash FROM revoked_access_tokens WHERE hash=? AND expires_at>?',[$hash,time()])){throw new ApiError(401,'revoked_session');}
        $d=$this->discovery();
        $client=Secrets::required('SV_OIDC_INTROSPECTION_CLIENT_ID');$secret=Secrets::required('SV_OIDC_INTROSPECTION_CLIENT_SECRET');
        [$status,$raw]=HttpClient::request('POST',$d['introspection_endpoint'],['Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.base64_encode(rawurlencode($client).':'.rawurlencode($secret))],http_build_query(['token'=>$token,'token_type_hint'=>'access_token']));
        if($status!==200){throw new ApiError(503,'identity_introspection_unavailable');}
        try{$c=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ApiError(503,'identity_invalid_response');}
        if(!is_array($c)){throw new ApiError(503,'identity_invalid_response');}
        $aud=$c['aud']??[];$aud=is_array($aud)?$aud:[$aud];
        $authorizedParty=getenv('SV_OIDC_WEB_CLIENT_ID')?:'';
        if(!is_array($c)||($c['active']??false)!==true||($c['iss']??'')!==$this->config->issuer||$this->config->audience===''||!in_array($this->config->audience,$aud,true)||!is_int($c['exp']??null)||$c['exp']<=time()||!is_string($c['sub']??null)||$c['sub']===''||($c['token_type']??'')!=='Bearer'||$authorizedParty===''||($c['client_id']??$c['azp']??'')!==$authorizedParty){throw new ApiError(401,'invalid_identity_token');}
        $identity=$this->db->one("SELECT e.user_id,u.status FROM external_identities e JOIN users u ON u.id=e.user_id WHERE e.issuer=? AND e.subject=? AND e.status='active'",[$c['iss'],$c['sub']]);
        if(!$identity||$identity['status']!=='active'){throw new ApiError(401,'unprovisioned_identity');}
        $mfa=false;$requiredAcr=getenv('SV_OIDC_MFA_ACR')?:'';$maxAge=(int)(getenv('SV_OIDC_MFA_MAX_AGE')?:300);
        if($requiredAcr!==''&&hash_equals($requiredAcr,(string)($c['acr']??''))&&is_int($c['auth_time']??null)&&$c['auth_time']<=time()&&$c['auth_time']>=time()-min(max($maxAge,30),900)){$mfa=true;}
        return ['id'=>$identity['user_id'],'mfa'=>$mfa,'credential'=>null,'external_hash'=>$hash,'expires_at'=>$c['exp']];
    }
}
