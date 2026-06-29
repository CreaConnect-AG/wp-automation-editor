<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Helpers' ) ) {
    class WPA_Automation_Editor_Helpers {
        const SHORTCODE = 'wpa_automation_editor';
		const META_WORKFLOW_STATUS = 'workflow_status';
		const META_LEGACY_WORKFLOW_STATUS = '_wpa_workflow_status';
        const META_FRONTEND_EDITED = '_wpa_frontend_edited';
        const META_LAST_EDITED_BY = '_wpa_last_edited_by';
        const META_LAST_EDITED_AT = '_wpa_last_edited_at';
        const META_REMOTE_PUBLISH_GROUP = 'wpa_remote_publish';
        const META_REMOTE_PUBLISH_DATE = 'wpa_remote_publish_date';
        const META_REMOTE_PUBLISH_TIME = 'wpa_remote_publish_time';
        const META_REMOTE_PUBLISH_GROUP_DATE = 'wpa_remote_publish_wpa_remote_publish_date';
        const META_REMOTE_PUBLISH_GROUP_TIME = 'wpa_remote_publish_wpa_remote_publish_time';
        const DEFAULT_AUTHOR_LOGIN = 'wp-automation';
        const POSTS_PER_PAGE = 20;
		const CLEANUP_CRON_HOOK = 'wpa_automation_editor_cleanup_old_unbearbeitet_posts';
        const NEWSLETTER_SCHEDULE_FILE = 'data/newsletter-schedule.json';

        public static function get_shortcode_name() {
            return self::SHORTCODE;
        }

        public static function should_enqueue_assets() {
            if ( is_admin() ) {
                return false;
            }

            if ( ! is_singular() ) {
                return false;
            }

            $current_post = get_post();

            if ( ! $current_post instanceof WP_Post ) {
                return false;
            }

            return has_shortcode( $current_post->post_content, self::SHORTCODE );
        }

        public static function get_current_action() {
            return isset( $_GET['wpa_action'] ) ? sanitize_key( wp_unslash( $_GET['wpa_action'] ) ) : '';
        }

        public static function get_current_edit_post_id() {
            if ( 'edit' !== self::get_current_action() ) {
                return 0;
            }

            return isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        }

        public static function load_post_lock_functions() {
            if ( ! function_exists( 'wp_check_post_lock' ) || ! function_exists( 'wp_set_post_lock' ) ) {
                require_once ABSPATH . 'wp-admin/includes/post.php';
            }
        }

		public static function get_workflow_status_options() {
			return array(
				'unbearbeitet'   => __( 'Neu', 'wp-automation-editor' ),
				'in_bearbeitung' => __( 'Entwurf', 'wp-automation-editor' ),
				'fertig'         => __( 'Veröffentlichen', 'wp-automation-editor' ),
			);
		}

        public static function get_remote_publish_time_options() {
            return array(
                '07:30' => __( '07:30 Uhr', 'wp-automation-editor' ),
                '09:00' => __( '09:00 Uhr', 'wp-automation-editor' ),
                '10:00' => __( '10:00 Uhr', 'wp-automation-editor' ),
                '11:00' => __( '11:00 Uhr', 'wp-automation-editor' ),
                '12:30' => __( '12:30 Uhr', 'wp-automation-editor' ),
                '14:00' => __( '14:00 Uhr', 'wp-automation-editor' ),
                '15:00' => __( '15:00 Uhr', 'wp-automation-editor' ),
                '16:00' => __( '16:00 Uhr', 'wp-automation-editor' ),
                '17:30' => __( '17:30 Uhr', 'wp-automation-editor' ),
                '19:00' => __( '19:00 Uhr', 'wp-automation-editor' ),
            );
        }

        public static function get_post_remote_publish_schedule( $post_id ) {
            $remote_publish_date = '';
            $remote_publish_time = '';

            if ( function_exists( 'get_field' ) ) {
                $remote_publish_group = get_field( self::META_REMOTE_PUBLISH_GROUP, $post_id, false );

                if ( is_array( $remote_publish_group ) ) {
                    if ( isset( $remote_publish_group[ self::META_REMOTE_PUBLISH_DATE ] ) ) {
                        $remote_publish_date = $remote_publish_group[ self::META_REMOTE_PUBLISH_DATE ];
                    }

                    if ( isset( $remote_publish_group[ self::META_REMOTE_PUBLISH_TIME ] ) ) {
                        $remote_publish_time = $remote_publish_group[ self::META_REMOTE_PUBLISH_TIME ];
                    }
                }
            }

            if ( '' === $remote_publish_date || null === $remote_publish_date || false === $remote_publish_date ) {
                $remote_publish_date = get_post_meta( $post_id, self::META_REMOTE_PUBLISH_GROUP_DATE, true );
            }

            if ( '' === $remote_publish_time || null === $remote_publish_time || false === $remote_publish_time ) {
                $remote_publish_time = get_post_meta( $post_id, self::META_REMOTE_PUBLISH_GROUP_TIME, true );
            }

            if ( '' === $remote_publish_date || null === $remote_publish_date || false === $remote_publish_date ) {
                $remote_publish_date = get_post_meta( $post_id, self::META_REMOTE_PUBLISH_DATE, true );
            }

            if ( '' === $remote_publish_time || null === $remote_publish_time || false === $remote_publish_time ) {
                $remote_publish_time = get_post_meta( $post_id, self::META_REMOTE_PUBLISH_TIME, true );
            }

            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );
            $remote_publish_time = is_string( $remote_publish_time ) ? sanitize_text_field( $remote_publish_time ) : '';

            return array(
                'date' => $remote_publish_date,
                'time' => $remote_publish_time,
            );
        }

        public static function update_post_remote_publish_schedule( $post_id, $remote_publish_date, $remote_publish_time ) {
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );
            $remote_publish_time = sanitize_text_field( $remote_publish_time );

            $acf_remote_publish_date = self::normalize_remote_publish_date_for_acf( $remote_publish_date );

            if ( function_exists( 'update_field' ) ) {
                $remote_publish_group = get_field( self::META_REMOTE_PUBLISH_GROUP, $post_id, false );

                if ( ! is_array( $remote_publish_group ) ) {
                    $remote_publish_group = array();
                }

                $remote_publish_group[ self::META_REMOTE_PUBLISH_DATE ] = $acf_remote_publish_date;
                $remote_publish_group[ self::META_REMOTE_PUBLISH_TIME ] = $remote_publish_time;

                update_field( self::META_REMOTE_PUBLISH_GROUP, $remote_publish_group, $post_id );
            }

            update_post_meta( $post_id, self::META_REMOTE_PUBLISH_GROUP_DATE, $acf_remote_publish_date );
            update_post_meta( $post_id, self::META_REMOTE_PUBLISH_GROUP_TIME, $remote_publish_time );

            delete_post_meta( $post_id, self::META_REMOTE_PUBLISH_DATE );
            delete_post_meta( $post_id, self::META_REMOTE_PUBLISH_TIME );
        }

        public static function clear_post_remote_publish_schedule( $post_id ) {
            if ( function_exists( 'update_field' ) ) {
                $remote_publish_group = get_field( self::META_REMOTE_PUBLISH_GROUP, $post_id, false );

                if ( ! is_array( $remote_publish_group ) ) {
                    $remote_publish_group = array();
                }

                $remote_publish_group[ self::META_REMOTE_PUBLISH_DATE ] = '';
                $remote_publish_group[ self::META_REMOTE_PUBLISH_TIME ] = '';

                update_field( self::META_REMOTE_PUBLISH_GROUP, $remote_publish_group, $post_id );
            }

            update_post_meta( $post_id, self::META_REMOTE_PUBLISH_GROUP_DATE, '' );
            update_post_meta( $post_id, self::META_REMOTE_PUBLISH_GROUP_TIME, '' );

            delete_post_meta( $post_id, self::META_REMOTE_PUBLISH_DATE );
            delete_post_meta( $post_id, self::META_REMOTE_PUBLISH_TIME );
        }

        public static function is_valid_remote_publish_date( $remote_publish_date ) {
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );

            if ( ! is_string( $remote_publish_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $remote_publish_date ) ) {
                return false;
            }

            $date_parts = explode( '-', $remote_publish_date );

            return checkdate( (int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0] );
        }

        public static function get_remote_publish_datetime_string( $remote_publish_date, $remote_publish_time ) {
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );
            $time_options = self::get_remote_publish_time_options();

            if ( ! self::is_valid_remote_publish_date( $remote_publish_date ) ) {
                return '';
            }

            if ( ! isset( $time_options[ $remote_publish_time ] ) ) {
                return '';
            }

            return $remote_publish_date . 'T' . $remote_publish_time . ':00';
        }

        public static function get_post_publish_information_for_notification( $post_id, $context = 'status' ) {
            $newsletter_id = self::get_post_newsletter_id( $post_id );

            if ( '' !== $newsletter_id ) {
                if ( 'import' === $context ) {
                    return sprintf(
                        __( 'Der Beitrag wird mit dem immoNewsletter #%s veröffentlicht.', 'wp-automation-editor' ),
                        $newsletter_id
                    );
                }

                return sprintf(
                    __( 'immoNewsletter #%s', 'wp-automation-editor' ),
                    $newsletter_id
                );
            }

            $remote_publish_schedule = self::get_post_remote_publish_schedule( $post_id );
            $remote_publish_date = self::format_remote_publish_date_for_notification( $remote_publish_schedule['date'] );
            $remote_publish_time = self::format_remote_publish_time_for_notification( $remote_publish_schedule['time'] );

            if ( '' === $remote_publish_date || '' === $remote_publish_time ) {
                return '';
            }

            if ( 'import' === $context ) {
                if ( has_post_thumbnail( $post_id ) ) {
                    return sprintf(
                        __( 'Der Beitrag ist für den %1$s um %2$s vorgeplant auf immo-invest.ch.', 'wp-automation-editor' ),
                        $remote_publish_date,
                        $remote_publish_time
                    );
                }

                return sprintf(
                    __( "HANDLUNGSBEDARF:\nDas Beitragsbild fehlt, der Beitrag soll am %1$s um %2$s vorgeplant werden auf immo-invest.ch.", 'wp-automation-editor' ),
                    $remote_publish_date,
                    $remote_publish_time
                );
            }

            return sprintf(
                __( '%1$s um %2$s', 'wp-automation-editor' ),
                $remote_publish_date,
                $remote_publish_time
            );
        }

        public static function get_post_newsletter_id( $post_id ) {
            $newsletter_id = '';

            if ( function_exists( 'get_field' ) ) {
                $newsletter_id = get_field( 'newsletter_id', $post_id );
            }

            if ( '' === $newsletter_id || null === $newsletter_id || false === $newsletter_id ) {
                $newsletter_id = get_post_meta( $post_id, 'newsletter_id', true );
            }

            if ( is_array( $newsletter_id ) || is_object( $newsletter_id ) ) {
                return '';
            }

            $newsletter_id = absint( $newsletter_id );

            return $newsletter_id > 0 ? (string) $newsletter_id : '';
        }

        private static function format_remote_publish_date_for_notification( $remote_publish_date ) {
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );

            if ( '' === $remote_publish_date ) {
                return '';
            }

            $timestamp = strtotime( $remote_publish_date . ' 00:00:00' );

            if ( false === $timestamp ) {
                return $remote_publish_date;
            }

            return date_i18n( get_option( 'date_format' ), $timestamp );
        }

        private static function format_remote_publish_time_for_notification( $remote_publish_time ) {
            $remote_publish_time = is_string( $remote_publish_time ) ? sanitize_text_field( $remote_publish_time ) : '';
            $time_options = self::get_remote_publish_time_options();

            if ( isset( $time_options[ $remote_publish_time ] ) ) {
                return wp_strip_all_tags( $time_options[ $remote_publish_time ] );
            }

            if ( preg_match( '/^\d{2}:\d{2}$/', $remote_publish_time ) ) {
                return $remote_publish_time . ' Uhr';
            }

            return '';
        }

        public static function get_remote_publish_occupied_slots( $remote_publish_date, $exclude_post_id = 0 ) {
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );

            if ( ! self::is_valid_remote_publish_date( $remote_publish_date ) ) {
                return array();
            }

            $acf_remote_publish_date = self::normalize_remote_publish_date_for_acf( $remote_publish_date );
            $exclude_post_id = absint( $exclude_post_id );

            $query_args = array(
                'post_type' => 'post',
                'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'update_post_term_cache' => false,
                'meta_query' => array(
                    array(
                        'key' => self::META_REMOTE_PUBLISH_GROUP_DATE,
                        'value' => $acf_remote_publish_date,
                        'compare' => '=',
                    ),
                ),
            );

            if ( $exclude_post_id > 0 ) {
                $query_args['post__not_in'] = array( $exclude_post_id );
            }

            $posts_query = new WP_Query( $query_args );
            $time_options = self::get_remote_publish_time_options();
            $occupied_slots = array();

            foreach ( $posts_query->posts as $occupied_post_id ) {
                $remote_publish_time = get_post_meta( $occupied_post_id, self::META_REMOTE_PUBLISH_GROUP_TIME, true );
                $remote_publish_time = is_string( $remote_publish_time ) ? sanitize_text_field( $remote_publish_time ) : '';

                if ( ! isset( $time_options[ $remote_publish_time ] ) ) {
                    continue;
                }

                $post_title = get_the_title( $occupied_post_id );
                $post_title = '' !== $post_title ? $post_title : __( 'Beitrag ohne Titel', 'wp-automation-editor' );

                if ( ! isset( $occupied_slots[ $remote_publish_time ] ) ) {
                    $occupied_slots[ $remote_publish_time ] = array(
                        'time' => $remote_publish_time,
                        'postId' => absint( $occupied_post_id ),
                        'title' => html_entity_decode( $post_title, ENT_QUOTES, get_bloginfo( 'charset' ) ),
                        'titles' => array(),
                    );
                }

                $occupied_slots[ $remote_publish_time ]['titles'][] = html_entity_decode( $post_title, ENT_QUOTES, get_bloginfo( 'charset' ) );
                $occupied_slots[ $remote_publish_time ]['titles'] = array_values( array_unique( $occupied_slots[ $remote_publish_time ]['titles'] ) );
                $occupied_slots[ $remote_publish_time ]['title'] = implode( ', ', $occupied_slots[ $remote_publish_time ]['titles'] );
            }

            return array_values( $occupied_slots );
        }

        public static function get_remote_publish_occupied_times( $remote_publish_date, $exclude_post_id = 0 ) {
            $occupied_slots = self::get_remote_publish_occupied_slots( $remote_publish_date, $exclude_post_id );
            $occupied_times = array();

            foreach ( $occupied_slots as $occupied_slot ) {
                if ( isset( $occupied_slot['time'] ) ) {
                    $occupied_times[] = $occupied_slot['time'];
                }
            }

            return array_values( array_unique( $occupied_times ) );
        }

        public static function is_remote_publish_slot_taken( $remote_publish_date, $remote_publish_time, $exclude_post_id = 0 ) {
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );
            $remote_publish_time = sanitize_text_field( $remote_publish_time );
            $time_options = self::get_remote_publish_time_options();

            if ( ! self::is_valid_remote_publish_date( $remote_publish_date ) ) {
                return false;
            }

            if ( ! isset( $time_options[ $remote_publish_time ] ) ) {
                return false;
            }

            $acf_remote_publish_date = self::normalize_remote_publish_date_for_acf( $remote_publish_date );
            $exclude_post_id = absint( $exclude_post_id );

            $query_args = array(
                'post_type' => 'post',
                'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
                'fields' => 'ids',
                'posts_per_page' => 1,
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => self::META_REMOTE_PUBLISH_GROUP_DATE,
                        'value' => $acf_remote_publish_date,
                        'compare' => '=',
                    ),
                    array(
                        'key' => self::META_REMOTE_PUBLISH_GROUP_TIME,
                        'value' => $remote_publish_time,
                        'compare' => '=',
                    ),
                ),
            );

            if ( $exclude_post_id > 0 ) {
                $query_args['post__not_in'] = array( $exclude_post_id );
            }

            $posts_query = new WP_Query( $query_args );

            return ! empty( $posts_query->posts );
        }

        private static function normalize_remote_publish_date_for_input( $remote_publish_date ) {
            $remote_publish_date = is_string( $remote_publish_date ) ? trim( $remote_publish_date ) : '';

            if ( '' === $remote_publish_date ) {
                return '';
            }

            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $remote_publish_date ) ) {
                $date_parts = explode( '-', $remote_publish_date );

                if ( checkdate( (int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0] ) ) {
                    return $remote_publish_date;
                }
            }

            if ( preg_match( '/^\d{8}$/', $remote_publish_date ) ) {
                $year = substr( $remote_publish_date, 0, 4 );
                $month = substr( $remote_publish_date, 4, 2 );
                $day = substr( $remote_publish_date, 6, 2 );

                if ( checkdate( (int) $month, (int) $day, (int) $year ) ) {
                    return $year . '-' . $month . '-' . $day;
                }
            }

            return '';
        }

        private static function normalize_remote_publish_date_for_acf( $remote_publish_date ) {
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );

            if ( '' === $remote_publish_date ) {
                return '';
            }

            return str_replace( '-', '', $remote_publish_date );
        }

        public static function get_date_filter_options() {
            return array(
                '7days'  => __( 'Letzte 7 Tage', 'wp-automation-editor' ),
                '30days' => __( 'Letzte 30 Tage', 'wp-automation-editor' ),
                '90days' => __( 'Letzte 90 Tage', 'wp-automation-editor' ),
                'all'    => __( 'Alle Zeiträume', 'wp-automation-editor' ),
            );
        }

		public static function get_post_workflow_status( $post_id ) {
			$workflow_status = '';

			if ( function_exists( 'get_field' ) ) {
				$workflow_status = get_field( self::META_WORKFLOW_STATUS, $post_id );
			}

			if ( '' === $workflow_status || null === $workflow_status || false === $workflow_status ) {
				$workflow_status = get_post_meta( $post_id, self::META_WORKFLOW_STATUS, true );
			}

			if ( '' === $workflow_status || null === $workflow_status || false === $workflow_status ) {
				$workflow_status = get_post_meta( $post_id, self::META_LEGACY_WORKFLOW_STATUS, true );
			}

			return self::normalize_workflow_status_value( $workflow_status );
		}

		public static function normalize_workflow_status_value( $workflow_status ) {
			$status_options = self::get_workflow_status_options();

			if ( is_array( $workflow_status ) && isset( $workflow_status['value'] ) ) {
				$workflow_status = $workflow_status['value'];
			}

			if ( is_array( $workflow_status ) && isset( $workflow_status['label'] ) ) {
				$workflow_status = $workflow_status['label'];
			}

			$workflow_status = is_string( $workflow_status ) ? sanitize_key( $workflow_status ) : '';

			if ( isset( $status_options[ $workflow_status ] ) ) {
				return $workflow_status;
			}

			foreach ( $status_options as $status_key => $status_label ) {
				if ( sanitize_key( $status_label ) === $workflow_status ) {
					return $status_key;
				}
			}

			return 'unbearbeitet';
		}
		
		public static function update_post_workflow_status( $post_id, $workflow_status ) {
			$workflow_status = self::normalize_workflow_status_value( $workflow_status );

			if ( function_exists( 'update_field' ) ) {
				update_field( self::META_WORKFLOW_STATUS, $workflow_status, $post_id );
				delete_post_meta( $post_id, self::META_LEGACY_WORKFLOW_STATUS );

				return;
			}

			update_post_meta( $post_id, self::META_WORKFLOW_STATUS, $workflow_status );
		}

        public static function get_automation_author_id() {
            $author_login = apply_filters( 'wpa_automation_editor_author_login', self::DEFAULT_AUTHOR_LOGIN );
            $author = get_user_by( 'login', $author_login );

            if ( ! $author ) {
                return 0;
            }

            return (int) $author->ID;
        }

        public static function get_base_page_url() {
            return remove_query_arg( array( 'wpa_action', 'post_id', 'wpa_notice' ) );
        }

        public static function get_edit_url( $post_id ) {
            return add_query_arg(
                array(
                    'wpa_action' => 'edit',
                    'post_id'    => absint( $post_id ),
                ),
                self::get_base_page_url()
            );
        }

        public static function render_trash_post_form( $post_id, $redirect_url = '' ) {
            $post_id = absint( $post_id );

            if ( ! $post_id || ! current_user_can( 'delete_post', $post_id ) ) {
                return '';
            }

            if ( '' === $redirect_url ) {
                $redirect_url = self::get_base_page_url();
            }

            ob_start();
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpa-inline-action-form" onsubmit="return confirm('<?php echo esc_js( __( 'Diesen Beitrag wirklich in den Papierkorb verschieben?', 'wp-automation-editor' ) ); ?>');">
                <input type="hidden" name="action" value="wpa_trash_post">
                <input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_url ); ?>">
                <?php wp_nonce_field( 'wpa_trash_post_' . $post_id ); ?>
                <button type="submit" class="wpa-button wpa-button-danger wpa-icon-button" title="<?php esc_attr_e( 'Löschen', 'wp-automation-editor' ); ?>" aria-label="<?php esc_attr_e( 'Löschen', 'wp-automation-editor' ); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    <span class="wpa-screen-reader-text"><?php esc_html_e( 'löschen', 'wp-automation-editor' ); ?></span>
                </button>
            </form>
            <?php

            return ob_get_clean();
        }

		public static function get_dashboard_status_filter_options() {
			$status_options = self::get_workflow_status_options();

			return array(
				'offen'          => __( 'Alle offenen', 'wp-automation-editor' ),
				'unbearbeitet'   => $status_options['unbearbeitet'],
				'in_bearbeitung' => $status_options['in_bearbeitung'],
			);
		}

		public static function get_dashboard_status_keys() {
			return array(
				'unbearbeitet',
				'in_bearbeitung',
			);
		}

		public static function build_dashboard_meta_query( $status_filter ) {
			$allowed_statuses = self::get_dashboard_status_keys();

			if ( in_array( $status_filter, $allowed_statuses, true ) ) {
				return self::build_workflow_status_meta_query( array( $status_filter ) );
			}

			return self::build_workflow_status_meta_query( $allowed_statuses );
		}

		public static function build_unbearbeitet_cleanup_meta_query() {
			return self::build_workflow_status_meta_query( array( 'unbearbeitet' ) );
		}

		private static function build_workflow_status_meta_query( $status_values ) {
			$status_values = array_values( array_unique( array_map( 'sanitize_key', $status_values ) ) );

			$meta_query = array(
				'relation' => 'OR',
				array(
					'key'     => self::META_WORKFLOW_STATUS,
					'value'   => $status_values,
					'compare' => 'IN',
				),
				array(
					'relation' => 'AND',
					array(
						'key'     => self::META_WORKFLOW_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_LEGACY_WORKFLOW_STATUS,
						'value'   => $status_values,
						'compare' => 'IN',
					),
				),
			);

			if ( in_array( 'unbearbeitet', $status_values, true ) ) {
				$meta_query[] = array(
					'key'     => self::META_WORKFLOW_STATUS,
					'value'   => '',
					'compare' => '=',
				);

				$meta_query[] = array(
					'relation' => 'AND',
					array(
						'key'     => self::META_WORKFLOW_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_LEGACY_WORKFLOW_STATUS,
						'compare' => 'NOT EXISTS',
					),
				);
			}

			return $meta_query;
		}

        public static function build_dashboard_date_query( $date_filter ) {
            if ( 'all' === $date_filter ) {
                return array();
            }

            if ( '30days' === $date_filter ) {
                return array(
                    array(
                        'after'     => '30 days ago',
                        'inclusive' => true,
                    ),
                );
            }

            if ( '90days' === $date_filter ) {
                return array(
                    array(
                        'after'     => '90 days ago',
                        'inclusive' => true,
                    ),
                );
            }

            return array(
                array(
                    'after'     => '7 days ago',
                    'inclusive' => true,
                ),
            );
        }

        public static function get_lock_holder_name( $user_id ) {
            $user = get_userdata( $user_id );

            if ( ! $user ) {
                return __( 'einem anderen Benutzer', 'wp-automation-editor' );
            }

            return $user->display_name;
        }

        public static function get_newsletter_schedule() {
            static $newsletter_schedule = null;

            if ( null !== $newsletter_schedule ) {
                return $newsletter_schedule;
            }

            $newsletter_schedule = array();
            $file_path = WPA_EDITOR_PLUGIN_PATH . self::NEWSLETTER_SCHEDULE_FILE;

            if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
                return $newsletter_schedule;
            }

            $file_content = file_get_contents( $file_path );

            if ( false === $file_content ) {
                return $newsletter_schedule;
            }

            $schedule_data = json_decode( $file_content, true );

            if ( ! is_array( $schedule_data ) || empty( $schedule_data['newsletters'] ) || ! is_array( $schedule_data['newsletters'] ) ) {
                return $newsletter_schedule;
            }

            foreach ( $schedule_data['newsletters'] as $date_key => $newsletter_id ) {
                $date_key = sanitize_text_field( $date_key );
                $newsletter_id = absint( $newsletter_id );

                if ( ! self::is_valid_remote_publish_date( $date_key ) || $newsletter_id <= 0 ) {
                    continue;
                }

                $newsletter_schedule[ $date_key ] = (string) $newsletter_id;
            }

            ksort( $newsletter_schedule );

            return $newsletter_schedule;
        }

        public static function get_newsletter_schedule_by_id() {
            $newsletter_schedule = self::get_newsletter_schedule();
            $newsletter_schedule_by_id = array();

            foreach ( $newsletter_schedule as $newsletter_date => $newsletter_id ) {
                $newsletter_schedule_by_id[ $newsletter_id ] = $newsletter_date;
            }

            return $newsletter_schedule_by_id;
        }

        public static function get_newsletter_date_by_id( $newsletter_id ) {
            $newsletter_id = absint( $newsletter_id );

            if ( $newsletter_id <= 0 ) {
                return '';
            }

            $newsletter_schedule_by_id = self::get_newsletter_schedule_by_id();
            $newsletter_id = (string) $newsletter_id;

            return isset( $newsletter_schedule_by_id[ $newsletter_id ] ) ? $newsletter_schedule_by_id[ $newsletter_id ] : '';
        }

        public static function get_next_newsletter() {
            $today = wp_date( 'Y-m-d' );
            $newsletter_schedule = self::get_newsletter_schedule();

            foreach ( $newsletter_schedule as $newsletter_date => $newsletter_id ) {
                if ( $newsletter_date >= $today ) {
                    return array(
                        'date' => $newsletter_date,
                        'newsletter_id' => $newsletter_id,
                        'formatted_date' => self::format_remote_publish_date_for_notification( $newsletter_date ),
                    );
                }
            }

            return array();
        }

        public static function validate_newsletter_remote_publish_schedule( $newsletter_id, $remote_publish_date, $remote_publish_time ) {
            $newsletter_id = absint( $newsletter_id );
            $remote_publish_date = self::normalize_remote_publish_date_for_input( $remote_publish_date );
            $remote_publish_time = sanitize_text_field( $remote_publish_time );

            if ( $newsletter_id <= 0 ) {
                return new WP_Error(
                    'newsletter_missing',
                    __( 'Bitte eine Newsletter ID angeben.', 'wp-automation-editor' )
                );
            }

            $newsletter_date = self::get_newsletter_date_by_id( $newsletter_id );

            if ( '' === $newsletter_date ) {
                return new WP_Error(
                    'newsletter_unknown',
                    __( 'Diese Newsletter ID ist nicht in der Newsletter-Planung (newsletter-schedule.json) hinterlegt.', 'wp-automation-editor' )
                );
            }

            if ( ! self::is_valid_remote_publish_date( $remote_publish_date ) ) {
                return new WP_Error(
                    'newsletter_schedule_invalid',
                    __( 'Das Veröffentlichungsdatum ist ungültig.', 'wp-automation-editor' ),
                    array(
                        'newsletter_date' => $newsletter_date,
                    )
                );
            }

            if ( ! isset( self::get_remote_publish_time_options()[ $remote_publish_time ] ) ) {
                return new WP_Error(
                    'newsletter_schedule_invalid',
                    __( 'Die Veröffentlichungszeit ist ungültig.', 'wp-automation-editor' ),
                    array(
                        'newsletter_date' => $newsletter_date,
                    )
                );
            }

            if ( $remote_publish_date > $newsletter_date ) {
                return new WP_Error(
                    'newsletter_schedule_after_newsletter',
                    __( 'Die Veröffentlichung liegt nach dem Newsletter-Datum.', 'wp-automation-editor' ),
                    array(
                        'newsletter_id' => $newsletter_id,
                        'newsletter_date' => $newsletter_date,
                        'remote_publish_date' => $remote_publish_date,
                    )
                );
            }

            if ( $remote_publish_date === $newsletter_date && $remote_publish_time >= '15:00' ) {
                return new WP_Error(
                    'newsletter_schedule_after_cutoff',
                    __( 'Die Veröffentlichung am Newsletter-Tag muss vor 15:00 Uhr geplant sein.', 'wp-automation-editor' ),
                    array(
                        'newsletter_id' => $newsletter_id,
                        'newsletter_date' => $newsletter_date,
                        'remote_publish_date' => $remote_publish_date,
                    )
                );
            }

            return true;
        }

        public static function render_message( $message, $type = 'info' ) {
            return sprintf(
                '<div class="wpa-notice wpa-notice-%1$s">%2$s</div>',
                esc_attr( $type ),
                esc_html( $message )
            );
        }

        public static function render_notice() {
            if ( empty( $_GET['wpa_notice'] ) ) {
                return '';
            }

            $notice_key = sanitize_key( wp_unslash( $_GET['wpa_notice'] ) );

            if ( 0 === strpos( $notice_key, 'wpa_' ) ) {
                $notice_key = substr( $notice_key, 4 );
            }

            if ( 'newsletter_schedule_after_newsletter' === $notice_key ) {
                $newsletter_id = isset( $_GET['wpa_newsletter_id'] ) ? absint( wp_unslash( $_GET['wpa_newsletter_id'] ) ) : 0;
                $newsletter_date = isset( $_GET['wpa_newsletter_date'] ) ? sanitize_text_field( wp_unslash( $_GET['wpa_newsletter_date'] ) ) : '';
                $remote_publish_date = isset( $_GET['wpa_remote_publish_date'] ) ? sanitize_text_field( wp_unslash( $_GET['wpa_remote_publish_date'] ) ) : '';

                $formatted_newsletter_date = self::format_remote_publish_date_for_notification( $newsletter_date );
                $formatted_remote_publish_date = self::format_remote_publish_date_for_notification( $remote_publish_date );

                if ( $newsletter_id > 0 && '' !== $formatted_newsletter_date && '' !== $formatted_remote_publish_date ) {
                    return sprintf(
                        '<div class="wpa-notice wpa-notice-error">%s</div>',
                        esc_html(
                            sprintf(
                                __( 'Die ausgewählte Newsletter ID #%1$d (Newsletterdatum: %2$s) liegt vor dem Veröffentlichungsdatum %3$s. Plane den Beitrag vor oder am Newsletter-Tag vor 15:00 Uhr.', 'wp-automation-editor' ),
                                $newsletter_id,
                                $formatted_newsletter_date,
                                $formatted_remote_publish_date
                            )
                        )
                    );
                }
            }

            $notices = array(
                'saved' => array(
                    'message' => __( 'Der Beitrag wurde erfolgreich gespeichert.', 'wp-automation-editor' ),
                    'type'    => 'success',
                ),
                'invalid_post' => array(
                    'message' => __( 'Der Beitrag konnte nicht geladen werden.', 'wp-automation-editor' ),
                    'type'    => 'error',
                ),
                'invalid_nonce' => array(
                    'message' => __( 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte versuche es erneut.', 'wp-automation-editor' ),
                    'type'    => 'error',
                ),
                'forbidden' => array(
                    'message' => __( 'Du hast keine Berechtigung für diese Aktion.', 'wp-automation-editor' ),
                    'type'    => 'error',
                ),
                'save_error' => array(
                    'message' => __( 'Beim Speichern ist ein Fehler aufgetreten.', 'wp-automation-editor' ),
                    'type'    => 'error',
                ),
                'featured_image_error' => array(
                    'message' => __( 'Das Beitragsbild konnte nicht hochgeladen werden. Bitte prüfe Dateityp und Dateigrösse.', 'wp-automation-editor' ),
                    'type'    => 'error',
                ),
                'locked' => array(
                    'message' => __( 'Dieser Beitrag wird aktuell von einer anderen Person bearbeitet.', 'wp-automation-editor' ),
                    'type'    => 'error',
                ),
                'trashed' => array(
                    'message' => __( 'Der Beitrag wurde in den Papierkorb verschoben.', 'wp-automation-editor' ),
                    'type'    => 'success',
                ),
                'trash_error' => array(
                    'message' => __( 'Der Beitrag konnte nicht in den Papierkorb verschoben werden.', 'wp-automation-editor' ),
                    'type'    => 'error',
                ),
                'newsletter_unknown' => array(
                    'message' => __( 'Diese Newsletter ID ist nicht in der Newsletter-Planung (newsletter-schedule.json) hinterlegt.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'newsletter_schedule_after_newsletter' => array(
                    'message' => __( 'Wähle ein Veröffentlichungsdatum vor dem Newsletter-Datum oder plane den Beitrag am Newsletter-Tag vor 15:00 Uhr.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'newsletter_schedule_after_cutoff' => array(
                    'message' => __( 'Am Newsletter-Tag muss die Veröffentlichung auf immo-invest.ch vor 15:00 Uhr eingeplant sein.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'newsletter_missing' => array(
                    'message' => __( 'Bitte eine Newsletter ID angeben.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'newsletter_unknown' => array(
                    'message' => __( 'Diese Newsletter ID ist nicht in der Newsletter-Planung (newsletter-schedule.json) hinterlegt.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'newsletter_schedule_invalid' => array(
                    'message' => __( 'Das Veröffentlichungsdatum oder die Veröffentlichungszeit ist ungültig.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'newsletter_schedule_after_newsletter' => array(
                    'message' => __( 'Wähle ein Veröffentlichungsdatum vor dem Newsletter-Datum oder plane den Beitrag am Newsletter-Tag vor 15:00 Uhr.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'newsletter_schedule_after_cutoff' => array(
                    'message' => __( 'Am Newsletter-Tag muss die Veröffentlichung auf immo-invest.ch vor 15:00 Uhr eingeplant sein.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'schedule_incomplete' => array(
                    'message' => __( 'Bitte Veröffentlichungsdatum und Veröffentlichungszeit gemeinsam ausfüllen.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'schedule_invalid' => array(
                    'message' => __( 'Das Veröffentlichungsdatum oder die Veröffentlichungszeit ist ungültig.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
                'schedule_slot_taken' => array(
                    'message' => __( 'Dieses Veröffentlichungsdatum mit dieser Uhrzeit ist bereits durch einen anderen Beitrag belegt.', 'wp-automation-editor' ),
                    'type' => 'error',
                ),
            );

            if ( ! isset( $notices[ $notice_key ] ) ) {
                return '';
            }

            return self::render_message( $notices[ $notice_key ]['message'], $notices[ $notice_key ]['type'] );
        }

        public static function render_pagination( WP_Query $posts_query, $current_page, $current_status_filter, $current_date_filter ) {
            if ( $posts_query->max_num_pages <= 1 ) {
                return '';
            }

            $pagination_base = add_query_arg(
                array(
                    'wpa_status'   => $current_status_filter,
                    'wpa_date'     => $current_date_filter,
                    'wpa_page_num' => '%#%',
                ),
                self::get_base_page_url()
            );

            $pagination_links = paginate_links(
                array(
                    'base'      => $pagination_base,
                    'format'    => '',
                    'current'   => $current_page,
                    'total'     => $posts_query->max_num_pages,
                    'type'      => 'array',
                    'prev_text' => '«',
                    'next_text' => '»',
                )
            );

            if ( empty( $pagination_links ) ) {
                return '';
            }

            ob_start();
            echo '<div class="wpa-pagination">';

            foreach ( $pagination_links as $pagination_link ) {
                echo wp_kses_post( $pagination_link );
            }

            echo '</div>';

            return ob_get_clean();
        }
    }
}