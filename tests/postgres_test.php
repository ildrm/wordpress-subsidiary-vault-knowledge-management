<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Application,Config};
$app=new Application(new Config());$db=$app->db;$checks=0;
$tokens=json_decode(file_get_contents($app->config->dataDir.'/demo-credentials.json'),true)['tokens'];
function ok(bool $condition,string $reason):void{global $checks;$checks++;if(!$condition){throw new RuntimeException($reason);}}
function callApi(string $user,string $method,string $path,array $body=[],int $expected=200):array{global $app,$tokens;[$s,$r]=$app->handle($method,$path,'Bearer '.$tokens[$user],$body);ok($s===$expected,"$path: expected $expected got $s ".json_encode($r));return $r;}
$role=$db->one('SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname=current_user');ok(!$role['rolsuper']&&!$role['rolbypassrls'],'runtime must not bypass RLS');
ok($db->one('SELECT count(*) AS n FROM documents')['n']===0,'no context means no document rows');
$docs=[];
for($i=1;$i<=10;$i++){
 $t=sprintf('tenant-%02d',$i);$u=$t.'-custodian';$rows=callApi($u,'GET',"/v1/tenants/$t/documents");ok(count($rows['items'])===1,'tenant inventory');$docs[$t]=$rows['items'][0]['id'];
 $db->begin();$db->context($u,$t);$raw=$db->all('SELECT * FROM documents');ok(count($raw)===1&&$raw[0]['tenant_id']===$t,'RLS protects query missing tenant filter');$db->commit();
 ok($db->one('SELECT count(*) AS n FROM documents')['n']===0,'transaction-local tenant context does not leak to next request');
}
foreach($docs as $tenant=>$id){foreach($docs as $foreign=>$foreignId){if($tenant!==$foreign){callApi($tenant.'-custodian','GET',"/v1/tenants/$foreign/documents/$foreignId",[],403);}}}
$t='tenant-01';$id=$docs[$t];$p="/v1/tenants/$t/documents/$id";$owner=$t.'-custodian';
callApi($owner,'POST',$p.'/versions',['expected_version'=>1,'content_base64'=>base64_encode('PostgreSQL integration version')]);
$r=callApi($owner,'GET',$p.'/versions/2');ok(base64_decode($r['content_base64'])==='PostgreSQL integration version','Postgres byte roundtrip');
callApi($owner,'POST',$p.'/workflow',['expected_version'=>2]);callApi($owner,'POST',$p.'/workflow',['expected_version'=>2,'decision'=>'approve'],403);
callApi($t.'-reviewer','POST',$p.'/workflow',['expected_version'=>2,'decision'=>'approve']);callApi($t.'-records','POST',$p.'/record');
callApi($owner,'DELETE',$p,[],403);
$db->begin();$db->context($owner,$t);try{$db->query('UPDATE documents SET title=? WHERE tenant_id=? AND id=?',['tamper',$t,$id]);ok(false,'record overwrite accepted');}catch(PDOException){ok(true,'record storage guard');}$db->rollback();
$db->begin();$db->context($owner,$t);try{$db->query('INSERT INTO departments(tenant_id,id,name) VALUES(?,?,?)',['tenant-02','invalid-scope','foreign']);ok(false,'cross-tenant write accepted');}catch(PDOException){ok(true,'RLS write guard');}$db->rollback();
$posture=callApi('holding-governor','GET','/v1/posture');ok(count($posture['tenants'])===10,'aggregate function permits governance only');
callApi('tenant-01-administrator','GET',"/v1/tenants/$t/documents/$id",[],403);
$db->begin();$db->context($owner,$t);ok($app->audit->verify($t),'Postgres audit chain');$db->commit();
echo "Live PostgreSQL RLS/API: $checks assertions passed.\n";
