<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Lock_Handler' ) ) {
    class WPA_Automation_Editor_Lock_Handler {
        public function refresh_post_lock() {
            if ( ! is_user_logged_in() ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Bitte zuerst einloggen.', 'wp-automation-editor' ),
                    ),
                    401
                );
            }

            check_ajax_referer( 'wpa_refresh_post_lock', 'nonce' );

            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

            if ( ! $post_id ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Ungültige Beitrags-ID.', 'wp-automation-editor' ),
                    ),
                    400
                );
            }

            $post = get_post( $post_id );
            $author_id = WPA_Automation_Editor_Helpers::get_automation_author_id();

            if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || ! $author_id || (int) $post->post_author !== (int) $author_id ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Der Beitrag konnte nicht geladen werden.', 'wp-automation-editor' ),
                    ),
                    404
                );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Keine Berechtigung.', 'wp-automation-editor' ),
                    ),
                    403
                );
            }

            WPA_Automation_Editor_Helpers::load_post_lock_functions();

            $locked_by = wp_check_post_lock( $post_id );

            if ( $locked_by ) {
                wp_send_json_error(
                    array(
                        'message' => sprintf(
                            __( 'Dieser Beitrag wird aktuell von %s bearbeitet.', 'wp-automation-editor' ),
                            WPA_Automation_Editor_Helpers::get_lock_holder_name( $locked_by )
                        ),
                    ),
                    409
                );
            }

            $lock_result = wp_set_post_lock( $post_id );

            if ( false === $lock_result ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Die Bearbeitungssperre konnte nicht aktualisiert werden.', 'wp-automation-editor' ),
                    ),
                    500
                );
            }

            wp_send_json_success(
                array(
                    'message' => __( 'Bearbeitungssperre aktualisiert.', 'wp-automation-editor' ),
                )
            );
        }
    }
}