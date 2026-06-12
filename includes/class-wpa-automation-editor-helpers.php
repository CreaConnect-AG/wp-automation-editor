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
        const DEFAULT_AUTHOR_LOGIN = 'wp-automation';
        const POSTS_PER_PAGE = 20;
		const CLEANUP_CRON_HOOK = 'wpa_automation_editor_cleanup_old_unbearbeitet_posts';

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