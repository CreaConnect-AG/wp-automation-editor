<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Import_Handler' ) ) {

	class WPA_Automation_Editor_Import_Handler {

		const META_REMOTE_POST_ID = 'immo-invest_id';
		const META_PENDING_REMOTE_POST_ID = '_wpa_pending_immo_invest_id';
		const META_REMOTE_MEDIA_ID = '_wpa_remote_featured_media_id';
		const META_REMOTE_MEDIA_STATUS = '_wpa_remote_featured_media_status';
		const META_REMOTE_MEDIA_ERROR = '_wpa_remote_featured_media_error';
		const META_LAST_IMPORT_AT = '_wpa_last_import_at';
		const META_LAST_IMPORT_ERROR = '_wpa_last_import_error';
		const MEDIA_STATUS_PENDING = 'pending';
		const MEDIA_STATUS_DONE = 'done';
		const MEDIA_STATUS_FAILED = 'failed';
		const MEDIA_STATUS_SKIPPED = 'skipped';
		const REMOTE_COOLDOWN_TRANSIENT = 'wpa_automation_editor_remote_import_cooldown_until';

		public function handle_import_post() {
			if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => __( 'Keine Berechtigung für den Import.', 'wp-automation-editor' ) ), 403 );
			}

			check_ajax_referer( 'wpa_import_post_to_remote', 'nonce' );

			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

			if ( ! $post_id ) {
				wp_send_json_error( array( 'message' => __( 'Ungültiger Beitrag.', 'wp-automation-editor' ) ), 400 );
			}

			$result = $this->import_post_to_remote( $post_id );

			if ( is_wp_error( $result ) ) {
				$status_code = $this->get_error_status( $result );

				if ( $status_code < 400 ) {
					$status_code = 500;
				}

				$error_data = array(
					'message'    => $result->get_error_message(),
					'status'     => $status_code,
					'stop_queue' => $this->should_stop_queue( $result ),
				);

				try {
					$this->send_teams_import_notification( $post_id, false, $error_data );
				} catch ( Throwable $exception ) {
					$this->log_message(
						'Import Teams notification crashed for local post ' . absint( $post_id ) . ': ' . $exception->getMessage()
					);
				}

				wp_send_json_error( $error_data, $status_code );
			}

			try {
				$this->send_teams_import_notification( $post_id, true, $result );
			} catch ( Throwable $exception ) {
				$this->log_message(
					'Import Teams notification crashed for local post ' . absint( $post_id ) . ': ' . $exception->getMessage()
				);
			}

			wp_send_json_success( $result );
		}

		public function handle_import_featured_image() {
			if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => __( 'Keine Berechtigung für den Bildimport.', 'wp-automation-editor' ) ), 403 );
			}

			check_ajax_referer( 'wpa_import_featured_image_to_remote', 'nonce' );

			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			if ( ! $post_id ) {
				wp_send_json_error( array( 'message' => __( 'Ungültiger Beitrag.', 'wp-automation-editor' ) ), 400 );
			}

			$result = $this->import_featured_image_to_remote( $post_id );
			if ( is_wp_error( $result ) ) {
				$status_code = $this->get_error_status( $result );
				if ( $status_code < 400 ) {
					$status_code = 500;
				}

				wp_send_json_error(
					array(
						'message'    => $result->get_error_message(),
						'status'    => $status_code,
						'stop_queue' => $this->should_stop_queue( $result ),
					),
					$status_code
				);
			}

			wp_send_json_success( $result );
		}

		public static function get_remote_site_url() {
			$remote_site_url = defined( 'WPA_EDITOR_REMOTE_SITE_URL' ) ? WPA_EDITOR_REMOTE_SITE_URL : 'https://immo-invest.ch';
			$remote_site_url = apply_filters( 'wpa_automation_editor_remote_site_url', $remote_site_url );
			return untrailingslashit( esc_url_raw( $remote_site_url ) );
		}

		public static function get_remote_username() {
			$remote_username = defined( 'WPA_EDITOR_REMOTE_USERNAME' ) ? WPA_EDITOR_REMOTE_USERNAME : 'immovestuser';
			return (string) apply_filters( 'wpa_automation_editor_remote_username', $remote_username );
		}

		public static function get_remote_password() {
			$remote_password = defined( 'WPA_EDITOR_REMOTE_APP_PASSWORD' ) ? WPA_EDITOR_REMOTE_APP_PASSWORD : '';
			return (string) apply_filters( 'wpa_automation_editor_remote_app_password', $remote_password );
		}

		public static function has_remote_config() {
			return '' !== self::get_remote_site_url() && '' !== self::get_remote_username() && '' !== self::get_remote_password();
		}

		public static function get_remote_post_url( $remote_post_id ) {
			return self::get_remote_site_url() . '/?p=' . absint( $remote_post_id );
		}

		public static function get_remote_request_timeout() {
			$timeout = defined( 'WPA_EDITOR_REMOTE_REQUEST_TIMEOUT' ) ? absint( WPA_EDITOR_REMOTE_REQUEST_TIMEOUT ) : 12;
			return max( 3, min( 30, (int) apply_filters( 'wpa_automation_editor_remote_request_timeout', $timeout ) ) );
		}

		public static function get_remote_media_timeout() {
			$timeout = defined( 'WPA_EDITOR_REMOTE_MEDIA_TIMEOUT' ) ? absint( WPA_EDITOR_REMOTE_MEDIA_TIMEOUT ) : 20;
			return max( 5, min( 45, (int) apply_filters( 'wpa_automation_editor_remote_media_timeout', $timeout ) ) );
		}

		public static function get_remote_cooldown_seconds() {
			$seconds = defined( 'WPA_EDITOR_REMOTE_COOLDOWN_SECONDS' ) ? absint( WPA_EDITOR_REMOTE_COOLDOWN_SECONDS ) : 300;
			return max( 60, min( 1800, (int) apply_filters( 'wpa_automation_editor_remote_cooldown_seconds', $seconds ) ) );
		}

		public static function get_inter_import_delay_ms() {
			$delay = defined( 'WPA_EDITOR_IMPORT_DELAY_MS' ) ? absint( WPA_EDITOR_IMPORT_DELAY_MS ) : 4000;
			return max( 0, min( 30000, (int) apply_filters( 'wpa_automation_editor_inter_import_delay_ms', $delay ) ) );
		}

		public static function get_max_featured_image_bytes() {
			$max_bytes = defined( 'WPA_EDITOR_REMOTE_IMAGE_MAX_BYTES' ) ? absint( WPA_EDITOR_REMOTE_IMAGE_MAX_BYTES ) : 6291456;
			return max( 524288, min( 52428800, (int) apply_filters( 'wpa_automation_editor_remote_image_max_bytes', $max_bytes ) ) );
		}

		public static function should_import_featured_images() {
			$enabled = defined( 'WPA_EDITOR_REMOTE_IMPORT_FEATURED_IMAGES' ) ? (bool) WPA_EDITOR_REMOTE_IMPORT_FEATURED_IMAGES : true;
			return (bool) apply_filters( 'wpa_automation_editor_import_featured_images', $enabled );
		}

		public function import_post_to_remote( $post_id ) {
			if ( ! self::has_remote_config() ) {
				return new WP_Error( 'wpa_remote_missing_config', __( 'Die Zugangsdaten für die Zielseite fehlen.', 'wp-automation-editor' ), array( 'status' => 500 ) );
			}

			$cooldown_error = $this->get_remote_cooldown_error();
			if ( is_wp_error( $cooldown_error ) ) {
				return $cooldown_error;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
				return new WP_Error( 'wpa_invalid_post', __( 'Der Beitrag wurde nicht gefunden.', 'wp-automation-editor' ), array( 'status' => 404 ) );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'wpa_forbidden', __( 'Du darfst diesen Beitrag nicht importieren.', 'wp-automation-editor' ), array( 'status' => 403 ) );
			}

			if ( 'fertig' !== WPA_Automation_Editor_Helpers::get_post_workflow_status( $post_id ) ) {
				return new WP_Error( 'wpa_not_finished', __( 'Nur Beiträge mit Status fertig können importiert werden.', 'wp-automation-editor' ), array( 'status' => 400 ) );
			}

			$remote_category_ids = $this->get_remote_category_ids_for_post( $post_id );
			$remote_tag_ids = $this->get_remote_tag_ids_for_post( $post_id );

			$cooldown_error = $this->get_remote_cooldown_error();
			if ( is_wp_error( $cooldown_error ) ) {
				return $cooldown_error;
			}

			$remote_post_data = $this->build_remote_post_data( $post_id, $remote_category_ids, $remote_tag_ids );
			$remote_post_id = absint( get_post_meta( $post_id, self::META_REMOTE_POST_ID, true ) );
			if ( ! $remote_post_id ) {
				$remote_post_id = absint( get_post_meta( $post_id, self::META_PENDING_REMOTE_POST_ID, true ) );
			}

			if ( $remote_post_id ) {
				$response_data = $this->remote_json_request( '/wp/v2/posts/' . $remote_post_id, 'POST', $remote_post_data );
				if ( is_wp_error( $response_data ) && 404 === $this->get_error_status( $response_data ) ) {
					$remote_post_id = 0;
					delete_post_meta( $post_id, self::META_PENDING_REMOTE_POST_ID );
					delete_post_meta( $post_id, self::META_REMOTE_POST_ID );
				} elseif ( is_wp_error( $response_data ) ) {
					return $this->store_import_error( $post_id, $response_data );
				}
			} else {
				$response_data = null;
			}

			if ( ! $remote_post_id ) {
				$response_data = $this->remote_json_request( '/wp/v2/posts', 'POST', $remote_post_data );
				if ( is_wp_error( $response_data ) ) {
					return $this->store_import_error( $post_id, $response_data );
				}
			}

			if ( empty( $response_data['id'] ) ) {
				return $this->store_import_error(
					$post_id,
					new WP_Error( 'wpa_missing_remote_id', __( 'Die Zielseite hat keine Beitrags-ID zurückgegeben.', 'wp-automation-editor' ), array( 'status' => 500 ) )
				);
			}

			$remote_post_id = absint( $response_data['id'] );
			update_post_meta( $post_id, self::META_REMOTE_POST_ID, $remote_post_id );
			delete_post_meta( $post_id, self::META_PENDING_REMOTE_POST_ID );
			delete_post_meta( $post_id, self::META_LAST_IMPORT_ERROR );
			update_post_meta( $post_id, self::META_LAST_IMPORT_AT, current_time( 'mysql' ) );

			$image_status_message = $this->prepare_featured_image_status_after_post_import( $post_id );

			$remote_status = isset( $remote_post_data['status'] ) ? $remote_post_data['status'] : 'draft';
			$remote_publish_date = isset( $remote_post_data['date'] ) ? $remote_post_data['date'] : '';

			if ( 'future' === $remote_status && '' !== $remote_publish_date ) {
				$import_message = sprintf(
					__( 'Beitrag wurde auf der Zielseite vorgeplant. Remote-ID: %d. Datum/Zeit: %s.', 'wp-automation-editor' ),
					$remote_post_id,
					$remote_publish_date
				);
			} elseif ( 'draft' === $remote_status && '' !== $remote_publish_date ) {
				$import_message = sprintf(
					__( 'Beitrag wurde als Entwurf auf der Zielseite importiert und Datum/Zeit wurde gesetzt. Remote-ID: %d. Datum/Zeit: %s.', 'wp-automation-editor' ),
					$remote_post_id,
					$remote_publish_date
				);
			} else {
				$import_message = sprintf(
					__( 'Beitrag wurde als Entwurf auf der Zielseite importiert. Remote-ID: %d.', 'wp-automation-editor' ),
					$remote_post_id
				);
			}

			return array(
				'post_id'        => $post_id,
				'remote_post_id' => $remote_post_id,
				'remote_url'     => self::get_remote_post_url( $remote_post_id ),
				'message' => $import_message . ' ' . $image_status_message,
			);
		}

		public function import_featured_image_to_remote( $post_id ) {
			if ( ! self::has_remote_config() ) {
				return new WP_Error( 'wpa_remote_missing_config', __( 'Die Zugangsdaten für die Zielseite fehlen.', 'wp-automation-editor' ), array( 'status' => 500 ) );
			}

			$cooldown_error = $this->get_remote_cooldown_error();
			if ( is_wp_error( $cooldown_error ) ) {
				return $cooldown_error;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
				return new WP_Error( 'wpa_invalid_post', __( 'Der Beitrag wurde nicht gefunden.', 'wp-automation-editor' ), array( 'status' => 404 ) );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'wpa_forbidden', __( 'Du darfst das Beitragsbild dieses Beitrags nicht importieren.', 'wp-automation-editor' ), array( 'status' => 403 ) );
			}

			$remote_post_id = absint( get_post_meta( $post_id, self::META_REMOTE_POST_ID, true ) );
			if ( ! $remote_post_id ) {
				return new WP_Error( 'wpa_missing_remote_post_id', __( 'Dieser Beitrag wurde noch nicht auf die Zielseite importiert. Bitte zuerst den Beitrag importieren.', 'wp-automation-editor' ), array( 'status' => 400 ) );
			}

			if ( ! has_post_thumbnail( $post_id ) ) {
				update_post_meta( $post_id, self::META_REMOTE_MEDIA_STATUS, self::MEDIA_STATUS_SKIPPED );
				delete_post_meta( $post_id, self::META_REMOTE_MEDIA_ERROR );
				return array(
					'post_id'        => $post_id,
					'remote_post_id' => $remote_post_id,
					'remote_url'     => self::get_remote_post_url( $remote_post_id ),
					'message'        => __( 'Dieser Beitrag hat kein Beitragsbild.', 'wp-automation-editor' ),
				);
			}

			$image_result = $this->sync_featured_image( $post_id, $remote_post_id );
			if ( is_wp_error( $image_result ) ) {
				update_post_meta( $post_id, self::META_REMOTE_MEDIA_STATUS, self::MEDIA_STATUS_FAILED );
				update_post_meta( $post_id, self::META_REMOTE_MEDIA_ERROR, $image_result->get_error_message() );
				return $this->store_import_error( $post_id, $image_result );
			}

			update_post_meta( $post_id, self::META_REMOTE_MEDIA_STATUS, self::MEDIA_STATUS_DONE );
			delete_post_meta( $post_id, self::META_REMOTE_MEDIA_ERROR );
			delete_post_meta( $post_id, self::META_LAST_IMPORT_ERROR );
			update_post_meta( $post_id, self::META_LAST_IMPORT_AT, current_time( 'mysql' ) );

			return array(
				'post_id'        => $post_id,
				'remote_post_id' => $remote_post_id,
				'remote_url'     => self::get_remote_post_url( $remote_post_id ),
				'message'        => ! empty( $image_result['message'] ) ? $image_result['message'] : __( 'Beitragsbild wurde importiert und gesetzt.', 'wp-automation-editor' ),
			);
		}

		private function prepare_featured_image_status_after_post_import( $post_id ) {
			if ( ! has_post_thumbnail( $post_id ) ) {
				update_post_meta( $post_id, self::META_REMOTE_MEDIA_STATUS, self::MEDIA_STATUS_SKIPPED );
				delete_post_meta( $post_id, self::META_REMOTE_MEDIA_ERROR );
				return __( 'Kein Beitragsbild vorhanden.', 'wp-automation-editor' );
			}

			$remote_media_id = absint( get_post_meta( $post_id, self::META_REMOTE_MEDIA_ID, true ) );
			if ( $remote_media_id ) {
				update_post_meta( $post_id, self::META_REMOTE_MEDIA_STATUS, self::MEDIA_STATUS_DONE );
				delete_post_meta( $post_id, self::META_REMOTE_MEDIA_ERROR );
				return __( 'Beitragsbild war bereits importiert.', 'wp-automation-editor' );
			}

			update_post_meta( $post_id, self::META_REMOTE_MEDIA_STATUS, self::MEDIA_STATUS_PENDING );
			delete_post_meta( $post_id, self::META_REMOTE_MEDIA_ERROR );
			return __( 'Beitragsbild ist für den separaten Bildimport vorgemerkt.', 'wp-automation-editor' );
		}

		private function build_remote_post_data( $post_id, $remote_category_ids, $remote_tag_ids ) {
			$post = get_post( $post_id );

			$lead = $this->get_field_value( 'lead', $post_id );
			$quelle = $this->get_field_value( 'quelle', $post_id );
			$newsletter_id = $this->get_field_value( 'newsletter_id', $post_id );

			if ( '' === $lead ) {
				$lead = $post->post_excerpt;
			}

			$remote_publish_schedule = WPA_Automation_Editor_Helpers::get_post_remote_publish_schedule( $post_id );
			$remote_publish_datetime_string = '';

			if ( '' === (string) $newsletter_id ) {
				$remote_publish_datetime_string = WPA_Automation_Editor_Helpers::get_remote_publish_datetime_string(
					$remote_publish_schedule['date'],
					$remote_publish_schedule['time']
				);
			}

			$remote_post_status = 'draft';

			if ( '' !== $remote_publish_datetime_string && has_post_thumbnail( $post_id ) ) {
				$remote_post_status = 'future';
			}

			$remote_post_data = array(
				'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'content' => $post->post_content,
				'excerpt' => $lead,
				'status' => $remote_post_status,
				'categories' => $remote_category_ids,
				'tags' => $remote_tag_ids,
				'acf' => array(
					'field_5e4ce5116af11' => $lead,
					'field_5e4ce60cd16db' => $quelle,
					'field_67bc48a5835d3' => $post_id,
				),
			);

			if ( '' !== $remote_publish_datetime_string ) {
				$remote_post_data['date'] = $remote_publish_datetime_string;
			}

			if ( '' !== (string) $newsletter_id ) {
				$remote_post_data['acf']['newsletter_id'] = $newsletter_id;
			}

			return apply_filters( 'wpa_automation_editor_remote_post_data', $remote_post_data, $post_id );
		}

		private function get_field_value( $field_name, $post_id ) {
			$value = '';
			if ( function_exists( 'get_field' ) ) {
				$value = get_field( $field_name, $post_id );
			}

			if ( '' === $value || null === $value || false === $value ) {
				$value = get_post_meta( $post_id, $field_name, true );
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				return '';
			}

			return (string) $value;
		}

		private function get_remote_category_ids_for_post( $post_id ) {
			$remote_category_ids = array();
			$categories = get_the_category( $post_id );

			if ( empty( $categories ) ) {
				return $remote_category_ids;
			}

			foreach ( $categories as $category ) {
				$remote_category_id = $this->get_remote_category_id_by_slug( $category->slug );
				if ( $remote_category_id ) {
					$remote_category_ids[] = $remote_category_id;
				}

				if ( is_wp_error( $this->get_remote_cooldown_error() ) ) {
					break;
				}
			}

			return array_values( array_unique( array_map( 'absint', $remote_category_ids ) ) );
		}

		private function get_remote_category_id_by_slug( $slug ) {
			$slug = sanitize_title( $slug );
			if ( '' === $slug ) {
				return 0;
			}

			$cache_key = 'wpa_remote_category_' . md5( self::get_remote_site_url() . '|' . $slug );
			$cached_category_id = get_transient( $cache_key );
			if ( false !== $cached_category_id ) {
				return absint( $cached_category_id );
			}

			$response_data = $this->remote_json_request( '/wp/v2/categories?slug=' . rawurlencode( $slug ), 'GET' );
			if ( is_wp_error( $response_data ) ) {
				$this->log_message( 'Remote category lookup failed: ' . $slug . ' - ' . $response_data->get_error_message() );
				return 0;
			}

			if ( empty( $response_data[0]['id'] ) ) {
				set_transient( $cache_key, 0, 10 * MINUTE_IN_SECONDS );
				$this->log_message( 'Remote category not found: ' . $slug );
				return 0;
			}

			$remote_category_id = absint( $response_data[0]['id'] );
			set_transient( $cache_key, $remote_category_id, 12 * HOUR_IN_SECONDS );
			return $remote_category_id;
		}

		private function get_remote_tag_ids_for_post( $post_id ) {
			$remote_tag_ids = array();
			$tags = get_the_terms( $post_id, 'post_tag' );

			if ( empty( $tags ) || is_wp_error( $tags ) ) {
				return $remote_tag_ids;
			}

			foreach ( $tags as $tag ) {
				$remote_tag_id = $this->get_or_create_remote_tag_id( $tag );
				if ( $remote_tag_id ) {
					$remote_tag_ids[] = $remote_tag_id;
				}

				if ( is_wp_error( $this->get_remote_cooldown_error() ) ) {
					break;
				}
			}

			return array_values( array_unique( array_map( 'absint', $remote_tag_ids ) ) );
		}

		private function get_or_create_remote_tag_id( $tag ) {
			if ( ! $tag instanceof WP_Term ) {
				return 0;
			}

			$slug = sanitize_title( $tag->slug );
			if ( '' === $slug ) {
				return 0;
			}

			$cache_key = 'wpa_remote_tag_' . md5( self::get_remote_site_url() . '|' . $slug );
			$cached_tag_id = get_transient( $cache_key );
			if ( false !== $cached_tag_id ) {
				return absint( $cached_tag_id );
			}

			$response_data = $this->remote_json_request( '/wp/v2/tags?slug=' . rawurlencode( $slug ), 'GET' );
			if ( is_wp_error( $response_data ) ) {
				$this->log_message( 'Remote tag lookup failed: ' . $slug . ' - ' . $response_data->get_error_message() );
				return 0;
			}

			if ( ! empty( $response_data[0]['id'] ) ) {
				$remote_tag_id = absint( $response_data[0]['id'] );
				set_transient( $cache_key, $remote_tag_id, 12 * HOUR_IN_SECONDS );
				return $remote_tag_id;
			}

			$create_missing_tags = defined( 'WPA_EDITOR_REMOTE_CREATE_MISSING_TAGS' ) ? (bool) WPA_EDITOR_REMOTE_CREATE_MISSING_TAGS : true;
			$create_missing_tags = (bool) apply_filters( 'wpa_automation_editor_create_missing_remote_tags', $create_missing_tags );
			if ( ! $create_missing_tags ) {
				set_transient( $cache_key, 0, 10 * MINUTE_IN_SECONDS );
				return 0;
			}

			$create_response_data = $this->remote_json_request(
				'/wp/v2/tags',
				'POST',
				array(
					'name'        => $tag->name,
					'slug'        => $slug,
					'description' => $tag->description,
				)
			);

			if ( is_wp_error( $create_response_data ) ) {
				$this->log_message( 'Remote tag could not be created: ' . $slug . ' - ' . $create_response_data->get_error_message() );
				return 0;
			}

			if ( empty( $create_response_data['id'] ) ) {
				set_transient( $cache_key, 0, 10 * MINUTE_IN_SECONDS );
				$this->log_message( 'Remote tag could not be created: ' . $slug . ' - No tag ID returned.' );
				return 0;
			}

			$remote_tag_id = absint( $create_response_data['id'] );
			set_transient( $cache_key, $remote_tag_id, 12 * HOUR_IN_SECONDS );
			return $remote_tag_id;
		}

		private function sync_featured_image( $post_id, $remote_post_id ) {
			if ( ! self::should_import_featured_images() ) {
				return array( 'message' => __( 'Beitragsbild-Import ist deaktiviert.', 'wp-automation-editor' ) );
			}

			$thumbnail_id = get_post_thumbnail_id( $post_id );
			if ( ! $thumbnail_id ) {
				return array( 'message' => __( 'Kein Beitragsbild vorhanden.', 'wp-automation-editor' ) );
			}

			$remote_media_id = absint( get_post_meta( $post_id, self::META_REMOTE_MEDIA_ID, true ) );
			if ( ! $remote_media_id ) {
				$media_file = $this->get_local_media_file( $thumbnail_id );
				if ( is_wp_error( $media_file ) ) {
					return $media_file;
				}

				$remote_media_id = $this->upload_media_to_remote( $media_file, $remote_post_id );
				if ( is_wp_error( $remote_media_id ) ) {
					return $remote_media_id;
				}

				update_post_meta( $post_id, self::META_REMOTE_MEDIA_ID, $remote_media_id );
			}

			$update_response = $this->remote_json_request(
				'/wp/v2/posts/' . absint( $remote_post_id ),
				'POST',
				array( 'featured_media' => absint( $remote_media_id ) )
			);

			if ( is_wp_error( $update_response ) ) {
				return $update_response;
			}

			return array( 'message' => __( 'Beitragsbild wurde gesetzt.', 'wp-automation-editor' ) );
		}

		private function get_local_media_file( $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			$file_name = $file_path ? basename( $file_path ) : '';

			if ( $file_path && file_exists( $file_path ) && is_readable( $file_path ) ) {
				$file_size = filesize( $file_path );
				if ( false !== $file_size && $file_size > self::get_max_featured_image_bytes() ) {
					return new WP_Error(
						'wpa_image_too_large',
						sprintf( __( 'Das Beitragsbild ist zu gross für den sicheren Import. Dateigrösse: %1$s. Limit: %2$s.', 'wp-automation-editor' ), size_format( $file_size ), size_format( self::get_max_featured_image_bytes() ) ),
						array( 'status' => 413 )
					);
				}

				return array(
					'path'      => $file_path,
					'name'      => sanitize_file_name( $file_name ),
					'mime_type' => $this->get_attachment_mime_type( $attachment_id, $file_name ),
				);
			}

			$image_url = wp_get_attachment_url( $attachment_id );
			if ( ! $image_url ) {
				return new WP_Error( 'wpa_image_missing', __( 'Das Beitragsbild konnte lokal nicht gefunden werden.', 'wp-automation-editor' ), array( 'status' => 404 ) );
			}

			$response = wp_remote_get(
				$image_url,
				array(
					'timeout'     => 10,
					'redirection' => 2,
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'wpa_image_download_failed', $response->get_error_message(), array( 'status' => 500 ) );
			}

			$response_code = (int) wp_remote_retrieve_response_code( $response );
			if ( $response_code < 200 || $response_code >= 300 ) {
				return new WP_Error( 'wpa_image_download_failed', sprintf( __( 'Bilddownload fehlgeschlagen. HTTP-Code: %d', 'wp-automation-editor' ), $response_code ), array( 'status' => $response_code ) );
			}

			$image_body = wp_remote_retrieve_body( $response );
			if ( strlen( $image_body ) > self::get_max_featured_image_bytes() ) {
				return new WP_Error(
					'wpa_image_too_large',
					sprintf( __( 'Das Beitragsbild ist zu gross für den sicheren Import. Dateigrösse: %1$s. Limit: %2$s.', 'wp-automation-editor' ), size_format( strlen( $image_body ) ), size_format( self::get_max_featured_image_bytes() ) ),
					array( 'status' => 413 )
				);
			}

			$temporary_file = wp_tempnam( basename( parse_url( $image_url, PHP_URL_PATH ) ) );
			if ( ! $temporary_file ) {
				return new WP_Error( 'wpa_temp_file_failed', __( 'Temporäre Bilddatei konnte nicht erstellt werden.', 'wp-automation-editor' ), array( 'status' => 500 ) );
			}

			file_put_contents( $temporary_file, $image_body );

			return array(
				'path'      => $temporary_file,
				'name'      => sanitize_file_name( basename( parse_url( $image_url, PHP_URL_PATH ) ) ),
				'mime_type' => $this->get_attachment_mime_type( $attachment_id, $image_url ),
				'temporary' => true,
			);
		}

		private function upload_media_to_remote( $media_file, $remote_post_id ) {
			$file_contents = file_get_contents( $media_file['path'] );
			if ( false === $file_contents || '' === $file_contents ) {
				return new WP_Error( 'wpa_empty_image', __( 'Die Bilddatei ist leer oder nicht lesbar.', 'wp-automation-editor' ), array( 'status' => 500 ) );
			}

			$file_name = ! empty( $media_file['name'] ) ? $media_file['name'] : 'featured-image.jpg';
			$mime_type = ! empty( $media_file['mime_type'] ) ? $media_file['mime_type'] : 'application/octet-stream';
			$endpoint = '/wp/v2/media?post=' . absint( $remote_post_id );

			$response = $this->remote_raw_request(
				$endpoint,
				array(
					'method'      => 'POST',
					'timeout'     => self::get_remote_media_timeout(),
					'redirection' => 2,
					'headers'     => array(
						'Authorization'       => $this->get_auth_header(),
						'Accept'              => 'application/json',
						'Content-Type'        => $mime_type,
						'Content-Disposition' => 'attachment; filename="' . $file_name . '"',
						'Connection'          => 'close',
						'Expect'              => '',
					),
					'body'        => $file_contents,
				)
			);

			if ( ! empty( $media_file['temporary'] ) ) {
				@unlink( $media_file['path'] );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = (int) wp_remote_retrieve_response_code( $response );
			$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $response_code < 200 || $response_code >= 300 ) {
				$message = is_array( $response_body ) && ! empty( $response_body['message'] ) ? $response_body['message'] : sprintf( __( 'Medien-Upload fehlgeschlagen. HTTP-Code: %d', 'wp-automation-editor' ), $response_code );
				$error = new WP_Error( 'wpa_remote_media_failed', wp_strip_all_tags( $message ), array( 'status' => $response_code ) );
				$this->set_cooldown_for_temporary_error( $error );
				return $error;
			}

			if ( empty( $response_body['id'] ) ) {
				return new WP_Error( 'wpa_remote_media_missing_id', __( 'Die Zielseite hat keine Medien-ID zurückgegeben.', 'wp-automation-editor' ), array( 'status' => 500 ) );
			}

			return absint( $response_body['id'] );
		}

		private function get_attachment_mime_type( $attachment_id, $file_name ) {
			$mime_type = get_post_mime_type( $attachment_id );
			if ( $mime_type ) {
				return $mime_type;
			}

			$file_type = wp_check_filetype( $file_name );
			return ! empty( $file_type['type'] ) ? $file_type['type'] : 'application/octet-stream';
		}

		private function remote_json_request( $endpoint, $method = 'GET', $body = null ) {
			$args = array(
				'method'      => $method,
				'timeout'     => self::get_remote_request_timeout(),
				'redirection' => 2,
				'headers'     => array(
					'Authorization' => $this->get_auth_header(),
					'Accept'        => 'application/json',
					'Connection'    => 'close',
				),
			);

			if ( null !== $body ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
			}

			$response = $this->remote_raw_request( $endpoint, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = (int) wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $response_body, true );

			if ( $response_code < 200 || $response_code >= 300 ) {
				$message = is_array( $response_data ) && ! empty( $response_data['message'] ) ? $response_data['message'] : sprintf( __( 'Remote-Anfrage fehlgeschlagen. HTTP-Code: %d', 'wp-automation-editor' ), $response_code );
				$error = new WP_Error( 'wpa_remote_request_failed', wp_strip_all_tags( $message ), array( 'status' => $response_code ) );
				$this->set_cooldown_for_temporary_error( $error );
				return $error;
			}

			if ( null === $response_data ) {
				return array();
			}

			return $response_data;
		}

		private function remote_raw_request( $endpoint, $args ) {
			$cooldown_error = $this->get_remote_cooldown_error();
			if ( is_wp_error( $cooldown_error ) ) {
				return $cooldown_error;
			}

			$response = wp_remote_request( self::get_remote_site_url() . '/wp-json' . $endpoint, $args );
			if ( is_wp_error( $response ) ) {
				$error = new WP_Error(
					'wpa_remote_http_error',
					sprintf( __( 'Remote-Anfrage abgebrochen oder fehlgeschlagen: %s', 'wp-automation-editor' ), $response->get_error_message() ),
					array( 'status' => 503, 'stop_queue' => true )
				);
				$this->start_remote_cooldown();
				return $error;
			}

			return $response;
		}

		private function get_auth_header() {
			return 'Basic ' . base64_encode( self::get_remote_username() . ':' . self::get_remote_password() );
		}

		private function get_remote_cooldown_error() {
			$cooldown_until = get_transient( self::REMOTE_COOLDOWN_TRANSIENT );
			if ( ! $cooldown_until ) {
				return false;
			}

			$remaining_seconds = max( 1, absint( $cooldown_until ) - time() );
			if ( $remaining_seconds <= 0 ) {
				delete_transient( self::REMOTE_COOLDOWN_TRANSIENT );
				return false;
			}

			return new WP_Error(
				'wpa_remote_cooldown',
				sprintf( __( 'Die Zielseite wirkt gerade überlastet. Der Import wurde zum Schutz gestoppt. Bitte in ca. %d Sekunden erneut versuchen.', 'wp-automation-editor' ), $remaining_seconds ),
				array( 'status' => 503, 'stop_queue' => true, 'retry_after' => $remaining_seconds )
			);
		}

		private function start_remote_cooldown() {
			$cooldown_seconds = self::get_remote_cooldown_seconds();
			set_transient( self::REMOTE_COOLDOWN_TRANSIENT, time() + $cooldown_seconds, $cooldown_seconds );
		}

		private function set_cooldown_for_temporary_error( WP_Error $error ) {
			if ( $this->should_stop_queue( $error ) ) {
				$this->start_remote_cooldown();
			}
		}

		private function get_error_status( WP_Error $error ) {
			$error_data = $error->get_error_data();
			if ( is_array( $error_data ) && ! empty( $error_data['status'] ) ) {
				return absint( $error_data['status'] );
			}

			return 500;
		}

		private function should_stop_queue( WP_Error $error ) {
			$error_data = $error->get_error_data();
			if ( is_array( $error_data ) && ! empty( $error_data['stop_queue'] ) ) {
				return true;
			}

			$status = $this->get_error_status( $error );
			return in_array( $status, array( 408, 429, 500, 502, 503, 504 ), true );
		}

		private function store_import_error( $post_id, WP_Error $error ) {
			update_post_meta( $post_id, self::META_LAST_IMPORT_ERROR, $error->get_error_message() );
			$this->log_message( 'Import failed for local post ' . absint( $post_id ) . ': ' . $error->get_error_message() );
			return $error;
		}

		private static function get_import_teams_webhook_url() {
			$webhook_url = defined( 'WPA_EDITOR_IMPORT_TEAMS_WEBHOOK_URL' )
				? WPA_EDITOR_IMPORT_TEAMS_WEBHOOK_URL
				: 'https://YOUR-IMPORT-WEBHOOK-URL-HERE';

			return (string) apply_filters( 'wpa_automation_editor_import_teams_webhook_url', $webhook_url );
		}

		private function send_teams_import_notification( $post_id, $import_successful, $result_data = array() ) {
			$webhook_url = self::get_import_teams_webhook_url();

			if ( empty( $webhook_url ) || 'https://YOUR-IMPORT-WEBHOOK-URL-HERE' === $webhook_url ) {
				return;
			}

			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				return;
			}

			$current_user = wp_get_current_user();

			$remote_post_id = 0;
			$remote_url = '';
			$message = '';
			$status_code = 0;
			$stop_queue = false;

			if ( is_array( $result_data ) ) {
				$remote_post_id = ! empty( $result_data['remote_post_id'] ) ? absint( $result_data['remote_post_id'] ) : 0;
				$remote_url = ! empty( $result_data['remote_url'] ) ? esc_url_raw( $result_data['remote_url'] ) : '';
				$message = ! empty( $result_data['message'] ) ? sanitize_textarea_field( (string) $result_data['message'] ) : '';
				$status_code = ! empty( $result_data['status'] ) ? absint( $result_data['status'] ) : 0;
				$stop_queue = ! empty( $result_data['stop_queue'] );
			}

			if ( ! $remote_post_id ) {
				$remote_post_id = absint( get_post_meta( $post_id, self::META_REMOTE_POST_ID, true ) );
			}

			if ( ! $remote_url && $remote_post_id ) {
				$remote_url = self::get_remote_post_url( $remote_post_id );
			}

			$has_featured_image = has_post_thumbnail( $post_id );
			$remote_media_status = (string) get_post_meta( $post_id, self::META_REMOTE_MEDIA_STATUS, true );
			$remote_media_error = (string) get_post_meta( $post_id, self::META_REMOTE_MEDIA_ERROR, true );

			$featured_image_status = 'unknown';
			$featured_image_status_label = __( 'Unbekannt', 'wp-automation-editor' );

			if ( ! $has_featured_image ) {
				$featured_image_status = 'not_set';
				$featured_image_status_label = __( 'Kein Beitragsbild gesetzt.', 'wp-automation-editor' );
			} elseif ( self::MEDIA_STATUS_DONE === $remote_media_status ) {
				$featured_image_status = 'set';
				$featured_image_status_label = __( 'Beitragsbild wurde gesetzt.', 'wp-automation-editor' );
			} elseif ( self::MEDIA_STATUS_PENDING === $remote_media_status ) {
				$featured_image_status = 'pending';
				$featured_image_status_label = __( 'Beitragsbild ist für den Import vorgemerkt.', 'wp-automation-editor' );
			} elseif ( self::MEDIA_STATUS_FAILED === $remote_media_status ) {
				$featured_image_status = 'failed';
				$featured_image_status_label = __( 'Beitragsbild-Import fehlgeschlagen.', 'wp-automation-editor' );
			} elseif ( self::MEDIA_STATUS_SKIPPED === $remote_media_status ) {
				$featured_image_status = 'skipped';
				$featured_image_status_label = __( 'Beitragsbild-Import übersprungen.', 'wp-automation-editor' );
			}

			$midjourney_prompt_en = '';

			if ( ! $has_featured_image ) {
				$midjourney_prompt_en = $this->get_field_value( 'midjourney_prompt_en', $post_id );
				$midjourney_prompt_en = sanitize_textarea_field( (string) $midjourney_prompt_en );
			}

			$publish_information = WPA_Automation_Editor_Helpers::get_post_publish_information_for_notification( $post_id, 'import' );

			$payload = array(
				'notification_type'           => 'remote_import',
				'import_successful'           => (bool) $import_successful,
				'import_status'               => $import_successful ? 'successful' : 'failed',
				'title'                       => get_the_title( $post_id ),
				'post_id'                     => $post_id,
				'post_url'                    => get_permalink( $post_id ),
				'edit_url'                    => WPA_Automation_Editor_Helpers::get_edit_url( $post_id ),
				'author'                      => $current_user && $current_user->exists() ? $current_user->display_name : '',
				'imported_at'                 => current_time( 'mysql' ),
				'remote_post_id'              => $remote_post_id,
				'remote_url'                  => $remote_url,
				'message'                     => $message,
				'publish_information' 		  => $publish_information,
				'status_code'                 => $status_code,
				'stop_queue'                  => $stop_queue,
				'has_featured_image'          => $has_featured_image,
				'featured_image_status'       => $featured_image_status,
				'featured_image_status_label' => $featured_image_status_label,
				'remote_media_status'         => $remote_media_status,
				'remote_media_error'          => $remote_media_error,
				'midjourney_prompt_en'        => $midjourney_prompt_en,
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
				$this->log_message( 'Import Teams notification failed for local post ' . absint( $post_id ) . ': ' . $response->get_error_message() );
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 > $response_code || 299 < $response_code ) {
				$this->log_message( 'Import Teams notification failed for local post ' . absint( $post_id ) . ' with HTTP status: ' . $response_code );
			}
		}

		private function log_message( $message ) {
			$upload_dir = wp_upload_dir();
			if ( empty( $upload_dir['basedir'] ) ) {
				return;
			}

			$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-automation-editor';
			if ( ! wp_mkdir_p( $log_dir ) ) {
				return;
			}

			$log_file = trailingslashit( $log_dir ) . 'import.log';
			file_put_contents( $log_file, '[' . current_time( 'mysql' ) . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX );
		}
	}
}
