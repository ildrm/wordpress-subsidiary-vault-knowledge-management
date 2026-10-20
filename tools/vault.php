<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';

use SubsidiaryVault\{Application,Config,Database,Policy};

umask(0077);
$command=$argv[1]??'help';
$config=new Config();
$db=new Database($config->database);
if ($command==='migrate') {
    $current=\SubsidiaryVault\Migrations::run($db);
    echo "Schema at version $current.\n";exit;
}
if ($command==='seed') {
    if (!in_array($config->environment,['local','integration'],true)) { throw new RuntimeException('seed_forbidden_outside_test_environment'); }
    if ($config->authMode!=='development') { throw new RuntimeException('seed_requires_development_auth'); }
    if ($db->one('SELECT id FROM tenants LIMIT 1')) { echo "Seed already exists; no identities, tokens or keys replaced.\n"; exit; }
    $app=new Application($config); $tokens=[]; $db=$app->db;
    $db->begin();
    try {
        $db->query('INSERT INTO tenants(id,name,region,created_at) VALUES(?,?,?,?)',['holding','Holding governance','local',time()]);
        $db->query('INSERT INTO users(id,name) VALUES(?,?)',['holding-governor','Holding governance administrator']);
        $db->query('INSERT INTO roles(tenant_id,id,name,capabilities) VALUES(?,?,?,?)',['holding','governor','Holding governance',json_encode(['posture','tenants'])]);
        $db->query('INSERT INTO memberships(tenant_id,user_id,role_id,clearance) VALUES(?,?,?,?)',['holding','holding-governor','governor',0]);
        $issue=static function(string $user,bool $mfa=true) use ($db,&$tokens):void {
            $token=bin2hex(random_bytes(32)); $tokens[$user]=$token;
            $db->query('INSERT INTO credentials(hash,user_id,expires_at,mfa) VALUES(?,?,?,?)',[hash('sha256',$token),$user,time()+28800,(int)$mfa]);
        };
        $issue('holding-governor');
        for($i=1;$i<=10;$i++) {
            $t=sprintf('tenant-%02d',$i);
            $db->query('INSERT INTO tenants(id,name,parent_id,region,settings,created_at) VALUES(?,?,?,?,?,?)',[$t,sprintf('Subsidiary %02d',$i),'holding','local',json_encode(['locale'=>$i===2?'fa':'en']),time()]);
            $roles=[
                'administrator'=>Policy::CAPABILITIES,
                'custodian'=>array_merge(['create'],Policy::DOCUMENT_ACTIONS),
                'reviewer'=>['view','download','comment','approve'],
                'records'=>['view','download','records','hold','delete','audit'],
                'viewer'=>['view'],
                'contributor'=>['create','view','download','edit','comment'],
            ];
            foreach($roles as $role=>$caps) { $db->query('INSERT INTO roles(tenant_id,id,name,capabilities) VALUES(?,?,?,?)',[$t,$role,ucfirst($role),json_encode($caps)]); }
            foreach(['administrator'=>'administrator','custodian'=>'custodian','reviewer'=>'reviewer','records'=>'records','records2'=>'records','viewer'=>'viewer','contributor'=>'contributor','lowclearance'=>'viewer'] as $suffix=>$role) {
                $user=$t . '-' . $suffix;
                $db->query('INSERT INTO users(id,name) VALUES(?,?)',[$user,ucfirst($suffix) . ' · ' . sprintf('%02d',$i)]);
                $db->query('INSERT INTO memberships(tenant_id,user_id,role_id,clearance) VALUES(?,?,?,?)',[$t,$user,$role,$suffix==='lowclearance'?0:3]);
                $issue($user,!in_array($role,['viewer','contributor'],true));
            }
            $app->crypto->provision($t);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    $file=$config->dataDir . '/demo-credentials.json';
    file_put_contents($file,json_encode(['expires_at'=>gmdate('c',time()+28800),'tokens'=>$tokens],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n"); chmod($file,0600);
    for($i=1;$i<=10;$i++) {
        $t=sprintf('tenant-%02d',$i); $auth='Bearer ' . $tokens[$t . '-custodian'];
        [$status,$doc]=$app->handle('POST','/v1/tenants/' . $t . '/documents',$auth,['title'=>'Synthetic policy · ' . $t,'classification'=>1,'metadata'=>['category'=>'Policy','language'=>'en'],'content_base64'=>base64_encode("Synthetic development data for $t.\nNo real company data.\n")]);
        if($status!==200) { throw new RuntimeException('seed_document_failed'); }
        foreach(['reviewer'=>['view','download','comment','approve'],'records'=>['view','download','records','hold','delete','audit'],'viewer'=>['view']] as $role=>$actions) {
            foreach($actions as $action) {
                [$s,$r]=$app->handle('PUT',"/v1/tenants/$t/documents/{$doc['id']}/permissions",$auth,['subject_type'=>'role','subject_id'=>$role,'action'=>$action,'effect'=>'allow']);
                if($s!==200) { throw new RuntimeException('seed_acl_failed: ' . json_encode($r)); }
            }
        }
    }
    echo "Seeded 10 subsidiaries, 81 independent identities, 10 encrypted text documents.\nDevelopment tokens (8 hour expiry) written to private data directory.\n"; exit;
}
if ($command==='token') {
    $user=$argv[2]??'';
    $tokens=json_decode(file_get_contents($config->dataDir . '/demo-credentials.json'),true,16,JSON_THROW_ON_ERROR);
    if(!isset($tokens['tokens'][$user])) { fwrite(STDERR,"Unknown development identity.\n"); exit(1); }
    echo $tokens['tokens'][$user],"\n"; exit;
}
if ($command==='rotate-demo-tokens') {
    if ($config->authMode!=='development') { throw new RuntimeException('development_only'); }
    $tokens=[]; $db->begin();
    try {
        $db->query('UPDATE credentials SET revoked=1');
        foreach($db->all("SELECT id FROM users WHERE status='active'") as $user) {
            $token=bin2hex(random_bytes(32));$tokens[$user['id']]=$token;
            $mfa=!preg_match('/-(viewer|contributor|lowclearance)$/',$user['id']);
            $db->query('INSERT INTO credentials(hash,user_id,expires_at,mfa) VALUES(?,?,?,?)',[hash('sha256',$token),$user['id'],time()+28800,(int)$mfa]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    file_put_contents($config->dataDir . '/demo-credentials.json',json_encode(['expires_at'=>gmdate('c',time()+28800),'tokens'=>$tokens],JSON_PRETTY_PRINT));
    echo "Replaced demo credentials; all previous sessions revoked.\n";exit;
}
if($command==='audit') {
    $app=new Application($config);$ok=true;
    foreach($db->all('SELECT id FROM tenants') as $tenant) { $valid=$app->audit->verify($tenant['id']);echo $tenant['id'],': ', $valid?'valid':'INVALID',"\n";$ok=$ok&&$valid; }
    exit($ok?0:1);
}
fwrite(STDOUT,"SubsidiaryVault local development commands:\n  php tools/vault.php migrate\n  php tools/vault.php seed\n  php tools/vault.php token tenant-01-custodian\n  php tools/vault.php rotate-demo-tokens\n  php tools/vault.php audit\n");
