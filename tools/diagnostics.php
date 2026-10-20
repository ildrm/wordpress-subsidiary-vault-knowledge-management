<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Config,Database};
$config=new Config();$db=new Database($config->database);
$extensions=[];foreach(['sodium','pdo_sqlite','intl','mbstring','openssl','Phar'] as $extension){$extensions[$extension]=extension_loaded($extension);}
echo json_encode(['product'=>'SubsidiaryVault KMS','version'=>\SubsidiaryVault\Version::CURRENT,'profile'=>$config->environment,'database_driver'=>$db->driver,'key_provider'=>getenv('SV_KEY_PROVIDER')?:'local-files','object_provider'=>getenv('SV_OBJECT_PROVIDER')?:'local-files','php'=>PHP_VERSION,'sodium'=>SODIUM_LIBRARY_VERSION,'extensions'=>$extensions,'schema_version'=>$db->schemaVersion(),'production_enabled'=>false,'zero_knowledge_enabled'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
