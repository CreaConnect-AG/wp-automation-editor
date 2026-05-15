<?php
/**
 * Plugin Name: WP Automation Frontend Editor
 * Plugin URI:  https://creaconnect.ch/
 * Description: Frontend-Übersicht und Bearbeitung für Beiträge vom Autor wp-automation.
 * Version:     1.2.0
 * Author:      CreaConnect
 * Text Domain: wp-automation-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPA_EDITOR_PLUGIN_VERSION', '1.2.0' );
define( 'WPA_EDITOR_PLUGIN_FILE', __FILE__ );
define( 'WPA_EDITOR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPA_EDITOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPA_EDITOR_PLUGIN_PATH . 'includes/class-wpa-automation-editor-helpers.php';
require_once WPA_EDITOR_PLUGIN_PATH . 'includes/class-wpa-automation-editor-assets.php';
require_once WPA_EDITOR_PLUGIN_PATH . 'includes/class-wpa-automation-editor-post-handler.php';
require_once WPA_EDITOR_PLUGIN_PATH . 'includes/class-wpa-automation-editor-lock-handler.php';
require_once WPA_EDITOR_PLUGIN_PATH . 'includes/class-wpa-automation-editor-cleanup-handler.php';
require_once WPA_EDITOR_PLUGIN_PATH . 'includes/class-wpa-automation-editor-shortcode.php';
require_once WPA_EDITOR_PLUGIN_PATH . 'includes/class-wpa-automation-editor-plugin.php';

register_activation_hook( WPA_EDITOR_PLUGIN_FILE, array( 'WPA_Automation_Editor_Cleanup_Handler', 'activate' ) );
register_deactivation_hook( WPA_EDITOR_PLUGIN_FILE, array( 'WPA_Automation_Editor_Cleanup_Handler', 'deactivate' ) );

new WPA_Automation_Editor_Plugin();