<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Application,Config,Database};
umask(0077);$app=new Application(new Config());$db=$app->db;$op=$argv[1]??'';
if($op==='create'&&count($argv)===4){
 $id=Application::id($argv[2]);$name=Application::text(['name'=>$argv[3]],'name',120);
 $db->begin();try{$db->query('INSERT INTO users(id,name) VALUES(?,?)',[$id,$name]);$db->context('operator','holding');$app->audit->append('holding','operator','identity.created',$id,'allowed','privileged_cli',Database::id());$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
 echo "Created identity without memberships or document grants.\n";exit;
}
if($op==='bind'&&count($argv)===5){
 $issuer=\SubsidiaryVault\HttpClient::validateUrl($argv[2]);$subject=$argv[3];$user=Application::id($argv[4]);
 if(strlen($subject)<1||strlen($subject)>255){throw new RuntimeException('invalid_subject');}
 $db->begin();try{
  if(!$db->one("SELECT id FROM users WHERE id=? AND status='active'",[$user])){throw new RuntimeException('unknown_user');}
  $db->query('INSERT INTO external_identities(issuer,subject,user_id,created_at) VALUES(?,?,?,?)',[$issuer,$subject,$user,time()]);
  $db->context('operator','holding');$app->audit->append('holding','operator','identity.bound',$user,'allowed','privileged_cli',Database::id());$db->commit();
 }catch(Throwable $e){$db->rollback();throw $e;}
 echo "Bound exact independent issuer/subject to existing identity; no new entitlements granted.\n";exit;
}
fwrite(STDERR,"Privileged operator commands (use dedicated migration/provisioning DB credentials):\n php tools/identity.php create USER_ID NAME\n php tools/identity.php bind EXACT_ISSUER EXACT_SUBJECT EXISTING_USER_ID\nEmail and WordPress identities are never automatically linked.\n");exit(2);
