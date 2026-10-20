<?php
declare(strict_types=1);
namespace SubsidiaryVault;

/** Detached checkpoints require a trusted latest copy and signing custody outside the application. */
final class AuditCheckpoint
{
    public static function create(Database $db,string $tenant,#[\SensitiveParameter] string $secretKey): array
    {
        if(strlen($secretKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES){throw new \RuntimeException('invalid_signing_key');}
        if(!(new Audit($db))->verify($tenant)){throw new \RuntimeException('audit_chain_invalid');}
        $head=$db->one('SELECT sequence,hash FROM audit WHERE tenant_id=? ORDER BY sequence DESC LIMIT 1',[$tenant]);
        $payload=json_encode(['format'=>'svkms-audit-checkpoint-v1','tenant'=>$tenant,'sequence'=>(int)($head['sequence']??0),'hash'=>$head['hash']??str_repeat('0',64),'created_at'=>time()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        return ['payload'=>base64_encode($payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload,$secretKey))];
    }
    public static function verify(Database $db,string $tenant,array $checkpoint,string $publicKey): bool
    {
        try{
            if(strlen($publicKey)!==32){return false;}
            $payload=base64_decode($checkpoint['payload']??'',true);$signature=base64_decode($checkpoint['signature']??'',true);
            if($payload===false||$signature===false||strlen($signature)!==64||!sodium_crypto_sign_verify_detached($signature,$payload,$publicKey)){return false;}
            $data=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
            if(($data['format']??'')!=='svkms-audit-checkpoint-v1'||($data['tenant']??'')!==$tenant||!is_int($data['sequence']??null)||$data['sequence']<0||!is_int($data['created_at']??null)||$data['created_at']>time()+30||!preg_match('/^[a-f0-9]{64}$/D',$data['hash']??'')){return false;}
            if(!(new Audit($db))->verify($tenant)){return false;}
            if($data['sequence']===0){return hash_equals(str_repeat('0',64),$data['hash']);}
            $anchor=$db->one('SELECT hash FROM audit WHERE tenant_id=? AND sequence=?',[$tenant,$data['sequence']]);
            return $anchor!==null&&hash_equals($anchor['hash'],$data['hash']);
        }catch(\Throwable){return false;}
    }
}
