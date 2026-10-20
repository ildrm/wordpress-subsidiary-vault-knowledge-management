<?php
declare(strict_types=1);
require dirname(__DIR__) . '/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Config,Database};
$config=new Config();$db=new Database($config->dataDir . '/vault.sqlite');
$extensions=[];foreach(['sodium','pdo_sqlite','intl','mbstring','openssl','Phar'] as $extension){$extensions[$extension]=extension_loaded($extension);}
echo json_encode(['product'=>'SubsidiaryVault KMS','version'=>'0.1.0-dev','profile'=>'local-only','php'=>PHP_VERSION,'sodium'=>SODIUM_LIBRARY_VERSION,'extensions'=>$extensions,'schema_version'=>(int)($db->one('SELECT MAX(version) AS v FROM schema_migrations')['v']??0),'production_enabled'=>false,'zero_knowledge_enabled'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
