<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Post_Handler' ) ) {
    class WPA_Automation_Editor_Post_Handler {
        public function handle_save_post() {
            if ( ! is_user_logged_in() ) {
                wp_die( esc_html__( 'Bitte zuerst einloggen.', 'wp-automation-editor' ) );
            }

            $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
            $redirect_url = isset( $_POST['redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) ) : home_url( '/' );

            if ( ! $post_id ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'invalid_post', $redirect_url ) );
                exit;
            }

            if ( ! isset( $_POST['wpa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpa_nonce'] ) ), 'wpa_save_post_' . $post_id ) ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'invalid_nonce', $redirect_url ) );
                exit;
            }

            $post = get_post( $post_id );
            $author_id = WPA_Automation_Editor_Helpers::get_automation_author_id();

            if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || ! $author_id || (int) $post->post_author !== (int) $author_id ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'invalid_post', $redirect_url ) );
                exit;
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'forbidden', $redirect_url ) );
                exit;
            }

            WPA_Automation_Editor_Helpers::load_post_lock_functions();

            $locked_by = wp_check_post_lock( $post_id );

            if ( $locked_by ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'locked', $redirect_url ) );
                exit;
            }

            $post_title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';

            $post_content = isset( $_POST['post_content'] ) ? wp_kses_post( wp_unslash( $_POST['post_content'] ) ) : '';

            $post_excerpt = isset( $_POST['post_excerpt'] ) ? wp_kses_post( wp_unslash( $_POST['post_excerpt'] ) ) : '';

            $old_workflow_status = WPA_Automation_Editor_Helpers::get_post_workflow_status( $post_id );

            $workflow_status = isset( $_POST['workflow_status'] ) ? sanitize_key( wp_unslash( $_POST['workflow_status'] ) ) : 'unbearbeitet';

            $newsletter_id = isset( $_POST['newsletter_id'] ) && '' !== wp_unslash( $_POST['newsletter_id'] )
                ? absint( wp_unslash( $_POST['newsletter_id'] ) )
                : '';

            $status_options = WPA_Automation_Editor_Helpers::get_workflow_status_options();

            if ( ! isset( $status_options[ $workflow_status ] ) ) {
                $workflow_status = 'unbearbeitet';
            }

            $post_update_data = array(
                'ID'           => $post_id,
                'post_title'   => $post_title,
                'post_content' => $post_content,
                'post_excerpt' => $post_excerpt,
            );

            if ( 'fertig' === $workflow_status ) {
                $post_type_object = get_post_type_object( $post->post_type );

                if ( $post_type_object && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
                    wp_safe_redirect( add_query_arg( 'wpa_notice', 'forbidden', $redirect_url ) );
                    exit;
                }

                $post_update_data['post_status'] = 'publish';
            }

            $updated_post_id = wp_update_post( $post_update_data, true );

            if ( is_wp_error( $updated_post_id ) ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'save_error', $redirect_url ) );
                exit;
            }

            $category_ids = array();

            if ( isset( $_POST['post_categories'] ) && is_array( $_POST['post_categories'] ) ) {
                $category_ids = array_map( 'absint', wp_unslash( $_POST['post_categories'] ) );
                $category_ids = array_filter( $category_ids );
            }

            wp_set_post_categories( $post_id, $category_ids, false );

            $tag_names = array();

            if ( isset( $_POST['post_tags'] ) && is_array( $_POST['post_tags'] ) ) {
                foreach ( wp_unslash( $_POST['post_tags'] ) as $tag_name ) {
                    $clean_tag_name = sanitize_text_field( $tag_name );

                    if ( '' !== $clean_tag_name ) {
                        $tag_names[] = $clean_tag_name;
                    }
                }
            }

            $tag_names = array_values( array_unique( $tag_names ) );
            wp_set_post_tags( $post_id, $tag_names, false );

            if ( function_exists( 'update_field' ) ) {
                update_field( 'newsletter_id', $newsletter_id, $post_id );
            } else {
                if ( '' === $newsletter_id ) {
                    delete_post_meta( $post_id, 'newsletter_id' );
                } else {
                    update_post_meta( $post_id, 'newsletter_id', $newsletter_id );
                }
            }

            WPA_Automation_Editor_Helpers::update_post_workflow_status( $post_id, $workflow_status );

            update_post_meta( $post_id, WPA_Automation_Editor_Helpers::META_FRONTEND_EDITED, 'unbearbeitet' === $workflow_status ? '0' : '1' );

            update_post_meta( $post_id, WPA_Automation_Editor_Helpers::META_LAST_EDITED_BY, get_current_user_id() );

            update_post_meta( $post_id, WPA_Automation_Editor_Helpers::META_LAST_EDITED_AT, current_time( 'mysql' ) );

            if ( $old_workflow_status !== $workflow_status ) {
                $this->send_teams_status_change_notification( $post_id, $old_workflow_status, $workflow_status );
            }

            wp_safe_redirect( add_query_arg( 'wpa_notice', 'saved', $redirect_url ) );
            exit;
        }

        public function handle_trash_post() {
            $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

            $redirect_url = isset( $_POST['redirect_to'] )
                ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
                : wp_get_referer();

            $redirect_url = wp_validate_redirect( $redirect_url, home_url( '/' ) );

            if ( ! $post_id ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'invalid_post', $redirect_url ) );
                exit;
            }

            check_admin_referer( 'wpa_trash_post_' . $post_id );

            $author_id = WPA_Automation_Editor_Helpers::get_automation_author_id();
            $post = get_post( $post_id );

            if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || ! $author_id || (int) $post->post_author !== (int) $author_id ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'invalid_post', $redirect_url ) );
                exit;
            }

            if ( ! current_user_can( 'delete_post', $post_id ) ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'forbidden', $redirect_url ) );
                exit;
            }

            WPA_Automation_Editor_Helpers::load_post_lock_functions();

            $locked_by = wp_check_post_lock( $post_id );

            if ( $locked_by && (int) $locked_by !== get_current_user_id() ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'locked', $redirect_url ) );
                exit;
            }

            $trashed_post = wp_trash_post( $post_id );

            if ( ! $trashed_post ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'trash_error', $redirect_url ) );
                exit;
            }

            wp_safe_redirect( add_query_arg( 'wpa_notice', 'trashed', $redirect_url ) );
            exit;
        }

        private function send_teams_status_change_notification( $post_id, $old_workflow_status, $new_workflow_status ) {
            $webhook_url = 'https://YOUR-WEBHOOK-URL-HERE';

            if ( empty( $webhook_url ) || 'https://YOUR-WEBHOOK-URL-HERE' === $webhook_url ) {
                return;
            }

            $post = get_post( $post_id );

            if ( ! $post instanceof WP_Post ) {
                return;
            }

            $status_options = WPA_Automation_Editor_Helpers::get_workflow_status_options();
            $current_user = wp_get_current_user();

            $has_featured_image = has_post_thumbnail( $post_id );
            $midjourney_prompt_en = '';

            if ( ! $has_featured_image ) {
                if ( function_exists( 'get_field' ) ) {
                    $midjourney_prompt_en = get_field( 'midjourney_prompt_en', $post_id );
                } else {
                    $midjourney_prompt_en = get_post_meta( $post_id, 'midjourney_prompt_en', true );
                }

                if ( is_array( $midjourney_prompt_en ) || is_object( $midjourney_prompt_en ) ) {
                    $midjourney_prompt_en = wp_json_encode( $midjourney_prompt_en );
                }

                $midjourney_prompt_en = sanitize_textarea_field( (string) $midjourney_prompt_en );
            }

            $payload = array(
                'title'                     => get_the_title( $post_id ),
                'post_id'                   => $post_id,
                'post_url'                  => get_permalink( $post_id ),
                'edit_url'                  => WPA_Automation_Editor_Helpers::get_edit_url( $post_id ),
                'author'                    => $current_user && $current_user->exists() ? $current_user->display_name : '',
                'changed_at'                => current_time( 'mysql' ),
                'old_workflow_status'       => $old_workflow_status,
                'old_workflow_status_label' => isset( $status_options[ $old_workflow_status ] ) ? $status_options[ $old_workflow_status ] : $old_workflow_status,
                'workflow_status'           => $new_workflow_status,
                'workflow_status_label'     => isset( $status_options[ $new_workflow_status ] ) ? $status_options[ $new_workflow_status ] : $new_workflow_status,

                'has_featured_image'        => $has_featured_image,
                'featured_image_status'     => $has_featured_image ? 'set' : 'missing',
                'midjourney_prompt_en'      => $midjourney_prompt_en,
            );

            $response = wp_remote_post(
                $webhook_url,
                array(
                    'timeout' => 10,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body'    => wp_json_encode( $payload ),
                )
            );

            if ( is_wp_error( $response ) ) {
                error_log( 'WPA Teams status notification failed: ' . $response->get_error_message() );
                return;
            }

            $response_code = wp_remote_retrieve_response_code( $response );

            if ( 200 > $response_code || 299 < $response_code ) {
                error_log( 'WPA Teams status notification failed with HTTP status: ' . $response_code );
            }
        }
    }
}