<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Image_Import_Shortcode' ) ) {

	class WPA_Automation_Editor_Image_Import_Shortcode {

		const SHORTCODE = 'wpa_automation_image_import';

		public function render_shortcode( $atts = array() ) {
			if ( ! is_user_logged_in() ) {
				return WPA_Automation_Editor_Helpers::render_message( __( 'Bitte zuerst einloggen, um den Bildimport zu öffnen.', 'wp-automation-editor' ), 'info' );
			}

			if ( ! current_user_can( 'edit_posts' ) ) {
				return WPA_Automation_Editor_Helpers::render_message( __( 'Du hast keine Berechtigung, diese Seite zu verwenden.', 'wp-automation-editor' ), 'error' );
			}

			$author_id = WPA_Automation_Editor_Helpers::get_automation_author_id();
			if ( ! $author_id ) {
				return WPA_Automation_Editor_Helpers::render_message( __( 'Der Benutzer wp-automation wurde nicht gefunden.', 'wp-automation-editor' ), 'error' );
			}

			$atts = shortcode_atts(
				array(
					'limit' => WPA_Automation_Editor_Helpers::POSTS_PER_PAGE,
				),
				$atts,
				self::SHORTCODE
			);

			$posts_per_page = max( 1, min( 100, absint( $atts['limit'] ) ) );
			$current_page = isset( $_GET['wpa_image_import_page'] ) ? max( 1, absint( $_GET['wpa_image_import_page'] ) ) : 1;

			$this->enqueue_assets();

			$query_args = array(
				'post_type'           => 'post',
				'post_status'         => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'author'              => $author_id,
				'posts_per_page'      => $posts_per_page,
				'paged'               => $current_page,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'meta_query'          => $this->build_image_import_meta_query(),
			);

			$posts_query = new WP_Query( $query_args );
			$remote_configured = WPA_Automation_Editor_Import_Handler::has_remote_config();

			ob_start();
			?>
			<div class="wpa-editor-wrap wpa-import-wrap" data-wpa-image-import>
				<?php if ( ! $remote_configured ) : ?>
					<?php echo WPA_Automation_Editor_Helpers::render_message( __( 'Die Zielseite ist noch nicht konfiguriert. Bitte die Konstanten in wp-config.php setzen.', 'wp-automation-editor' ), 'error' ); ?>
				<?php endif; ?>

				<div class="wpa-card">
					<div class="wpa-card-header">
						<div>
							<h2><?php esc_html_e( 'Fehlende Beitragsbilder importieren', 'wp-automation-editor' ); ?></h2>
							<p><?php esc_html_e( 'Es werden Beiträge angezeigt, die bereits auf der Zielseite erstellt wurden und bei denen das Beitragsbild noch nicht sicher abgeschlossen ist. Beiträge ohne lokales WordPress-Beitragsbild werden zur Kontrolle angezeigt, können aber nicht importiert werden.', 'wp-automation-editor' ); ?></p>
							<?php if ( $posts_query->post_count > 0 ) : ?>

								<div class="wpa-import-actions wpa-image-import-reset-actions">
									<button type="button" class="wpa-button wpa-button-danger" data-wpa-image-import-reset-list>
										<?php esc_html_e( 'Bildimport-Liste zurücksetzen', 'wp-automation-editor' ); ?>
									</button>

									<span class="wpa-import-progress" data-wpa-image-import-reset-progress></span>
								</div>

							<?php endif; ?>
						</div>
					</div>
				</div>

				<div class="wpa-card">
					<?php if ( $posts_query->have_posts() ) : ?>
						<div class="wpa-import-actions">
							<button type="button" class="wpa-button wpa-button-primary" data-wpa-image-import-selected <?php disabled( ! $remote_configured ); ?>>
								<?php esc_html_e( 'Ausgewählte Beitragsbilder importieren', 'wp-automation-editor' ); ?>
							</button>
							<span class="wpa-import-progress" data-wpa-image-import-progress></span>
						</div>

						<div class="wpa-table-wrap">
							<table class="wpa-post-table wpa-import-table">
								<thead>
									<tr>
										<th><input type="checkbox" data-wpa-image-import-check-all></th>
										<th><?php esc_html_e( 'Titel', 'wp-automation-editor' ); ?></th>
										<th><?php esc_html_e( 'Remote-Beitrag', 'wp-automation-editor' ); ?></th>
										<th><?php esc_html_e( 'Bildstatus', 'wp-automation-editor' ); ?></th>
										<th><?php esc_html_e( 'Aktion', 'wp-automation-editor' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php while ( $posts_query->have_posts() ) : $posts_query->the_post(); ?>
										<?php
										$post_id = get_the_ID();
										$remote_post_id = absint( get_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_REMOTE_POST_ID, true ) );
										$remote_link = WPA_Automation_Editor_Import_Handler::get_remote_post_url( $remote_post_id );
										$media_status = get_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_STATUS, true );
										$media_error = get_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_ERROR, true );
										$local_thumbnail_id = get_post_thumbnail_id( $post_id );
										$has_local_thumbnail = ! empty( $local_thumbnail_id );
										$media_status = $this->maybe_prepare_missing_image_status( $post_id, $media_status, $has_local_thumbnail );
										?>
										<tr data-wpa-image-import-row="<?php echo esc_attr( $post_id ); ?>">
											<td><input type="checkbox" data-wpa-image-import-checkbox value="<?php echo esc_attr( $post_id ); ?>" <?php disabled( ! $remote_configured || ! $has_local_thumbnail ); ?>></td>
											<td>
												<strong><a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title() ); ?></a></strong>
												<div class="wpa-post-meta-line"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 16 ) ); ?></div>
											</td>
											<td><a href="<?php echo esc_url( $remote_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $remote_link ); ?></a></td>
											<td data-wpa-image-import-status>
												<?php echo esc_html( $this->get_status_label( $media_status, $has_local_thumbnail ) ); ?>
												<?php if ( ! $has_local_thumbnail ) : ?>
													<div class="wpa-post-meta-line"><?php esc_html_e( 'Auf der Quellseite ist kein WordPress-Beitragsbild gesetzt.', 'wp-automation-editor' ); ?></div>
												<?php endif; ?>
												<?php if ( $media_error ) : ?>
													<div class="wpa-post-meta-line"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $media_error ), 18 ) ); ?></div>
												<?php endif; ?>
											</td>
											<td>
												<button type="button" class="wpa-button wpa-button-primary" data-wpa-image-import-one="<?php echo esc_attr( $post_id ); ?>" <?php disabled( ! $remote_configured || ! $has_local_thumbnail ); ?>>
													<?php echo WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_FAILED === $media_status ? esc_html__( 'Erneut versuchen', 'wp-automation-editor' ) : esc_html__( 'Bild importieren', 'wp-automation-editor' ); ?>
												</button>
											</td>
										</tr>
									<?php endwhile; ?>
								</tbody>
							</table>
						</div>
						<?php echo $this->render_pagination( $posts_query, $current_page ); ?>
					<?php else : ?>
						<div class="wpa-empty-state">
							<p><?php esc_html_e( 'Es wurden keine fehlenden Beitragsbilder gefunden.', 'wp-automation-editor' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
			wp_reset_postdata();
			return ob_get_clean();
		}

		private function enqueue_assets() {
			wp_enqueue_style(
				'wpa-frontend-editor',
				WPA_EDITOR_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				WPA_EDITOR_PLUGIN_VERSION
			);

			wp_enqueue_script(
				'wpa-automation-image-import',
				WPA_EDITOR_PLUGIN_URL . 'assets/js/image-import.js',
				array(),
				WPA_EDITOR_PLUGIN_VERSION,
				true
			);

			wp_localize_script(
				'wpa-automation-image-import',
				'wpaAutomationImageImport',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'wpa_import_featured_image_to_remote' ),
					'resetNonce' => wp_create_nonce( 'wpa_reset_featured_image_import_list' ),
					'confirmMessage' => __( 'Ausgewählte Beitragsbilder jetzt nacheinander importieren?', 'wp-automation-editor' ),
					'resetConfirmMessage' => __( 'Diese Aktion entfernt alle aktuell ausstehenden, fehlgeschlagenen oder übersprungenen Beitragsbild-Importe aus dieser Liste. Lokale Bilder und bereits importierte Bilder auf der Zielseite werden nicht gelöscht. Fortfahren?', 'wp-automation-editor' ),
					'importingMessage' => __( 'Bildimport läuft...', 'wp-automation-editor' ),
					'finishedMessage' => __( 'Bildimport abgeschlossen.', 'wp-automation-editor' ),
					'errorMessage' => __( 'Bildimport fehlgeschlagen.', 'wp-automation-editor' ),
					'resettingMessage' => __( 'Bildimport-Liste wird zurückgesetzt...', 'wp-automation-editor' ),
					'resetErrorMessage' => __( 'Die Bildimport-Liste konnte nicht zurückgesetzt werden.', 'wp-automation-editor' ),
					'emptyAfterResetMessage' => __( 'Die Bildimport-Liste wurde zurückgesetzt. Es werden keine fehlenden Beitragsbilder mehr angezeigt.', 'wp-automation-editor' ),
					'stoppedMessage' => __( 'Bildimport gestoppt, weil die Zielseite überlastet wirkt.', 'wp-automation-editor' ),
					'delayMs' => WPA_Automation_Editor_Import_Handler::get_inter_import_delay_ms(),
				)
			);
		}

		private function build_image_import_meta_query() {
			return array(
				'relation' => 'AND',
				array(
					'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_POST_ID,
					'value'   => array( '', '0' ),
					'compare' => 'NOT IN',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_STATUS,
						'value'   => WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_RESET,
						'compare' => '!=',
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_ID,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_ID,
						'value'   => array( '', '0' ),
						'compare' => 'IN',
					),
					array(
						'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_STATUS,
						'value'   => array(
							WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_PENDING,
							WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_FAILED,
							WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_SKIPPED,
						),
						'compare' => 'IN',
					),
				),
			);
		}

		private function maybe_prepare_missing_image_status( $post_id, $media_status, $has_local_thumbnail ) {
			if ( WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_RESET === $media_status ) {
				return $media_status;
			}

			$remote_media_id = absint( get_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_ID, true ) );

			if ( $has_local_thumbnail && ! $remote_media_id && WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_DONE !== $media_status ) {
				$media_status = WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_PENDING;
				update_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_REMOTE_MEDIA_STATUS, $media_status );
			}

			return $media_status;
		}

		private function get_status_label( $media_status, $has_local_thumbnail = true ) {
			if ( ! $has_local_thumbnail ) {
				return __( 'Kein lokales Beitragsbild erkannt', 'wp-automation-editor' );
			}

			switch ( $media_status ) {
				case WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_FAILED:
					return __( 'Fehlgeschlagen. Bitte erneut versuchen.', 'wp-automation-editor' );
				case WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_PENDING:
					return __( 'Ausstehend', 'wp-automation-editor' );
				case WPA_Automation_Editor_Import_Handler::MEDIA_STATUS_SKIPPED:
					return __( 'Übersprungen. Erneut prüfbar.', 'wp-automation-editor' );
				default:
					return __( 'Noch nicht importiert', 'wp-automation-editor' );
			}
		}

		private function render_pagination( WP_Query $posts_query, $current_page ) {
			if ( $posts_query->max_num_pages <= 1 ) {
				return '';
			}

			$pagination_links = paginate_links(
				array(
					'base'      => add_query_arg( 'wpa_image_import_page', '%#%' ),
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
