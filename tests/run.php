<?php
declare(strict_types=1);
require __DIR__ . '/local_environment.php';
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Application,Config,Crypto,Database,Policy};

umask(0077);
$root=sys_get_temp_dir() . '/svkms-test-' . bin2hex(random_bytes(8));
mkdir($root,0700,true);
putenv('SV_DATA_DIR=' . $root . '/data');putenv('SV_KEY_DIR=' . $root . '/keys');putenv('SV_ENV=local');putenv('SV_AUTH_MODE=development');
function runCommand(array $args): void { $p=proc_open($args,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException($out . $err);} }
runCommand([PHP_BINARY,'tools/vault.php','migrate']);runCommand([PHP_BINARY,'tools/vault.php','seed']);
$app=new Application(new Config());$db=$app->db;
$tokens=json_decode(file_get_contents($root . '/data/demo-credentials.json'),true)['tokens'];
$assertions=0;$cases=[];
function check(bool $condition,string $message):void { global $assertions;$assertions++;if(!$condition){throw new RuntimeException($message);} }
function api(string $user,string $method,string $path,array $body=[],int $expected=200,array $query=[]):array {
    global $app,$tokens;
    [$s,$r]=$app->handle($method,$path,$user===''?'':'Bearer ' . ($tokens[$user]??$user),$body,$query);
    check($s===$expected,"$method $path expected $expected got $s " . json_encode($r));return $r;
}
function test(string $name,Closure $fn):void { global $cases;$fn();$cases[]=$name;echo 'PASS ', $name,"\n"; }
function pathFor(string $tenant,string $doc,string $suffix=''):string {return "/v1/tenants/$tenant/documents/$doc" . ($suffix!==''?'/' . $suffix:'');}
function grant(string $owner,string $tenant,string $doc,string $subject,string $action,string $effect='allow',string $type='user'):void {api($owner,'PUT',pathFor($tenant,$doc,'permissions'),['subject_type'=>$type,'subject_id'=>$subject,'action'=>$action,'effect'=>$effect]);}
$docs=[];for($i=1;$i<=10;$i++){ $t=sprintf('tenant-%02d',$i);$docs[$t]=$db->one('SELECT id FROM documents WHERE tenant_id=?',[$t])['id']; }

test('authentication and development credentials',function(){
    api('','GET','/v1/me',[],401);api(str_repeat('x',64),'GET','/v1/me',[],401);
    $me=api('tenant-01-custodian','GET','/v1/me');check(count($me['tenants'])===1,'membership list');
});
test('all ten tenants reject foreign document references and resource surfaces',function()use($docs,$db){
    foreach($docs as $t=>$id){
        $own=api($t . '-custodian','GET',"/v1/tenants/$t/documents");check(count($own['items'])===1,'own document inventory');
        foreach($docs as $other=>$foreign){
            if($t===$other){continue;}
            foreach(['','versions','versions/1','permissions','comments','holds','workflow'] as $sub){api($t . '-custodian','GET',pathFor($other,$foreign,$sub),[],403);}
            api($t . '-custodian','POST',pathFor($other,$foreign,'versions'),['content_base64'=>base64_encode('attack'),'expected_version'=>1],403);
            api($t . '-custodian','DELETE',pathFor($other,$foreign),[],403);
            api($t . '-custodian','GET',pathFor($t,$foreign),[],404);
        }
        $db->query('DELETE FROM rate_limits');
    }
});
test('holding and subsidiary administration do not grant content access',function()use($docs){
    $posture=api('holding-governor','GET','/v1/posture');check(count($posture['tenants'])===10,'posture count');
    check(!str_contains(json_encode($posture),'Synthetic policy'),'no titles in posture');
    api('holding-governor','GET',pathFor('tenant-01',$docs['tenant-01']),[],403);
    api('tenant-01-administrator','GET',pathFor('tenant-01',$docs['tenant-01']),[],403);
    api('tenant-01-administrator','PUT',pathFor('tenant-01',$docs['tenant-01'],'permissions'),['subject_type'=>'user','subject_id'=>'tenant-01-administrator','action'=>'view','effect'=>'allow'],403);
});
test('view and download are independent; clearance filters search',function()use($docs){
    $path=pathFor('tenant-01',$docs['tenant-01']);api('tenant-01-viewer','GET',$path);
    api('tenant-01-viewer','GET',$path . '/versions/1',[],403);
    api('tenant-01-lowclearance','GET',$path,[],403);
    $r=api('tenant-01-lowclearance','GET','/v1/tenants/tenant-01/documents',[],200,['q'=>'Synthetic']);check($r['items']===[],'search clearance trim');
});
test('explicit deny overrides role grants and revocation is immediate',function()use($docs){
    $t='tenant-01';$id=$docs[$t];$owner=$t . '-custodian';$viewer=$t . '-viewer';
    grant($owner,$t,$id,$viewer,'view','deny');api($viewer,'GET',pathFor($t,$id),[],403);
    $list=api($viewer,'GET',"/v1/tenants/$t/documents");check($list['items']===[],'denied resource absent from list');
    api($owner,'DELETE',pathFor($t,$id,'permissions'),['subject_type'=>'user','subject_id'=>$viewer,'action'=>'view','effect'=>'deny']);
    api($viewer,'GET',pathFor($t,$id));
});
test('encryption uses fresh version keys and authenticates bound context',function()use($docs,$app,$db){
    $t='tenant-02';$id=$docs[$t];$p=pathFor($t,$id);$owner=$t . '-custodian';
    $v1=$db->one('SELECT * FROM versions WHERE tenant_id=? AND document_id=?',[$t,$id]);
    $download=api($owner,'GET',$p . '/versions/1');$plain=base64_decode($download['content_base64']);
    api($owner,'POST',$p . '/versions',['content_base64'=>base64_encode($plain),'expected_version'=>1]);
    $v2=$db->one('SELECT * FROM versions WHERE tenant_id=? AND document_id=? AND number=2',[$t,$id]);
    check($v1['envelope']!==$v2['envelope'],'fresh key envelope');
    $cipher=file_get_contents($app->config->dataDir . '/objects/' . $v1['object_id'] . '.bin');check(!str_contains($cipher,$plain),'encrypted bytes');
    foreach([Crypto::context($t,$id,2,1),Crypto::context($t,$id,1,2),Crypto::context('tenant-03',$id,1,1)] as $aad){try{$app->crypto->decrypt($t,$aad,$cipher,$v1['envelope']);check(false,'AAD accepted');}catch(SubsidiaryVault\ApiError $e){check($e->reason==='integrity_check_failed','integrity error');}}
    $file=$app->config->dataDir . '/objects/' . $v1['object_id'] . '.bin';$broken=$cipher;$broken[30]=chr(ord($broken[30])^1);file_put_contents($file,$broken);
    api($owner,'GET',$p . '/versions/1',[],503);file_put_contents($file,$cipher);
    api($owner,'POST',$p . '/versions',['content_base64'=>base64_encode('stale'),'expected_version'=>1],409);
});
test('workflow binds version and blocks self approval and in-review edits',function()use($docs){
    $t='tenant-03';$p=pathFor($t,$docs[$t]);$owner=$t . '-custodian';
    api($owner,'POST',$p . '/workflow',['decision'=>'submit','expected_version'=>1]);
    api($owner,'POST',$p . '/workflow',['decision'=>'approve','expected_version'=>1],403);
    api($owner,'POST',$p . '/versions',['content_base64'=>base64_encode('mutate review'),'expected_version'=>1],409);
    api($t . '-reviewer','POST',$p . '/workflow',['decision'=>'approve','expected_version'=>1]);
    api($owner,'PATCH',$p,['title'=>'mutate approval','expected_version'=>1],409);
    api($t . '-records','POST',$p . '/record',['retain_until'=>time()+86400]);
    api($owner,'DELETE',$p,[],403);
});
test('holds and retention override every disposition path with dual release',function()use($docs){
    $t='tenant-04';$p=pathFor($t,$docs[$t]);$owner=$t . '-custodian';$legal=$t . '-records';$other=$t . '-records2';
    $hold=api($legal,'POST',$p . '/holds',['reason'=>'Synthetic legal hold']);
    api($owner,'DELETE',$p,[],403);api($owner,'POST',$p . '/disposition',[],403);
    api($legal,'DELETE',$p . '/holds/' . $hold['id'],[],403);
    api($other,'DELETE',$p . '/holds/' . $hold['id']);
    api($owner,'POST',$p . '/retention',['retain_until'=>time()+3600]);
    api($owner,'POST',$p . '/retention',['retain_until'=>0],422);
    api($owner,'DELETE',$p,[],403);
});
test('soft deletion requires separate disposition approval and prevents retrieval',function()use($docs){
    $t='tenant-05';$p=pathFor($t,$docs[$t]);$owner=$t . '-custodian';
    api($owner,'DELETE',$p,[],409);$d=api($owner,'POST',$p . '/disposition');
    api($owner,'POST',$p . '/disposition',['approve_id'=>$d['id']],403);
    api($t . '-records','POST',$p . '/disposition',['approve_id'=>$d['id']]);
    api($owner,'DELETE',$p);api($owner,'GET',$p,[],404);api($owner,'GET',$p . '/versions/1',[],404);
});
test('membership suspension and expiry rechecked on every request',function()use($docs,$db){
    $t='tenant-06';$p=pathFor($t,$docs[$t]);$viewer=$t . '-viewer';$admin=$t . '-administrator';
    api($admin,'PATCH',"/v1/tenants/$t/members/$viewer",['status'=>'suspended']);api($viewer,'GET',$p,[],403);
    api($admin,'PATCH',"/v1/tenants/$t/members/$viewer",['status'=>'active']);api($viewer,'GET',$p);
    $db->query('UPDATE memberships SET expires_at=? WHERE tenant_id=? AND user_id=?',[time()-1,$t,$viewer]);api($viewer,'GET',$p,[],403);
    $db->query('UPDATE memberships SET expires_at=NULL WHERE tenant_id=? AND user_id=?',[$t,$viewer]);
    $db->query("UPDATE tenants SET status='suspended' WHERE id=?",[$t]);api($t . '-custodian','GET',$p,[],403);$db->query("UPDATE tenants SET status='active' WHERE id=?",[$t]);
});
test('one identity keeps independent memberships and tenant roles',function()use($db,$docs){
    $user='tenant-06-viewer';
    $db->query("INSERT INTO memberships(tenant_id,user_id,role_id,clearance) VALUES('tenant-02',?,'viewer',3)",[$user]);
    api($user,'GET',pathFor('tenant-02',$docs['tenant-02']));
    $db->query("UPDATE memberships SET status='suspended' WHERE tenant_id='tenant-06' AND user_id=?",[$user]);
    api($user,'GET',pathFor('tenant-06',$docs['tenant-06']),[],403);
    api($user,'GET',pathFor('tenant-02',$docs['tenant-02']));
    $db->query("UPDATE memberships SET status='active' WHERE tenant_id='tenant-06' AND user_id=?",[$user]);
});
test('privilege escalation, invalid role capabilities and locked floors fail closed',function()use($db){
    $t='tenant-07';$admin=$t . '-administrator';
    api($admin,'PATCH',"/v1/tenants/$t/members/$admin",['clearance'=>3],403);
    api($admin,'PATCH',"/v1/tenants/$t/settings",['encryption'=>false],422);
    api($admin,'POST',"/v1/tenants/$t/roles",['name'=>'Bad role','capabilities'=>['posture']],422);
    $role=api($admin,'POST',"/v1/tenants/$t/roles",['name'=>'Empty role']);
    api($admin,'PATCH',"/v1/tenants/$t/members/$t-contributor",['role_id'=>$role['id']]);
    api($t . '-contributor','GET',"/v1/tenants/$t/documents",[],403);
    $hash=hash('sha256',$GLOBALS['tokens'][$admin]);$db->query('UPDATE credentials SET mfa=0 WHERE hash=?',[$hash]);
    api($admin,'GET',"/v1/tenants/$t/roles",[],403);$db->query('UPDATE credentials SET mfa=1 WHERE hash=?',[$hash]);
});
test('text upload limits, unsafe profiles, malformed content and quotas',function()use($db){
    $t='tenant-08';$owner=$t . '-custodian';$p="/v1/tenants/$t/documents";
    api($owner,'POST',$p,['title'=>'Unsupported','content_base64'=>base64_encode('test'),'mode'=>'zero-knowledge'],409);
    api($owner,'POST',$p,['title'=>'Bad','content_base64'=>'%%%%'],422);
    api($owner,'POST',$p,['title'=>'Bad','content_base64'=>base64_encode("binary\0payload")],415);
    api($owner,'POST',$p,['title'=>'Bad','content_base64'=>base64_encode('EICAR-STANDARD-ANTIVIRUS-TEST-FILE')],422);
    api($owner,'POST',$p,['title'=>'Unsafe','classification'=>4,'content_base64'=>base64_encode('test')],422);
    $db->query('UPDATE tenants SET quota_bytes=1 WHERE id=?',[$t]);api($owner,'POST',$p,['title'=>'Quota','content_base64'=>base64_encode('test')],413);$db->query('UPDATE tenants SET quota_bytes=104857600 WHERE id=?',[$t]);
    check($db->one('SELECT COUNT(*) AS n FROM documents WHERE tenant_id=?',[$t])['n']===1,'failed uploads rollback metadata');
});
test('knowledge revision history comments and bookmarks execute real persistence',function(){
    $t='tenant-09';$owner=$t . '-custodian';$p="/v1/tenants/$t/knowledge";
    $doc=api($owner,'POST',$p,['title'=>'Synthetic wiki','content_base64'=>base64_encode('# Policy article'),'metadata'=>['category'=>'How-to']]);$p.='/' . $doc['id'];
    api($owner,'POST',$p . '/versions',['content_base64'=>base64_encode('# Updated policy'),'expected_version'=>1]);
    api($owner,'POST',$p . '/comments',['body'=>'Review this version','expected_version'=>2]);
    $comments=api($owner,'GET',$p . '/comments');check(count($comments['items'])===1,'comment persisted');
    $versions=api($owner,'GET',$p . '/versions');check(count($versions['items'])===2,'article revisions');
    api($owner,'POST',$p . '/bookmark');api($owner,'DELETE',$p . '/bookmark');
});
test('tenant rates are isolated and rejected requests consume quota',function()use($db,$docs){
    $t='tenant-10';$user=$t . '-viewer';$db->query('INSERT OR REPLACE INTO rate_limits(tenant_id,user_id,window,requests) VALUES(?,?,?,?)',[$t,$user,intdiv(time(),60),119]);
    api($user,'GET',pathFor($t,$docs[$t],'versions/1'),[],403);
    api($user,'GET',pathFor($t,$docs[$t]),[],429);
    api('tenant-01-viewer','GET',pathFor('tenant-01',$docs['tenant-01']));$db->query('DELETE FROM rate_limits');
});
test('version and audit rows reject mutation and chains verify',function()use($db,$app){
    foreach(['UPDATE versions SET digest=?','DELETE FROM versions','UPDATE audit SET reason=?','DELETE FROM audit'] as $sql){
        try{$db->query($sql,str_contains($sql,'?')?['tamper']:[]);check(false,'immutable table modified');}catch(PDOException){check(true,'trigger rejected mutation');}
    }
    foreach($db->all('SELECT id FROM tenants') as $tenant){check($app->audit->verify($tenant['id']),'audit chain ' . $tenant['id']);}
});
test('database preservation guards resist direct record mutation and hold edits',function()use($db,$docs){
    $t='tenant-03';$id=$docs[$t];
    foreach(['UPDATE documents SET title=title WHERE tenant_id=? AND id=?','UPDATE documents SET is_record=0 WHERE tenant_id=? AND id=?','UPDATE documents SET retain_until=0 WHERE tenant_id=? AND id=?','DELETE FROM documents WHERE tenant_id=? AND id=?'] as $sql){
        try{$db->query($sql,[$t,$id]);check(false,'raw record mutation allowed');}catch(PDOException){check(true,'record locked in database');}
    }
    $t='tenant-04';$p=pathFor($t,$docs[$t]);$h=api($t.'-records','POST',$p.'/holds',['reason'=>'Preserve current bytes']);
    api($t.'-custodian','PATCH',$p,['title'=>'alter held draft','expected_version'=>1],409);
    api($t.'-custodian','POST',$p.'/versions',['content_base64'=>base64_encode('alter held bytes'),'expected_version'=>1],409);
    api($t.'-records2','DELETE',$p.'/holds/'.$h['id']);
    $e=$db->one("SELECT * FROM workflows WHERE tenant_id='tenant-03'");check($e['assurance']==='verified-session-mfa' && strlen($e['version_digest'])===64 && $e['policy_version']===1,'approval evidence captured');
    try{$db->query("UPDATE workflows SET decided_by='tenant-03-custodian' WHERE tenant_id='tenant-03'");check(false,'approval evidence modified');}catch(PDOException){check(true,'approval evidence immutable');}
});
test('pinned RS256 identity adapter rejects token confusion and unknown subjects',function()use($root,$tokens){
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$pub=openssl_pkey_get_details($key)['key'];file_put_contents($root.'/oidc.pub',$pub);
    putenv('SV_AUTH_MODE=oidc-rs256');putenv('SV_OIDC_ISSUER=https://identity.example.test');putenv('SV_OIDC_AUDIENCE=svkms-local');putenv('SV_OIDC_PUBLIC_KEY='.$root.'/oidc.pub');
    $jwtApp=new Application(new Config());$enc=fn($data)=>rtrim(strtr(base64_encode(json_encode($data)),'+/','-_'),'=');
    $make=static function(array $claims,string $alg='RS256')use($key,$enc):string{$h=$enc(['alg'=>$alg,'typ'=>'at+jwt']);$p=$enc($claims);openssl_sign($h.'.'.$p,$sig,$key,OPENSSL_ALGO_SHA256);return $h.'.'.$p.'.'.rtrim(strtr(base64_encode($sig),'+/','-_'),'=');};
    $claims=['iss'=>'https://identity.example.test','aud'=>'svkms-local','sub'=>'tenant-01-custodian','exp'=>time()+300];
    [$s,$r]=$jwtApp->handle('GET','/v1/me','Bearer '.$make($claims));check($s===200 && $r['id']==='tenant-01-custodian','known independently signed identity');check($r['mfa']===false,'unconfigured MFA claims cannot grant step-up');
    foreach([['aud'=>'wordpress'],['iss'=>'https://wordpress.example.test'],['sub'=>'wp-admin'],['exp'=>time()-1]] as $override){[$s]=$jwtApp->handle('GET','/v1/me','Bearer '.$make(array_replace($claims,$override)));check($s===401,'invalid issuer/audience/subject/expiry');}
    [$s]=$jwtApp->handle('GET','/v1/me','Bearer '.$make($claims,'HS256'));check($s===401,'algorithm confusion rejected');
    putenv('SV_AUTH_MODE=development');putenv('SV_OIDC_ISSUER');putenv('SV_OIDC_AUDIENCE');putenv('SV_OIDC_PUBLIC_KEY');
});
test('metadata revisions reject lost updates independently of content versions',function()use($docs){
    $p=pathFor('tenant-02',$docs['tenant-02']);$u='tenant-02-custodian';$d=api($u,'GET',$p);
    $r=api($u,'PATCH',$p,['title'=>'Updated metadata','expected_version'=>$d['current_version'],'expected_metadata_revision'=>$d['metadata_revision']]);
    check($r['metadata_revision']===$d['metadata_revision']+1,'metadata revision increment');
    api($u,'PATCH',$p,['title'=>'Stale metadata overwrite','expected_version'=>$d['current_version'],'expected_metadata_revision'=>$d['metadata_revision']],409);
    $r2=api($u,'GET',$p);check($r2['title']==='Updated metadata','lost update rejected');
});
test('governance provisioning is idempotent and suspension preserves records',function()use($docs,$db){
    $body=['name'=>'New synthetic subsidiary','region'=>'local','owner_id'=>'tenant-01-administrator','idempotency_key'=>'test-provision-001'];
    $first=api('holding-governor','POST','/v1/tenants',$body);$again=api('holding-governor','POST','/v1/tenants',$body);
    check($first['id']===$again['id'] && $again['replayed']===true,'provision retry returns same tenant');
    api('holding-governor','POST','/v1/tenants',array_replace($body,['name'=>'Changed request']),409);
    $p=pathFor('tenant-03',$docs['tenant-03']);
    api('tenant-01-administrator','PATCH','/v1/governance/tenants/tenant-03',['status'=>'suspended'],403);
    api('holding-governor','PATCH','/v1/governance/tenants/tenant-03',['status'=>'suspended']);
    api('tenant-03-custodian','GET',$p,[],403);
    check($db->one('SELECT is_record FROM documents WHERE tenant_id=? AND id=?',['tenant-03',$docs['tenant-03']])['is_record']===1,'record preserved');
    api('holding-governor','PATCH','/v1/governance/tenants/tenant-03',['status'=>'active']);
    api('tenant-03-custodian','GET',$p);
    api('holding-governor','PATCH','/v1/governance/tenants/tenant-03',['status'=>'closed'],409);
});
test('session revocation is effective on subsequent requests',function(){api('tenant-10-contributor','DELETE','/v1/session');api('tenant-10-contributor','GET','/v1/me',[],401);});

echo count($cases),' test groups; ',$assertions," assertions passed.\n";
$report=['test_groups'=>count($cases),'assertions'=>$assertions,'cases'=>$cases,'profile'=>'local SQLite / PHP Sodium; internal testing only'];
if(getenv('SV_TEST_REPORT')){file_put_contents(getenv('SV_TEST_REPORT'),json_encode($report,JSON_PRETTY_PRINT) . "\n");}
function removeTree(string $dir):void{foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir . '/' . $n;if(is_dir($p))removeTree($p);else unlink($p);}rmdir($dir);}
removeTree($root);
