<?php
// Executed by official WP-CLI against an isolated real WordPress installation.
use SubsidiaryVault\WordPress\Connector;
$GLOBALS['svkms_live_checks']=0;
function svkms_live_check(bool $condition,string $reason):void{$GLOBALS['svkms_live_checks']++;if(!$condition){throw new RuntimeException($reason);}}
$admin=get_user_by('login','synthetic-admin');wp_set_current_user($admin->ID);
svkms_live_check(is_plugin_active('subsidiaryvault-kms/subsidiaryvault-kms.php'),'plugin activated');
$initial=get_option(Connector::OPTION);svkms_live_check(is_array($initial)&&array_keys($initial)===['console_url','consent'],'activation stores only connector configuration');
$route=rest_do_request(new WP_REST_Request('GET','/subsidiaryvault-kms/v1/connection'));svkms_live_check($route->get_status()===200,'real REST administrator permission');
$valid=Connector::sanitize(['console_url'=>'https://vault.example.invalid','consent'=>'1']);update_option(Connector::OPTION,$valid);
svkms_live_check(get_option(Connector::OPTION)===$valid,'settings sanitized and persisted');
foreach(['https://user@example.invalid','http://evil.example.invalid','javascript:alert(1)','https://vault.example.invalid/?token=secret'] as $url){svkms_live_check(Connector::sanitize(['console_url'=>$url,'consent'=>'1'])['console_url']==='','unsafe connector URL refused');}
$reader=get_user_by('login','synthetic-reader');if(!$reader){$id=wp_create_user('synthetic-reader',wp_generate_password(40));$reader=get_user_by('id',$id);$reader->set_role('subscriber');}
wp_set_current_user($reader->ID);
$response=rest_do_request(new WP_REST_Request('GET','/subsidiaryvault-kms/v1/connection'));svkms_live_check(in_array($response->get_status(),[401,403],true),'subscriber cannot access settings diagnostics');
svkms_live_check(Connector::sanitize(['console_url'=>'https://changed.example.invalid'])===$valid,'subscriber cannot change connector settings');
ob_start();Connector::page();$page=ob_get_clean();svkms_live_check(str_contains($page,'rel="noopener noreferrer"')&&str_contains($page,'https://vault.example.invalid'),'independent safe navigation output');
svkms_live_check(!str_contains($page,'secret')&&!str_contains($page,'Bearer'),'navigation contains no vault credentials');
wp_set_current_user(0);$response=rest_do_request(new WP_REST_Request('GET','/subsidiaryvault-kms/v1/connection'));svkms_live_check(in_array($response->get_status(),[401,403],true),'anonymous REST caller denied');
echo 'Live WordPress activation/REST/settings: '.$GLOBALS['svkms_live_checks']." assertions passed.\n";
