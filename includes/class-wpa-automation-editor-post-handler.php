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

            $remote_publish_type = isset( $_POST['remote_publish_type'] )
                ? sanitize_key( wp_unslash( $_POST['remote_publish_type'] ) )
                : 'newsletter';

            if ( ! in_array( $remote_publish_type, array( 'newsletter', 'immonews', 'newsletter_immonews' ), true ) ) {
                $remote_publish_type = 'newsletter';
            }

            $uses_newsletter = in_array( $remote_publish_type, array( 'newsletter', 'newsletter_immonews' ), true );
            $uses_remote_publish = in_array( $remote_publish_type, array( 'immonews', 'newsletter_immonews' ), true );

            $newsletter_id = $uses_newsletter && isset( $_POST['newsletter_id'] ) && '' !== wp_unslash( $_POST['newsletter_id'] )
                ? absint( wp_unslash( $_POST['newsletter_id'] ) )
                : '';

            $remote_publish_date = $uses_remote_publish && isset( $_POST['remote_publish_date'] )
                ? sanitize_text_field( wp_unslash( $_POST['remote_publish_date'] ) )
                : '';

            $remote_publish_time = $uses_remote_publish && isset( $_POST['remote_publish_time'] )
                ? sanitize_text_field( wp_unslash( $_POST['remote_publish_time'] ) )
                : '';

            $has_remote_publish_date = '' !== $remote_publish_date;
            $has_remote_publish_time = '' !== $remote_publish_time;
            $has_remote_publish_schedule = $has_remote_publish_date || $has_remote_publish_time;

            if ( $uses_newsletter && '' === $newsletter_id ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'newsletter_unknown', $redirect_url ) );
                exit;
            }

            if ( $uses_remote_publish && ( ! $has_remote_publish_date || ! $has_remote_publish_time ) ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'schedule_incomplete', $redirect_url ) );
                exit;
            }

            if ( $uses_remote_publish ) {
                $remote_publish_datetime_string = WPA_Automation_Editor_Helpers::get_remote_publish_datetime_string( $remote_publish_date, $remote_publish_time );

                if ( '' === $remote_publish_datetime_string ) {
                    wp_safe_redirect( add_query_arg( 'wpa_notice', 'schedule_invalid', $redirect_url ) );
                    exit;
                }

                if ( WPA_Automation_Editor_Helpers::is_remote_publish_slot_taken( $remote_publish_date, $remote_publish_time, $post_id ) ) {
                    wp_safe_redirect( add_query_arg( 'wpa_notice', 'schedule_slot_taken', $redirect_url ) );
                    exit;
                }
            }

            if ( 'newsletter_immonews' === $remote_publish_type ) {
                $newsletter_schedule_validation = WPA_Automation_Editor_Helpers::validate_newsletter_remote_publish_schedule(
                    $newsletter_id,
                    $remote_publish_date,
                    $remote_publish_time
                );

                if ( is_wp_error( $newsletter_schedule_validation ) ) {
                    $error_data = $newsletter_schedule_validation->get_error_data();

                    $notice_query_args = array(
                        'wpa_notice' => $newsletter_schedule_validation->get_error_code(),
                    );

                    if ( is_array( $error_data ) ) {
                        if ( isset( $error_data['newsletter_id'] ) ) {
                            $notice_query_args['wpa_newsletter_id'] = absint( $error_data['newsletter_id'] );
                        }

                        if ( isset( $error_data['newsletter_date'] ) ) {
                            $notice_query_args['wpa_newsletter_date'] = sanitize_text_field( $error_data['newsletter_date'] );
                        }

                        if ( isset( $error_data['remote_publish_date'] ) ) {
                            $notice_query_args['wpa_remote_publish_date'] = sanitize_text_field( $error_data['remote_publish_date'] );
                        }
                    }

                    wp_safe_redirect( add_query_arg( $notice_query_args, $redirect_url ) );
                    exit;
                }
            }

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

            $featured_image_result = $this->handle_featured_image_upload( $post_id );

            if ( is_wp_error( $featured_image_result ) ) {
                wp_safe_redirect( add_query_arg( 'wpa_notice', 'featured_image_error', $redirect_url ) );
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
                update_field( 'lead', $post_excerpt, $post_id );

            } else {

                if ( '' === $newsletter_id ) {

                    delete_post_meta( $post_id, 'newsletter_id' );
                } else {

                    update_post_meta( $post_id, 'newsletter_id', $newsletter_id );

                }

                update_post_meta( $post_id, 'lead', $post_excerpt );

            }

            if ( $has_remote_publish_date && $has_remote_publish_time ) {
                WPA_Automation_Editor_Helpers::update_post_remote_publish_schedule( $post_id, $remote_publish_date, $remote_publish_time );
            } else {
                WPA_Automation_Editor_Helpers::clear_post_remote_publish_schedule( $post_id );
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

        public function handle_get_remote_publish_occupied_times() {
            if ( ! is_user_logged_in() ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Bitte zuerst einloggen.', 'wp-automation-editor' ),
                    ),
                    401
                );
            }

            check_ajax_referer( 'wpa_remote_publish_slots', 'nonce' );

            $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
            $remote_publish_date = isset( $_POST['remote_publish_date'] )
                ? sanitize_text_field( wp_unslash( $_POST['remote_publish_date'] ) )
                : '';

            if ( $post_id > 0 ) {
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
                            'message' => __( 'Du hast keine Berechtigung für diese Aktion.', 'wp-automation-editor' ),
                        ),
                        403
                    );
                }
            }

            $occupied_slots = WPA_Automation_Editor_Helpers::get_remote_publish_occupied_slots( $remote_publish_date, $post_id );
            $occupied_times = array();

            foreach ( $occupied_slots as $occupied_slot ) {
                if ( isset( $occupied_slot['time'] ) ) {
                    $occupied_times[] = $occupied_slot['time'];
                }
            }

            wp_send_json_success(
                array(
                    'occupiedTimes' => $occupied_times,
                    'occupiedSlots' => $occupied_slots,
                )
            );
        }

        private function handle_featured_image_upload( $post_id ) {
            $remove_featured_image = isset( $_POST['remove_featured_image'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['remove_featured_image'] ) );

            $has_featured_image_upload = isset( $_FILES['wpa_featured_image'] )
                && is_array( $_FILES['wpa_featured_image'] )
                && ! empty( $_FILES['wpa_featured_image']['name'] )
                && isset( $_FILES['wpa_featured_image']['error'] )
                && UPLOAD_ERR_NO_FILE !== $_FILES['wpa_featured_image']['error'];

            if ( ! $has_featured_image_upload ) {
                if ( $remove_featured_image ) {
                    delete_post_thumbnail( $post_id );
                }

                return true;
            }

            if ( ! current_user_can( 'upload_files' ) ) {
                return new WP_Error( 'wpa_upload_forbidden', __( 'Du hast keine Berechtigung, Bilder hochzuladen.', 'wp-automation-editor' ) );
            }

            if ( ! function_exists( 'media_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            $attachment_id = media_handle_upload(
                'wpa_featured_image',
                $post_id,
                array(),
                array(
                    'test_form' => false,
                    'mimes'     => array(
                        'jpg|jpeg|jpe' => 'image/jpeg',
                        'png'          => 'image/png',
                        'gif'          => 'image/gif',
                        'webp'         => 'image/webp',
                    ),
                )
            );

            if ( is_wp_error( $attachment_id ) ) {
                return $attachment_id;
            }

            set_post_thumbnail( $post_id, $attachment_id );

            return true;
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

            $publish_information = WPA_Automation_Editor_Helpers::get_post_publish_information_for_notification( $post_id, 'status' );

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
                'publish_information'       => $publish_information,
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