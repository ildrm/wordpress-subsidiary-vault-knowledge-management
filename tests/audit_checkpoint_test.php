<?php
declare(strict_types=1);
require __DIR__.'/local_environment.php';
require dirname(__DIR__).'/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Audit,AuditCheckpoint,Database};
$db=new Database(':memory:');$db->pdo->exec('CREATE TABLE tenants(id TEXT PRIMARY KEY);INSERT INTO tenants VALUES ("tenant-01"),("tenant-02");CREATE TABLE audit(sequence INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id TEXT,actor_id TEXT,action TEXT,resource_id TEXT,outcome TEXT,reason TEXT,at INTEGER,request_id TEXT,previous_hash TEXT,hash TEXT)');
$audit=new Audit($db);$checks=0;function good(bool $condition,string $reason):void{global $checks;$checks++;if(!$condition){throw new RuntimeException($reason);}}
$keypair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keypair);$public=sodium_crypto_sign_publickey($keypair);
$audit->append('tenant-01','synthetic','created','doc-1','allowed','policy','request-1');$audit->append('tenant-01','synthetic','viewed','doc-1','allowed','policy','request-2');
$cp=AuditCheckpoint::create($db,'tenant-01',$secret);good(AuditCheckpoint::verify($db,'tenant-01',$cp,$public),'authentic external checkpoint');
$audit->append('tenant-01','synthetic','viewed','doc-1','allowed','policy','request-3');good(AuditCheckpoint::verify($db,'tenant-01',$cp,$public),'permitted later append');
good(!AuditCheckpoint::verify($db,'tenant-02',$cp,$public),'checkpoint cannot move across tenants');
$tampered=$cp;$tampered['payload']=base64_encode(str_replace('doc','forged',base64_decode($cp['payload'])).' ');good(!AuditCheckpoint::verify($db,'tenant-01',$tampered,$public),'signed payload alteration fails');
$wrong=sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());good(!AuditCheckpoint::verify($db,'tenant-01',$cp,$wrong),'wrong signer denied');
$db->query('DELETE FROM audit WHERE sequence>=2');good($audit->verify('tenant-01'),'truncated local chain can appear valid');good(!AuditCheckpoint::verify($db,'tenant-01',$cp,$public),'external checkpoint detects suffix truncation');
$db->query('DELETE FROM audit');$audit->append('tenant-01','attacker','rewritten','doc-1','allowed','policy','forged');good($audit->verify('tenant-01'),'rewritten local chain can appear valid');good(!AuditCheckpoint::verify($db,'tenant-01',$cp,$public),'external checkpoint detects rewritten chain');
echo "Independent audit checkpoint binding: $checks assertions passed.\n";
