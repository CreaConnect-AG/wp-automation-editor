<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Planning_Calendar_Shortcode' ) ) {
	class WPA_Automation_Editor_Planning_Calendar_Shortcode {

		const SHORTCODE = 'wpa_planungskalender';
		const STYLE_HANDLE = 'wpa-planning-calendar';
		const DAYS_TO_SHOW = 15;

		public function enqueue_assets() {
			if ( ! $this->should_enqueue_assets() ) {
				return;
			}

			wp_enqueue_style(
				self::STYLE_HANDLE,
				WPA_EDITOR_PLUGIN_URL . 'assets/css/planning-calendar.css',
				array(),
				WPA_EDITOR_PLUGIN_VERSION
			);
		}

		public function render_shortcode() {
			if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
				return $this->render_message( __( 'Bitte einloggen, um die Planung zu sehen.', 'wp-automation-editor' ) );
			}

			$timezone = wp_timezone();
			$start_datetime = new DateTimeImmutable( 'today', $timezone );
			$end_datetime = $start_datetime->modify( '+' . ( self::DAYS_TO_SHOW - 1 ) . ' days' );

			$posts_by_date = $this->get_posts_by_date( $start_datetime, $end_datetime );

			ob_start();
			?>
			<div class="wpa-plan-calendar">
				<div class="wpa-plan-calendar__header">
					<div>
						<h3 class="wpa-plan-calendar__title"><?php esc_html_e( 'Planung nächste 2 Wochen', 'wp-automation-editor' ); ?></h3>
						<div class="wpa-plan-calendar__range">
							<?php echo esc_html( $start_datetime->format( 'd.m.Y' ) . ' – ' . $end_datetime->format( 'd.m.Y' ) ); ?>
						</div>
					</div>
				</div>

				<div class="wpa-plan-calendar__legend" aria-label="<?php esc_attr_e( 'Legende', 'wp-automation-editor' ); ?>">
					<?php foreach ( $this->get_legend_items() as $legend_item ) : ?>
						<div class="wpa-plan-calendar__legend-item">
							<span class="wpa-plan-calendar__legend-color" style="background: <?php echo esc_attr( $legend_item['color'] ); ?>"></span>
							<span><?php echo esc_html( $legend_item['label'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="wpa-plan-calendar__scroll">
					<div class="wpa-plan-calendar__weekdays" aria-hidden="true">
						<span>Mo</span>
						<span>Di</span>
						<span>Mi</span>
						<span>Do</span>
						<span>Fr</span>
						<span>Sa</span>
						<span>So</span>
					</div>

					<div class="wpa-plan-calendar__grid">
						<?php
						$start_weekday_number = (int) $start_datetime->format( 'N' );

						for ( $empty_cell_index = 1; $empty_cell_index < $start_weekday_number; $empty_cell_index++ ) :
							?>
							<div class="wpa-plan-calendar-day wpa-plan-calendar-day--empty" aria-hidden="true"></div>
							<?php
						endfor;

						for ( $day_index = 0; $day_index < self::DAYS_TO_SHOW; $day_index++ ) :
							$current_datetime = $start_datetime->modify( '+' . $day_index . ' days' );
							$date_key = $current_datetime->format( 'Y-m-d' );
							$day_posts = isset( $posts_by_date[ $date_key ] ) ? $posts_by_date[ $date_key ] : array();

							echo $this->render_day( $current_datetime, $day_posts );
						endfor;
						?>
					</div>
				</div>
			</div>
			<?php

			return ob_get_clean();
		}

		private function should_enqueue_assets() {
			if ( is_admin() || ! is_singular() ) {
				return false;
			}

			$current_post = get_post();

			if ( ! $current_post instanceof WP_Post ) {
				return false;
			}

			return has_shortcode( $current_post->post_content, self::SHORTCODE );
		}

		private function get_posts_by_date( $start_datetime, $end_datetime ) {
			$posts_by_date = array();

			for ( $day_index = 0; $day_index < self::DAYS_TO_SHOW; $day_index++ ) {
				$date_key = $start_datetime->modify( '+' . $day_index . ' days' )->format( 'Y-m-d' );
				$posts_by_date[ $date_key ] = array();
			}

			$post_ids = array_merge(
				$this->get_remote_publish_post_ids( $start_datetime, $end_datetime ),
				$this->get_wordpress_future_post_ids( $start_datetime, $end_datetime )
			);

			$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );

			foreach ( $post_ids as $post_id ) {
				$remote_publish_schedule = WPA_Automation_Editor_Helpers::get_post_remote_publish_schedule( $post_id );
				$date_key = $this->get_relevant_post_date( $post_id, $remote_publish_schedule );

				if ( ! isset( $posts_by_date[ $date_key ] ) ) {
					continue;
				}

				$title = get_the_title( $post_id );
				$title = '' !== trim( $title ) ? $title : __( 'Beitrag ohne Titel', 'wp-automation-editor' );

				$posts_by_date[ $date_key ][] = array(
					'id' => $post_id,
					'title' => $title,
					'time' => $this->get_relevant_post_time( $post_id, $remote_publish_schedule ),
					'url' => $this->get_post_url( $post_id ),
				);
			}

			foreach ( $posts_by_date as $date_key => $date_posts ) {
				usort(
					$date_posts,
					function ( $first_post, $second_post ) {
						return strcmp( $first_post['time'] . $first_post['title'], $second_post['time'] . $second_post['title'] );
					}
				);

				$posts_by_date[ $date_key ] = $date_posts;
			}

			return $posts_by_date;
		}

		private function get_remote_publish_post_ids( $start_datetime, $end_datetime ) {
			$query = new WP_Query(
				array(
					'post_type' => 'post',
					'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'fields' => 'ids',
					'posts_per_page' => 200,
					'no_found_rows' => true,
					'ignore_sticky_posts' => true,
					'update_post_term_cache' => false,
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key' => WPA_Automation_Editor_Helpers::META_REMOTE_PUBLISH_GROUP_DATE,
							'value' => array(
								$start_datetime->format( 'Ymd' ),
								$end_datetime->format( 'Ymd' ),
							),
							'compare' => 'BETWEEN',
							'type' => 'NUMERIC',
						),
						array(
							'key' => WPA_Automation_Editor_Helpers::META_REMOTE_PUBLISH_DATE,
							'value' => array(
								$start_datetime->format( 'Y-m-d' ),
								$end_datetime->format( 'Y-m-d' ),
							),
							'compare' => 'BETWEEN',
							'type' => 'CHAR',
						),
					),
				)
			);

			return $query->posts;
		}

		private function get_wordpress_future_post_ids( $start_datetime, $end_datetime ) {
			$query = new WP_Query(
				array(
					'post_type' => 'post',
					'post_status' => array( 'future' ),
					'fields' => 'ids',
					'posts_per_page' => 200,
					'no_found_rows' => true,
					'ignore_sticky_posts' => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'date_query' => array(
						array(
							'column' => 'post_date',
							'after' => $start_datetime->format( 'Y-m-d 00:00:00' ),
							'before' => $end_datetime->format( 'Y-m-d 23:59:59' ),
							'inclusive' => true,
						),
					),
				)
			);

			return $query->posts;
		}

		private function get_relevant_post_date( $post_id, $remote_publish_schedule ) {
			if ( isset( $remote_publish_schedule['date'] ) && $this->is_valid_date( $remote_publish_schedule['date'] ) ) {
				return $remote_publish_schedule['date'];
			}

			$post = get_post( $post_id );

			if ( $post instanceof WP_Post && 'future' === $post->post_status ) {
				return mysql2date( 'Y-m-d', $post->post_date, false );
			}

			return '';
		}

		private function get_relevant_post_time( $post_id, $remote_publish_schedule ) {
			if ( isset( $remote_publish_schedule['time'] ) && preg_match( '/^\d{2}:\d{2}$/', $remote_publish_schedule['time'] ) ) {
				return $remote_publish_schedule['time'];
			}

			$post = get_post( $post_id );

			if ( $post instanceof WP_Post && 'future' === $post->post_status ) {
				return mysql2date( 'H:i', $post->post_date, false );
			}

			return '';
		}

		private function get_post_url( $post_id ) {
            $permalink = get_permalink( $post_id );

            return $permalink ? $permalink : '';
        }

		private function render_day( $current_datetime, $posts ) {
			$post_count = count( $posts );
			$is_today = $current_datetime->format( 'Y-m-d' ) === wp_date( 'Y-m-d' );
			$is_tuesday = 2 === (int) $current_datetime->format( 'N' );

			$classes = array(
				'wpa-plan-calendar-day',
				$this->get_count_class( $post_count ),
			);

			if ( $is_today ) {
				$classes[] = 'wpa-plan-calendar-day--today';
			}

			if ( $is_tuesday ) {
				$classes[] = 'wpa-plan-calendar-day--tuesday';
			}

			$visible_posts = array_slice( $posts, 0, 3 );
			$hidden_post_count = max( 0, $post_count - count( $visible_posts ) );
			$hover_title = $this->get_hover_title( $current_datetime, $posts );

			ob_start();
			?>
			<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="--wpa-plan-day-color: <?php echo esc_attr( $this->get_count_color( $post_count ) ); ?>;" title="<?php echo esc_attr( $hover_title ); ?>">
				<div class="wpa-plan-calendar-day__top">
					<div>
						<div class="wpa-plan-calendar-day__weekday"><?php echo esc_html( $this->get_weekday_label( $current_datetime ) ); ?></div>
						<div class="wpa-plan-calendar-day__date"><?php echo esc_html( $current_datetime->format( 'd.m.' ) ); ?></div>
					</div>

					<div class="wpa-plan-calendar-day__count"><?php echo esc_html( $post_count ); ?></div>
				</div>

				<?php if ( $is_tuesday ) : ?>
					<div class="wpa-plan-calendar-day__newsletter"><?php esc_html_e( 'Newsletter', 'wp-automation-editor' ); ?></div>
				<?php endif; ?>

				<?php if ( $post_count > 0 ) : ?>
					<div class="wpa-plan-calendar-day__posts">
						<?php foreach ( $visible_posts as $post_item ) : ?>
							<a class="wpa-plan-calendar-day__post" href="<?php echo esc_url( $post_item['url'] ); ?>" target="_blank">
								<?php if ( '' !== $post_item['time'] ) : ?>
									<span class="wpa-plan-calendar-day__post-time"><?php echo esc_html( $post_item['time'] ); ?></span>
								<?php endif; ?>
								<span class="wpa-plan-calendar-day__post-title"><?php echo esc_html( $post_item['title'] ); ?></span>
							</a>
						<?php endforeach; ?>

						<?php if ( $hidden_post_count > 0 ) : ?>
							<div class="wpa-plan-calendar-day__more">
								<?php echo esc_html( sprintf( __( '+%d weitere', 'wp-automation-editor' ), $hidden_post_count ) ); ?>
							</div>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="wpa-plan-calendar-day__empty"><?php esc_html_e( 'Fehlt', 'wp-automation-editor' ); ?></div>
				<?php endif; ?>
			</div>
			<?php

			return ob_get_clean();
		}

		private function get_hover_title( $current_datetime, $posts ) {
			$lines = array(
				$this->get_weekday_label( $current_datetime ) . ', ' . $current_datetime->format( 'd.m.Y' ),
			);

			if ( empty( $posts ) ) {
				$lines[] = __( 'Kein Beitrag geplant', 'wp-automation-editor' );
				return implode( "\n", $lines );
			}

			foreach ( $posts as $post_item ) {
				$time_prefix = '' !== $post_item['time'] ? $post_item['time'] . ' – ' : '';
				$lines[] = $time_prefix . $post_item['title'];
			}

			return implode( "\n", $lines );
		}

		private function get_count_class( $post_count ) {
			if ( $post_count <= 0 ) {
				return 'wpa-plan-calendar-day--count-0';
			}

			if ( 1 === $post_count ) {
				return 'wpa-plan-calendar-day--count-1';
			}

			if ( 2 === $post_count ) {
				return 'wpa-plan-calendar-day--count-2';
			}

			if ( 3 === $post_count ) {
				return 'wpa-plan-calendar-day--count-3';
			}

			return 'wpa-plan-calendar-day--count-4';
		}

		private function get_count_color( $post_count ) {
			if ( $post_count <= 0 ) {
				return '#ff0c00';
			}

			if ( 1 === $post_count ) {
				return '#ffc0bd';
			}

			if ( 2 === $post_count ) {
				return '#eded61';
			}

			if ( 3 === $post_count ) {
				return '#abff87';
			}

			return '#45b515';
		}

		private function get_legend_items() {
			return array(
				array(
					'color' => '#ff0c00',
					'label' => __( '0 Beiträge', 'wp-automation-editor' ),
				),
				array(
					'color' => '#ffc0bd',
					'label' => __( '1 Beitrag', 'wp-automation-editor' ),
				),
				array(
					'color' => '#eded61',
					'label' => __( '2 Beiträge', 'wp-automation-editor' ),
				),
				array(
					'color' => '#abff87',
					'label' => __( '3 Beiträge', 'wp-automation-editor' ),
				),
				array(
					'color' => '#45b515',
					'label' => __( '4+ Beiträge', 'wp-automation-editor' ),
				),
				array(
					'color' => '#e6e6e6',
					'label' => __( 'Newsletter-Tag', 'wp-automation-editor' ),
				),
			);
		}

		private function get_weekday_label( $current_datetime ) {
			$weekday_labels = array(
				1 => 'Mo',
				2 => 'Di',
				3 => 'Mi',
				4 => 'Do',
				5 => 'Fr',
				6 => 'Sa',
				7 => 'So',
			);

			$weekday_number = (int) $current_datetime->format( 'N' );

			return isset( $weekday_labels[ $weekday_number ] ) ? $weekday_labels[ $weekday_number ] : '';
		}

		private function is_valid_date( $date ) {
			if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return false;
			}

			$date_parts = explode( '-', $date );

			return checkdate( (int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0] );
		}

		private function render_message( $message ) {
			return '<div class="wpa-plan-calendar-message">' . esc_html( $message ) . '</div>';
		}
	}
}