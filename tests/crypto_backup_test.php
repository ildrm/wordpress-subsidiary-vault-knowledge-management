<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
require dirname(__DIR__) . '/apps/trusted-vault-client/FileCipher.php';
use SubsidiaryVault\{Application,Config,Database};
use SubsidiaryVault\LocalClient\FileCipher;
umask(0077);$checks=0;
function ok(bool $v,string $reason):void{global $checks;$checks++;if(!$v){throw new RuntimeException($reason);}}
function fails(Closure $fn):void{try{$fn();ok(false,'expected failure');}catch(RuntimeException $e){ok($e->getMessage()!=='expected failure','failed safely');}}
function command(array $args):string{$p=proc_open($args,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException($out.$err);}return $out;}
$root=sys_get_temp_dir() . '/svkms-recovery-' . bin2hex(random_bytes(8));mkdir($root,0700);
try{
    // Known-answer vector from libsodium's public upstream test/default/aead_xchacha20poly1305.{c,exp}.
    $key=hex2bin('808182838485868788898a8b8c8d8e8f909192939495969798999a9b9c9d9e9f');
    $nonce=hex2bin('07000000404142434445464748494a4b4c4d4e4f50515253');
    $aad=hex2bin('50515253c0c1c2c3c4c5c6c7');
    $plain="Ladies and Gentlemen of the class of '99: If I could offer you only one tip for the future, sunscreen would be it.";
    $expected='f8ebea4875044066fc162a0604e171feecfb3d20425248563bcfd5a155dcc47bbda70b86e5ab9b55002bd1274c02db35321acd7af8b2e2d25015e136b7679458e9f43243bf719d639badb5feac03f80a19a96ef10cb1d15333a837b90946ba3854ee74da3f2585efc7e1e170e17e15e563e77601f4f85cafa8e5877614e143e68420';
    ok(bin2hex(sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain,$aad,$nonce,$key))===$expected,'upstream known-answer vector');
    FileCipher::generateKey($root . '/backup.key');FileCipher::generateKey($root . '/wrong.key');
    foreach([0,1,65535,65536,65537,200000] as $size){
        $input=$root . '/input-' . $size;$cipher=$input . '.sv';$output=$input . '.out';$data=$size===0?'':random_bytes($size);file_put_contents($input,$data);
        FileCipher::transform('encrypt',$input,$cipher,$root . '/backup.key','synthetic-context');
        FileCipher::transform('decrypt',$cipher,$output,$root . '/backup.key','synthetic-context');ok(file_get_contents($output)===$data,'stream roundtrip ' . $size);
        fails(fn()=>FileCipher::transform('decrypt',$cipher,$input . '.wrong-context',$root . '/backup.key','another-context'));
        ok(!file_exists($input . '.wrong-context'),'no partial plaintext after AAD failure');
        fails(fn()=>FileCipher::transform('decrypt',$cipher,$input . '.wrong-key',$root . '/wrong.key','synthetic-context'));
        fails(fn()=>FileCipher::transform('decrypt',$cipher,$output,$root . '/backup.key','synthetic-context'));
        $raw=file_get_contents($cipher);file_put_contents($input . '.truncated',substr($raw,0,-1));
        fails(fn()=>FileCipher::transform('decrypt',$input . '.truncated',$input . '.partial',$root . '/backup.key','synthetic-context'));ok(!file_exists($input . '.partial'),'no plaintext after truncation');
    }
    putenv('SV_ENV=local');putenv('SV_AUTH_MODE=development');putenv('SV_DATA_DIR=' . $root . '/data');putenv('SV_KEY_DIR=' . $root . '/keys');
    command([PHP_BINARY,'tools/vault.php','migrate']);command([PHP_BINARY,'tools/vault.php','seed']);
    command([PHP_BINARY,'tools/worker.php']);$db=new Database($root . '/data/vault.sqlite');
    $n=$db->one('SELECT COUNT(*) AS n FROM notifications')['n'];ok($n>0,'outbox delivered notifications');
    command([PHP_BINARY,'tools/worker.php']);$n2=$db->one('SELECT COUNT(*) AS n FROM notifications')['n'];
    command([PHP_BINARY,'tools/worker.php']);$n3=$db->one('SELECT COUNT(*) AS n FROM notifications')['n'];
    command([PHP_BINARY,'tools/worker.php']);ok($db->one('SELECT COUNT(*) AS n FROM notifications')['n']===$n3,'outbox retry idempotent after drain');
    ok($n2>=$n && $n3>=$n2,'bounded worker progression');
    command([PHP_BINARY,'tools/backup.php',$root . '/backup.key',$root . '/snapshot.svbackup']);
    command([PHP_BINARY,'tools/restore.php',$root . '/backup.key',$root . '/snapshot.svbackup',$root . '/restored']);
    $restored=new Database($root . '/restored/vault.sqlite');ok($restored->one('SELECT COUNT(*) AS n FROM documents')['n']===10,'restored documents');
    ok($restored->one('SELECT COUNT(*) AS n FROM credentials WHERE revoked=0')['n']===0,'old sessions revoked after restore');
    $app=new Application(new Config($root . '/restored',$root . '/keys'));
    $v=$restored->one('SELECT v.*,d.classification FROM versions v JOIN documents d ON d.tenant_id=v.tenant_id AND d.id=v.document_id LIMIT 1');
    $cipher=file_get_contents($root . '/restored/objects/' . $v['object_id'] . '.bin');
    $content=$app->crypto->decrypt($v['tenant_id'],SubsidiaryVault\Crypto::context($v['tenant_id'],$v['document_id'],(int)$v['number'],(int)$v['classification']),$cipher,$v['envelope']);
    ok(hash('sha256',$content)===$v['digest'],'restored object decrypts with separately held original key');
    $missing=new Application(new Config($root . '/restored',$root . '/missing-keys'));
    try{$missing->crypto->decrypt($v['tenant_id'],SubsidiaryVault\Crypto::context($v['tenant_id'],$v['document_id'],(int)$v['number'],(int)$v['classification']),$cipher,$v['envelope']);ok(false,'missing key accepted');}catch(SubsidiaryVault\ApiError $e){ok($e->reason==='key_unavailable','missing keys fail closed');}
    foreach($restored->all('SELECT id FROM tenants') as $tenant){ok($app->audit->verify($tenant['id']),'restored audit chain');}
    // The browser inbox must reevaluate ACL after notification generation.
    $tokens=json_decode(file_get_contents($root . '/data/demo-credentials.json'),true)['tokens'];$live=new Application(new Config($root . '/data',$root . '/keys'));$doc=$db->one("SELECT id FROM documents WHERE tenant_id='tenant-01'")['id'];
    $db->query("INSERT INTO acl(tenant_id,document_id,subject_type,subject_id,action,effect) VALUES('tenant-01',?,'user','tenant-01-viewer','view','deny')",[$doc]);
    [$s,$r]=$live->handle('GET','/v1/tenants/tenant-01/notifications','Bearer ' . $tokens['tenant-01-viewer']);ok($s===200&&$r['items']===[],'inbox suppresses revoked resources');
    echo "Cryptography, worker and isolated recovery: $checks assertions passed.\n";
}finally{
    function clean(string $dir):void{foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir . '/' . $n;if(is_dir($p))clean($p);else @unlink($p);}@rmdir($dir);}clean($root);
}
