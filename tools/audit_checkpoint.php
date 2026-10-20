<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Application,AuditCheckpoint,Config,Database};
umask(0077);$op=$argv[1]??'';
if(!in_array($op,['create','verify'],true)||count($argv)!==5){fwrite(STDERR,"Usage:\n php tools/audit_checkpoint.php create TENANT SIGNER_SECRET_KEY NEW_CHECKPOINT_FILE\n php tools/audit_checkpoint.php verify TENANT TRUSTED_PUBLIC_KEY TRUSTED_LATEST_CHECKPOINT\nSigning keys/latest checkpoint custody must be independent of the application and database.\n");exit(2);}
$tenant=Application::id($argv[2]);$config=new Config();$db=new Database($config->database);$key=file_get_contents($argv[3]);
$db->begin();$db->context('independent-audit-exporter',$tenant);
try{
 if($op==='create'){
  $checkpoint=AuditCheckpoint::create($db,$tenant,$key);$h=fopen($argv[4],'x');if(!$h){throw new RuntimeException('checkpoint_must_not_exist');}
  try{chmod($argv[4],0600);$data=json_encode($checkpoint,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";if(fwrite($h,$data)!==strlen($data)||!fflush($h)||(function_exists('fsync')&&!fsync($h))){throw new RuntimeException('checkpoint_write_failed');}}finally{fclose($h);}
  $db->commit();echo "Signed checkpoint created. Store latest checkpoint and public key outside application custody.\n";
 }else{
  $checkpoint=json_decode(file_get_contents($argv[4]),true,16,JSON_THROW_ON_ERROR);$valid=AuditCheckpoint::verify($db,$tenant,$checkpoint,$key);$db->commit();echo $valid?"Trusted checkpoint matches current audit prefix.\n":"CHECKPOINT OR AUDIT INVALID.\n";exit($valid?0:1);
 }
}catch(Throwable $e){$db->rollback();throw $e;}finally{if(is_string($key)){sodium_memzero($key);}}
