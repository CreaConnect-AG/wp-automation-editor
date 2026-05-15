<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Plugin' ) ) {
    class WPA_Automation_Editor_Plugin {
        private $assets;
        private $shortcode;
        private $post_handler;
        private $lock_handler;
        private $cleanup_handler;

        public function __construct() {
            $this->assets = new WPA_Automation_Editor_Assets();
            $this->shortcode = new WPA_Automation_Editor_Shortcode();
            $this->post_handler = new WPA_Automation_Editor_Post_Handler();
            $this->lock_handler = new WPA_Automation_Editor_Lock_Handler();
            $this->cleanup_handler = new WPA_Automation_Editor_Cleanup_Handler();

            add_action( 'wp_enqueue_scripts', array( $this->assets, 'enqueue_assets' ) );
            add_shortcode( WPA_Automation_Editor_Helpers::get_shortcode_name(), array( $this->shortcode, 'render_shortcode' ) );

            add_action( 'admin_post_wpa_save_post', array( $this->post_handler, 'handle_save_post' ) );
            add_action( 'wp_ajax_wpa_refresh_post_lock', array( $this->lock_handler, 'refresh_post_lock' ) );
            add_action( WPA_Automation_Editor_Helpers::CLEANUP_CRON_HOOK, array( $this->cleanup_handler, 'trash_old_unbearbeitet_posts' ) );

            $this->cleanup_handler->ensure_scheduled();
        }
    }
}