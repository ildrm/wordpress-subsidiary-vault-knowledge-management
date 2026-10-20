<?php
declare(strict_types=1);
// A small WordPress API harness: tests connector logic, not WordPress runtime compatibility.
define('ABSPATH',__DIR__);$allowed=true;$routes=[];$settings=[];
function add_action(...$args):void{}
function register_activation_hook(...$args):void{}
function current_user_can(string $cap):bool{return $GLOBALS['allowed'];}
function get_option(string $key,array $default=[]):array{return $GLOBALS['settings']?:$default;}
function wp_parse_url(string $url):array|false{return parse_url($url);}
function esc_url_raw(string $url,array $protocols):string{return filter_var($url,FILTER_SANITIZE_URL);}
function add_settings_error(...$args):void{}
function __(string $text,string $domain):string{return $text;}
function register_rest_route(string $ns,string $path,array $args):void{$GLOBALS['routes'][$path]=$args;}
require dirname(__DIR__) . '/apps/wordpress-plugin/subsidiaryvault-kms/subsidiaryvault-kms.php';
use SubsidiaryVault\WordPress\Connector;
$checks=0;
function verify(bool $condition):void{global $checks;$checks++;if(!$condition){throw new RuntimeException('plugin assertion '.$checks);}}
foreach(['http://attacker.test','javascript:alert(1)','https://user@example.test','https://user:pass@example.test','https://example.test/?token=secret','https://example.test/#secret'] as $url){verify(Connector::sanitize(['console_url'=>$url,'consent'=>'1'])['console_url']==='');}
verify(Connector::sanitize(['console_url'=>'https://vault.example.test','consent'=>'1'])===['console_url'=>'https://vault.example.test','consent'=>true]);
verify(Connector::sanitize(['console_url'=>'https://vault.example.test','consent'=>'yes'])['consent']===false);
Connector::routes();verify(($routes['/connection']['permission_callback'])());$allowed=false;verify(!($routes['/connection']['permission_callback'])());
$settings=['console_url'=>'https://old.example.test','consent'=>true];verify(Connector::sanitize(['console_url'=>'https://new.example.test'])===$settings);
echo "WordPress connector harness: $checks assertions passed (live WordPress not covered).\n";
