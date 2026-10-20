<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Application,Config};
$tokens=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);$app=new Application(new Config());$checks=0;
function ok(bool $x,string $reason):void{global $checks;$checks++;if(!$x){throw new RuntimeException($reason);}}
$auth='Bearer '.$tokens['access_token'];
[$s,$me]=$app->handle('GET','/v1/me',$auth);ok($s===200&&($me['id']??'')==='tenant-01-custodian','real introspected independent identity: HTTP '.$s.' '.json_encode($me));ok($me['mfa']===false,'unapproved MFA claim is not elevated');
[$s]=$app->handle('GET','/v1/tenants/tenant-02/documents',$auth);ok($s===403,'federated identity cannot change tenant scope');
[$s]=$app->handle('GET','/v1/tenants/tenant-01/roles',$auth);ok($s===403,'privileged actions fail closed without approved MFA');
$config=$app->handle('GET','/v1/auth/config')[1];ok($config['enabled']&&$config['issuer']===(getenv('SV_OIDC_ISSUER')),'PKCE public config');ok(!str_contains(json_encode($config),getenv('SV_OIDC_INTROSPECTION_CLIENT_SECRET')),'no verifier secret exposed');
$app->db->begin();$app->db->context('tenant-01-custodian','tenant-01');$app->db->query("UPDATE memberships SET status='suspended' WHERE tenant_id='tenant-01' AND user_id='tenant-01-custodian'");$app->db->commit();
[$s]=$app->handle('GET','/v1/tenants/tenant-01/documents',$auth);ok($s===403,'membership revocation independent from IdP session');
$app->db->begin();$app->db->context('tenant-01-custodian','tenant-01');$app->db->query("UPDATE memberships SET status='active' WHERE tenant_id='tenant-01' AND user_id='tenant-01-custodian'");$app->db->commit();
[$s]=$app->handle('DELETE','/v1/session',$auth);ok($s===200,'local access session logout');[$s]=$app->handle('GET','/v1/me',$auth);ok($s===401,'logged out access token denied immediately');
[$s]=$app->handle('GET','/v1/me','Bearer '.str_repeat('invalid',20));ok($s===401,'unrecognized provider token rejected');
echo "Live introspection/API: $checks assertions passed.\n";
