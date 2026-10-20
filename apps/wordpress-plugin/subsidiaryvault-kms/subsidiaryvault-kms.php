<?php
/**
 * Plugin Name: SubsidiaryVault KMS – Enterprise Document & Knowledge Management
 * Description: Low-privilege navigation and configuration for an independently authenticated SubsidiaryVault service.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: subsidiaryvault-kms
 * Domain Path: /languages
 */
declare(strict_types=1);
namespace SubsidiaryVault\WordPress;

if (!defined('ABSPATH')) { exit; }

final class Connector
{
    public const OPTION = 'svkms_connector';

    public static function boot(): void
    {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_init',[self::class,'settings']);
        add_action('rest_api_init',[self::class,'routes']);
        add_action('init',static function(): void { load_plugin_textdomain('subsidiaryvault-kms',false,dirname(plugin_basename(__FILE__)) . '/languages'); });
    }

    public static function activate(): void { add_option(self::OPTION,['console_url'=>'','consent'=>false],'','no'); }

    public static function menu(): void
    {
        add_menu_page(__('SubsidiaryVault','subsidiaryvault-kms'),__('SubsidiaryVault','subsidiaryvault-kms'),'read','subsidiaryvault-kms',[self::class,'page'],'dashicons-vault',58);
        add_submenu_page('subsidiaryvault-kms',__('Connection settings','subsidiaryvault-kms'),__('Connection settings','subsidiaryvault-kms'),'manage_options','subsidiaryvault-kms-settings',[self::class,'settingsPage']);
    }

    public static function settings(): void
    {
        register_setting('svkms_settings',self::OPTION,['type'=>'array','sanitize_callback'=>[self::class,'sanitize'],'default'=>['console_url'=>'','consent'=>false],'show_in_rest'=>false]);
    }

    public static function sanitize(mixed $input): array
    {
        if (!current_user_can('manage_options')) { return get_option(self::OPTION,['console_url'=>'','consent'=>false]); }
        $input=is_array($input)?$input:[];
        $url=is_string($input['console_url']??null)?trim($input['console_url']):'';
        $parts=wp_parse_url($url);
        $local=defined('SVKMS_ALLOW_LOCAL_CONSOLE') && SVKMS_ALLOW_LOCAL_CONSOLE && defined('WP_DEBUG') && WP_DEBUG;
        $valid=is_array($parts) && isset($parts['host'],$parts['scheme']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
        $valid=$valid && ($parts['scheme']==='https' || ($local && $parts['scheme']==='http' && in_array($parts['host'],['127.0.0.1','localhost','[::1]'],true)));
        if (!$valid && $url!=='') { add_settings_error(self::OPTION,'invalid_console_url',__('Use an HTTPS console URL without credentials, query parameters or fragments.','subsidiaryvault-kms')); }
        return ['console_url'=>$valid?esc_url_raw($url,['https','http']):'','consent'=>($input['consent']??'')==='1'];
    }

    public static function routes(): void
    {
        register_rest_route('subsidiaryvault-kms/v1','/connection',[
            'methods'=>'GET',
            'permission_callback'=>static fn(): bool=>current_user_can('manage_options'),
            'callback'=>static function(): \WP_REST_Response {
                $settings=get_option(self::OPTION,[]);
                $response=new \WP_REST_Response(['configured'=>!empty($settings['console_url']),'consented'=>!empty($settings['consent']),'document_credentials_stored'=>false,'version'=>'0.1.0']);
                $response->header('Cache-Control','no-store'); return $response;
            },
        ]);
    }

    public static function page(): void
    {
        if (!current_user_can('read')) { wp_die(esc_html__('Access denied.','subsidiaryvault-kms')); }
        $settings=get_option(self::OPTION,[]);
        echo '<div class="wrap"><h1>' . esc_html__('SubsidiaryVault KMS','subsidiaryvault-kms') . '</h1>';
        echo '<p>' . esc_html__('Enterprise Document & Knowledge Management for WordPress','subsidiaryvault-kms') . '</p>';
        echo '<p>' . esc_html__('Open the independent vault and sign in directly. WordPress accounts do not grant document access. Never paste document tokens or encryption keys into WordPress.','subsidiaryvault-kms') . '</p>';
        if (!empty($settings['console_url']) && !empty($settings['consent'])) {
            echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url($settings['console_url']) . '">' . esc_html__('Open independent vault','subsidiaryvault-kms') . '</a></p>';
        } else { echo '<p>' . esc_html__('An administrator must configure the independent console URL and acknowledge the external service disclosure.','subsidiaryvault-kms') . '</p>'; }
        echo '<p>' . esc_html__('Development preview: production deployment and high-assurance zero-knowledge access require additional implementation and independent validation.','subsidiaryvault-kms') . '</p></div>';
    }

    public static function settingsPage(): void
    {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Access denied.','subsidiaryvault-kms')); }
        $s=get_option(self::OPTION,[]);
        echo '<div class="wrap"><h1>' . esc_html__('SubsidiaryVault connection','subsidiaryvault-kms') . '</h1>';
        settings_errors(self::OPTION);
        echo '<form action="options.php" method="post">'; settings_fields('svkms_settings');
        echo '<p><label for="svkms-console">' . esc_html__('Independent console URL','subsidiaryvault-kms') . '</label></p><input type="url" class="regular-text" name="svkms_connector[console_url]" id="svkms-console" value="' . esc_attr($s['console_url']??'') . '">';
        echo '<p><label><input type="checkbox" name="svkms_connector[consent]" value="1" ' . checked(!empty($s['consent']),true,false) . '> ' . esc_html__('I understand that opening the console connects the user’s browser to the configured independent service, governed by its own privacy and authentication policies.','subsidiaryvault-kms') . '</label></p>';
        echo '<p>' . esc_html__('This connector does not transmit documents, create backend accounts, store vault credentials, proxy document requests or automatically contact the external service.','subsidiaryvault-kms') . '</p>';
        submit_button(); echo '</form></div>';
    }
}

Connector::boot();
register_activation_hook(__FILE__,[Connector::class,'activate']);
