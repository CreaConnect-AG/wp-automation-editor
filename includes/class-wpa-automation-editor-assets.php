<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Assets' ) ) {
    class WPA_Automation_Editor_Assets {
        public function enqueue_assets() {
            if ( ! WPA_Automation_Editor_Helpers::should_enqueue_assets() ) {
                return;
            }

            wp_enqueue_style(
                'wpa-tom-select',
                'https://cdn.jsdelivr.net/npm/tom-select@2.5.2/dist/css/tom-select.css',
                array(),
                '2.5.2'
            );

            wp_enqueue_style(
                'wpa-frontend-editor',
                WPA_EDITOR_PLUGIN_URL . 'assets/css/frontend.css',
                array( 'wpa-tom-select' ),
                WPA_EDITOR_PLUGIN_VERSION
            );

            wp_enqueue_script(
                'wpa-tom-select',
                'https://cdn.jsdelivr.net/npm/tom-select@2.5.2/dist/js/tom-select.complete.min.js',
                array(),
                '2.5.2',
                true
            );

            wp_enqueue_script(
                'wpa-frontend-editor',
                WPA_EDITOR_PLUGIN_URL . 'assets/js/frontend.js',
                array( 'wpa-tom-select' ),
                WPA_EDITOR_PLUGIN_VERSION,
                true
            );

            wp_localize_script(
                'wpa-frontend-editor',
                'wpaAutomationEditor',
                array(
                    'categoryPlaceholder' => __( 'Kategorien suchen oder auswählen', 'wp-automation-editor' ),
                    'tagPlaceholder'      => __( 'Schlagwörter suchen oder neu eingeben', 'wp-automation-editor' ),
                    'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
                    'lockRefreshNonce'    => wp_create_nonce( 'wpa_refresh_post_lock' ),
                    'lockRefreshInterval' => 60000,
                    'currentEditPostId'   => WPA_Automation_Editor_Helpers::get_current_edit_post_id(),
                    'lockLostMessage'     => __( 'Dieser Beitrag ist nicht mehr für dich freigegeben. Bitte lade die Seite neu.', 'wp-automation-editor' ),
                )
            );

            if ( function_exists( 'wp_enqueue_editor' ) ) {
                wp_enqueue_editor();
            }
        }
    }
}