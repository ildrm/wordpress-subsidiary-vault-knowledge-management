<?php
declare(strict_types=1);
require __DIR__.'/local_environment.php';require dirname(__DIR__).'/services/core-api/src/bootstrap.php';
use SubsidiaryVault\{Config,HttpClient};
$checks=0;function denied(Closure $fn):void{global $checks;$checks++;try{$fn();}catch(Throwable){return;}throw new RuntimeException('unsafe configuration accepted');}
$root=sys_get_temp_dir().'/svkms-config-'.bin2hex(random_bytes(6));
putenv('SV_DATA_DIR='.$root.'/data');putenv('SV_KEY_DIR='.$root.'/keys');
putenv('SV_ENV=production');denied(fn()=>new Config());
putenv('SV_ENV=staging');putenv('SV_AUTH_MODE=development');denied(fn()=>new Config());
putenv('SV_ENV=local');foreach(['SV_KEY_PROVIDER','SV_OBJECT_PROVIDER','SV_UPLOAD_PROFILE'] as $key){putenv($key.'=misspelled-provider');denied(fn()=>new Config());putenv($key);}
foreach(['http://127.0.0.1:8200','file:///etc/passwd','https://user:secret@keys.example.invalid','https://keys.example.invalid/#fragment',"https://keys.example.invalid\r\nInjected: value"] as $url){denied(fn()=>HttpClient::validateUrl($url));}
putenv('SV_ALLOW_INSECURE_TEST_ENDPOINTS=1');HttpClient::validateUrl('http://127.0.0.1:8200');$checks++;
putenv('SV_ENV=staging');denied(fn()=>HttpClient::validateUrl('http://127.0.0.1:8200'));
if(is_dir($root.'/data'))rmdir($root.'/data');if(is_dir($root.'/keys'))rmdir($root.'/keys');if(is_dir($root))rmdir($root);
echo "Deployment configuration/TLS guards: $checks assertions passed.\n";
