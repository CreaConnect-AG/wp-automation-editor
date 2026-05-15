<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Cleanup_Handler' ) ) {
    class WPA_Automation_Editor_Cleanup_Handler {
        public static function activate() {
            self::schedule_cleanup_event();
        }

        public static function deactivate() {
            $scheduled_timestamp = wp_next_scheduled( WPA_Automation_Editor_Helpers::CLEANUP_CRON_HOOK );

            if ( $scheduled_timestamp ) {
                wp_unschedule_event( $scheduled_timestamp, WPA_Automation_Editor_Helpers::CLEANUP_CRON_HOOK );
            }
        }

        public function ensure_scheduled() {
            self::schedule_cleanup_event();
        }

        public function trash_old_unbearbeitet_posts() {
            $author_id = WPA_Automation_Editor_Helpers::get_automation_author_id();

            if ( ! $author_id ) {
                return;
            }

            $old_posts_query = new WP_Query(
                array(
                    'post_type'              => 'post',
                    'post_status'            => array( 'draft', 'pending', 'future', 'private', 'publish' ),
                    'author'                 => $author_id,
                    'posts_per_page'         => 50,
                    'fields'                 => 'ids',
                    'no_found_rows'          => true,
                    'ignore_sticky_posts'    => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'date_query'             => array(
                        array(
                            'column'    => 'post_date',
                            'before'    => '1 month ago',
                            'inclusive' => false,
                        ),
                    ),
                    'meta_query'             => WPA_Automation_Editor_Helpers::build_unbearbeitet_cleanup_meta_query(),
                )
            );

            if ( ! $old_posts_query->have_posts() ) {
                return;
            }

            foreach ( $old_posts_query->posts as $post_id ) {
                $workflow_status = WPA_Automation_Editor_Helpers::get_post_workflow_status( $post_id );

                if ( 'unbearbeitet' !== $workflow_status ) {
                    continue;
                }

                wp_trash_post( $post_id );
            }
        }

        private static function schedule_cleanup_event() {
            if ( wp_next_scheduled( WPA_Automation_Editor_Helpers::CLEANUP_CRON_HOOK ) ) {
                return;
            }

            wp_schedule_event(
                time() + HOUR_IN_SECONDS,
                'daily',
                WPA_Automation_Editor_Helpers::CLEANUP_CRON_HOOK
            );
        }
    }
}