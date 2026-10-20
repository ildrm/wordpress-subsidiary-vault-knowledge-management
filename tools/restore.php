<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
require dirname(__DIR__) . '/apps/trusted-vault-client/FileCipher.php';
use SubsidiaryVault\Database;
use SubsidiaryVault\LocalClient\FileCipher;
umask(0077);
if(count($argv)!==4){fwrite(STDERR,"Usage: php tools/restore.php BACKUP_ENCRYPTION_KEY INPUT.svbackup NEW_ISOLATED_DIRECTORY\nNever reconnect a restored snapshot before reconciling current identity, ACL and preservation policy.\n");exit(2);}
if(file_exists($argv[3])||is_link($argv[3])){throw new RuntimeException('restore_destination_must_not_exist');}
$work=sys_get_temp_dir() . '/svkms-restore-' . Database::id();mkdir($work,0700);
$created=false;$success=false;
try{
    FileCipher::transform('decrypt',$argv[2],$work . '/snapshot.tar',$argv[1],'svkms-local-backup-v1');
    $tar=new PharData($work . '/snapshot.tar');
    $manifest=json_decode($tar['manifest.json']->getContent(),true,64,JSON_THROW_ON_ERROR);
    if(($manifest['format']??'')!=='svkms-local-backup-v1'||!isset($manifest['files']['vault.sqlite'])){throw new RuntimeException('invalid_manifest');}
    foreach($manifest['files'] as $relative=>$digest){
        if(!preg_match('~^(vault\.sqlite|objects/[a-f0-9]{32}\.bin)$~D',$relative)||!is_string($digest)||!isset($tar[$relative])||!hash_equals($digest,hash('sha256',$tar[$relative]->getContent()))){throw new RuntimeException('invalid_backup_entry');}
    }
    mkdir($argv[3],0700);$created=true;mkdir($argv[3] . '/objects',0700);
    foreach($manifest['files'] as $relative=>$digest){file_put_contents($argv[3] . '/' . $relative,$tar[$relative]->getContent());chmod($argv[3] . '/' . $relative,0600);}
    $db=new Database($argv[3] . '/vault.sqlite');$db->query('UPDATE credentials SET revoked=1');
    file_put_contents($argv[3] . '/RESTORE-ISOLATED.txt',"Isolated recovery only. All sessions revoked. Restore tenant keys separately; reconcile current policies before any reconnection.\n");
    $success=true;echo "Restored verified snapshot into new isolated directory; all sessions revoked.\n";
}finally{
    @unlink($work . '/snapshot.tar');@rmdir($work);
    if($created&&!$success){foreach(glob($argv[3] . '/objects/*') as $f){@unlink($f);}@rmdir($argv[3] . '/objects');foreach(glob($argv[3] . '/*') as $f){@unlink($f);}@rmdir($argv[3]);}
}
