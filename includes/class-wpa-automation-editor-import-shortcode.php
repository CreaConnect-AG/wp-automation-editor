<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Import_Shortcode' ) ) {

	class WPA_Automation_Editor_Import_Shortcode {

		const SHORTCODE = 'wpa_automation_import';

		public function render_shortcode( $atts = array() ) {
			if ( ! is_user_logged_in() ) {
				return WPA_Automation_Editor_Helpers::render_message( __( 'Bitte zuerst einloggen, um den Import zu öffnen.', 'wp-automation-editor' ), 'info' );
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
			$current_page = isset( $_GET['wpa_import_page'] ) ? max( 1, absint( $_GET['wpa_import_page'] ) ) : 1;

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
				'meta_query'          => $this->build_importable_meta_query(),
			);

			$posts_query = new WP_Query( $query_args );
			$remote_configured = WPA_Automation_Editor_Import_Handler::has_remote_config();

			ob_start();
			?>
			<div class="wpa-editor-wrap wpa-import-wrap" data-wpa-import>
				<?php if ( ! $remote_configured ) : ?>
					<?php echo WPA_Automation_Editor_Helpers::render_message( __( 'Die Zielseite ist noch nicht konfiguriert. Bitte die Konstanten in wp-config.php setzen.', 'wp-automation-editor' ), 'error' ); ?>
				<?php endif; ?>

				<div class="wpa-card">
					<div class="wpa-card-header">
						<div>
							<h2><?php esc_html_e( 'Fertige Beiträge importieren', 'wp-automation-editor' ); ?></h2>
							<p><?php esc_html_e( 'Es werden nur Beiträge mit Workflow-Status fertig angezeigt, die noch nicht importiert wurden. Dieser Import erstellt nur den Beitrag. Beitragsbilder werden separat über den Bildimport nachgezogen.', 'wp-automation-editor' ); ?></p>
						</div>
					</div>
				</div>

				<div class="wpa-card">
					<?php if ( $posts_query->have_posts() ) : ?>
						<div class="wpa-import-actions">
							<button type="button" class="wpa-button wpa-button-primary" data-wpa-import-selected <?php disabled( ! $remote_configured ); ?>>
								<?php esc_html_e( 'Ausgewählte Beiträge importieren', 'wp-automation-editor' ); ?>
							</button>
							<span class="wpa-import-progress" data-wpa-import-progress></span>
						</div>

						<div class="wpa-table-wrap">
							<table class="wpa-post-table wpa-import-table">
								<thead>
									<tr>
										<th><input type="checkbox" data-wpa-import-check-all></th>
										<th><?php esc_html_e( 'Titel', 'wp-automation-editor' ); ?></th>
										<th><?php esc_html_e( 'Datum', 'wp-automation-editor' ); ?></th>
										<th><?php esc_html_e( 'Beitragsbild', 'wp-automation-editor' ); ?></th>
										<th><?php esc_html_e( 'Importstatus', 'wp-automation-editor' ); ?></th>
										<th><?php esc_html_e( 'Aktion', 'wp-automation-editor' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php while ( $posts_query->have_posts() ) : $posts_query->the_post(); ?>
										<?php
										$post_id = get_the_ID();
										$remote_post_id = absint( get_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_REMOTE_POST_ID, true ) );
										$pending_remote_post_id = absint( get_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_PENDING_REMOTE_POST_ID, true ) );
										$last_import_error = get_post_meta( $post_id, WPA_Automation_Editor_Import_Handler::META_LAST_IMPORT_ERROR, true );
										$remote_link = $remote_post_id ? WPA_Automation_Editor_Import_Handler::get_remote_post_url( $remote_post_id ) : '';
										
										$redirect_url = add_query_arg(
											'wpa_import_page',
											$current_page,
											WPA_Automation_Editor_Helpers::get_base_page_url()
										);
										?>
										<tr data-wpa-import-row="<?php echo esc_attr( $post_id ); ?>">
											<td><input type="checkbox" data-wpa-import-checkbox value="<?php echo esc_attr( $post_id ); ?>" <?php disabled( ! $remote_configured ); ?>></td>
											<td>
												<strong><a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title() ); ?></a></strong>
												<div class="wpa-post-meta-line"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 16 ) ); ?></div>
											</td>
											<td><?php echo esc_html( get_the_date( 'd.m.Y H:i', $post_id ) ); ?></td>
											<td><?php echo has_post_thumbnail( $post_id ) ? esc_html__( 'Ja', 'wp-automation-editor' ) : esc_html__( 'Nein', 'wp-automation-editor' ); ?></td>
											<td data-wpa-import-status>
												<?php if ( $remote_post_id ) : ?>
													<a href="<?php echo esc_url( $remote_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $remote_link ); ?></a>
												<?php elseif ( $pending_remote_post_id ) : ?>
													<?php echo esc_html( sprintf( __( 'Teilweise vorbereitet. Remote-ID: %d. Bitte erneut versuchen.', 'wp-automation-editor' ), $pending_remote_post_id ) ); ?>
													<?php if ( $last_import_error ) : ?>
														<div class="wpa-post-meta-line"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $last_import_error ), 18 ) ); ?></div>
													<?php endif; ?>
												<?php else : ?>
													<?php esc_html_e( 'Noch nicht importiert', 'wp-automation-editor' ); ?>
												<?php endif; ?>
											</td>
											<td class="actions">
												<button type="button" class="wpa-button wpa-button-primary wpa-icon-button" data-wpa-import-one="<?php echo esc_attr( $post_id ); ?>" title="<?php echo esc_attr( $pending_remote_post_id ? __( 'Erneut versuchen', 'wp-automation-editor' ) : __( 'Importieren', 'wp-automation-editor' ) ); ?>" aria-label="<?php echo esc_attr( $pending_remote_post_id ? __( 'Erneut versuchen', 'wp-automation-editor' ) : __( 'Importieren', 'wp-automation-editor' ) ); ?>" <?php disabled( ! $remote_configured ); ?>>
													<?php if ( $pending_remote_post_id ) : ?>
														<span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
														<span class="wpa-screen-reader-text"><?php esc_html_e( 'Erneut versuchen', 'wp-automation-editor' ); ?></span>
													<?php else : ?>
														<span class="dashicons dashicons-download" aria-hidden="true"></span>
														<span class="wpa-screen-reader-text"><?php esc_html_e( 'Importieren', 'wp-automation-editor' ); ?></span>
													<?php endif; ?>
												</button>

												<?php echo WPA_Automation_Editor_Helpers::render_trash_post_form( $post_id, $redirect_url ); ?>
											</td>
										</tr>
									<?php endwhile; ?>
								</tbody>
							</table>
						</div>
						<?php echo $this->render_pagination( $posts_query, $current_page ); ?>
					<?php else : ?>
						<div class="wpa-empty-state">
							<p><?php esc_html_e( 'Es wurden keine fertigen Beiträge gefunden.', 'wp-automation-editor' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
			wp_reset_postdata();
			return ob_get_clean();
		}

		private function enqueue_assets() {
			wp_enqueue_style( 'dashicons' );

			wp_enqueue_style(
				'wpa-frontend-editor',
				WPA_EDITOR_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				WPA_EDITOR_PLUGIN_VERSION
			);

			wp_enqueue_script(
				'wpa-automation-import',
				WPA_EDITOR_PLUGIN_URL . 'assets/js/import.js',
				array(),
				WPA_EDITOR_PLUGIN_VERSION,
				true
			);

			wp_localize_script(
				'wpa-automation-import',
				'wpaAutomationImport',
				array(
					'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
					'nonce'            => wp_create_nonce( 'wpa_import_post_to_remote' ),
					'confirmMessage'   => __( 'Ausgewählte Beiträge jetzt nacheinander importieren?', 'wp-automation-editor' ),
					'importingMessage' => __( 'Import läuft...', 'wp-automation-editor' ),
					'finishedMessage'  => __( 'Beitragsimport abgeschlossen.', 'wp-automation-editor' ),
					'errorMessage'     => __( 'Import fehlgeschlagen.', 'wp-automation-editor' ),
					'stoppedMessage'   => __( 'Import gestoppt, weil die Zielseite überlastet wirkt.', 'wp-automation-editor' ),
					'delayMs'          => WPA_Automation_Editor_Import_Handler::get_inter_import_delay_ms(),
				)
			);
		}

		private function build_importable_meta_query() {
			return array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array(
						'key'     => WPA_Automation_Editor_Helpers::META_WORKFLOW_STATUS,
						'value'   => 'fertig',
						'compare' => '=',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => WPA_Automation_Editor_Helpers::META_WORKFLOW_STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => WPA_Automation_Editor_Helpers::META_LEGACY_WORKFLOW_STATUS,
							'value'   => 'fertig',
							'compare' => '=',
						),
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_POST_ID,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => WPA_Automation_Editor_Import_Handler::META_REMOTE_POST_ID,
						'value'   => array( '', '0' ),
						'compare' => 'IN',
					),
				),
			);
		}

		private function render_pagination( WP_Query $posts_query, $current_page ) {
			if ( $posts_query->max_num_pages <= 1 ) {
				return '';
			}

			$pagination_links = paginate_links(
				array(
					'base'      => add_query_arg( 'wpa_import_page', '%#%' ),
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
