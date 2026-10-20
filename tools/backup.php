<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
require dirname(__DIR__) . '/apps/trusted-vault-client/FileCipher.php';
use SubsidiaryVault\{Config,Database};
use SubsidiaryVault\LocalClient\FileCipher;
umask(0077);
if(count($argv)!==3){fwrite(STDERR,"Usage: php tools/backup.php BACKUP_ENCRYPTION_KEY OUTPUT.svbackup\nTenant encryption keys are excluded and must be backed up separately under distinct custody.\n");exit(2);}
$config=new Config();$db=new Database($config->dataDir . '/vault.sqlite');
$work=sys_get_temp_dir() . '/svkms-backup-' . Database::id();mkdir($work,0700);
try{
    $snapshot=$work . '/vault.sqlite';
    $db->pdo->exec('VACUUM INTO ' . $db->pdo->quote($snapshot));
    $copy=new Database($snapshot);
    // Never export reusable development sessions as an active recovery session.
    $copy->query('UPDATE credentials SET revoked=1');
    $copy->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $archive=new PharData($work . '/snapshot.tar');$archive->addFile($snapshot,'vault.sqlite');
    $manifest=['format'=>'svkms-local-backup-v1','created_at'=>time(),'files'=>['vault.sqlite'=>hash_file('sha256',$snapshot)],'tenant_keys_included'=>false,'profile'=>'isolated-local-restore-only'];
    foreach($copy->all('SELECT object_id FROM versions ORDER BY object_id') as $v){
        if(!preg_match('/^[a-f0-9]{32}$/D',$v['object_id'])){throw new RuntimeException('invalid_object');}
        $relative='objects/' . $v['object_id'] . '.bin';$path=$config->dataDir . '/' . $relative;
        if(!is_file($path)){throw new RuntimeException('missing_object');}
        $archive->addFile($path,$relative);$manifest['files'][$relative]=hash_file('sha256',$path);
    }
    $archive->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));unset($archive);
    FileCipher::transform('encrypt',$work . '/snapshot.tar',$argv[2],$argv[1],'svkms-local-backup-v1');
    echo "Encrypted local snapshot created. Recoverability requires separately held tenant keys.\n";
}finally{
    foreach(glob($work . '/*') as $file){@unlink($file);}@rmdir($work);
}
