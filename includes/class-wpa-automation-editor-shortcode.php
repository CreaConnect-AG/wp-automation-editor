<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPA_Automation_Editor_Shortcode' ) ) {
    class WPA_Automation_Editor_Shortcode {
        public function render_shortcode() {
            if ( ! is_user_logged_in() ) {
                return WPA_Automation_Editor_Helpers::render_message(
                    __( 'Bitte zuerst einloggen, um die Beitragsübersicht zu öffnen.', 'wp-automation-editor' ),
                    'info'
                );
            }

            if ( ! current_user_can( 'edit_posts' ) ) {
                return WPA_Automation_Editor_Helpers::render_message(
                    __( 'Du hast keine Berechtigung, diese Seite zu verwenden.', 'wp-automation-editor' ),
                    'error'
                );
            }

            $author_id = WPA_Automation_Editor_Helpers::get_automation_author_id();

            if ( ! $author_id ) {
                return WPA_Automation_Editor_Helpers::render_message(
                    __( 'Der Benutzer wp-automation wurde nicht gefunden.', 'wp-automation-editor' ),
                    'error'
                );
            }

            wp_enqueue_style( 'dashicons' );

            ob_start();

            echo '<div class="wpa-editor-wrap">';
            echo WPA_Automation_Editor_Helpers::render_notice();

            $action = WPA_Automation_Editor_Helpers::get_current_action();
            $post_id = WPA_Automation_Editor_Helpers::get_current_edit_post_id();

            if ( 'edit' === $action && $post_id > 0 ) {
                echo $this->render_edit_form( $post_id, $author_id );
            } else {
                echo $this->render_dashboard( $author_id );
            }

            echo '</div>';

            return ob_get_clean();
        }

        private function render_dashboard( $author_id ) {
            $current_status_filter = isset( $_GET['wpa_status'] ) ? sanitize_key( wp_unslash( $_GET['wpa_status'] ) ) : 'offen';
            $current_date_filter = isset( $_GET['wpa_date'] ) ? sanitize_key( wp_unslash( $_GET['wpa_date'] ) ) : '7days';
            $current_page = isset( $_GET['wpa_page_num'] ) ? max( 1, absint( $_GET['wpa_page_num'] ) ) : 1;

            $date_filter_options = WPA_Automation_Editor_Helpers::get_date_filter_options();
            $status_filter_options = WPA_Automation_Editor_Helpers::get_dashboard_status_filter_options();

            if ( ! isset( $date_filter_options[ $current_date_filter ] ) ) {
                $current_date_filter = '7days';
            }

            if ( ! isset( $status_filter_options[ $current_status_filter ] ) ) {
                $current_status_filter = 'offen';
            }

            $query_args = array(
                'post_type'           => 'post',
                'post_status'         => array( 'publish', 'draft', 'pending', 'future', 'private' ),
                'author'              => $author_id,
                'posts_per_page'      => WPA_Automation_Editor_Helpers::POSTS_PER_PAGE,
                'paged'               => $current_page,
                'orderby'             => 'date',
                'order'               => 'DESC',
                'ignore_sticky_posts' => true,
            );

            $meta_query = WPA_Automation_Editor_Helpers::build_dashboard_meta_query( $current_status_filter );
            $date_query = WPA_Automation_Editor_Helpers::build_dashboard_date_query( $current_date_filter );

            if ( ! empty( $meta_query ) ) {
                $query_args['meta_query'] = $meta_query;
            }

            if ( ! empty( $date_query ) ) {
                $query_args['date_query'] = $date_query;
            }

            $posts_query = new WP_Query( $query_args );
            $status_options = WPA_Automation_Editor_Helpers::get_workflow_status_options();
            $base_url = WPA_Automation_Editor_Helpers::get_base_page_url();

            ob_start();
            ?>
            <div class="wpa-card">
                <div class="wpa-card-header">
                    <div>
                        <h2><?php esc_html_e( 'Automatisierte Beiträge', 'wp-automation-editor' ); ?></h2>
                        <p><?php esc_html_e( 'Standardmässig werden die neuesten Beiträge der letzten 7 Tage gezeigt.', 'wp-automation-editor' ); ?></p>
                    </div>
                </div>

                <form method="get" class="wpa-filter-form">
                    <?php
                    foreach ( $_GET as $key => $value ) {
                        if ( in_array( $key, array( 'wpa_status', 'wpa_date', 'wpa_page_num' ), true ) ) {
                            continue;
                        }

                        if ( is_array( $value ) ) {
                            continue;
                        }

                        printf(
                            '<input type="hidden" name="%1$s" value="%2$s">',
                            esc_attr( $key ),
                            esc_attr( wp_unslash( $value ) )
                        );
                    }
                    ?>

                    <div class="wpa-filter-grid">
                        <div>
                            <label for="wpa_status"><?php esc_html_e( 'Status', 'wp-automation-editor' ); ?></label>
                            <select id="wpa_status" name="wpa_status">
                                <?php foreach ( $status_filter_options as $status_key => $status_label ) : ?>
                                    <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_key, $current_status_filter ); ?>>
                                        <?php echo esc_html( $status_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="wpa_date"><?php esc_html_e( 'Zeitraum', 'wp-automation-editor' ); ?></label>
                            <select id="wpa_date" name="wpa_date">
                                <?php foreach ( $date_filter_options as $date_key => $date_label ) : ?>
                                    <option value="<?php echo esc_attr( $date_key ); ?>" <?php selected( $date_key, $current_date_filter ); ?>>
                                        <?php echo esc_html( $date_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="wpa-filter-actions">
                        <button type="submit" class="wpa-button wpa-button-primary"><?php esc_html_e( 'Filtern', 'wp-automation-editor' ); ?></button>
                        <a class="wpa-button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Zurücksetzen', 'wp-automation-editor' ); ?></a>
                    </div>
                </form>
            </div>

            <div class="wpa-card">
                <?php if ( $posts_query->have_posts() ) : ?>
                    <div class="wpa-table-wrap">
                        <table class="wpa-post-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Titel', 'wp-automation-editor' ); ?></th>
                                    <th><?php esc_html_e( 'Datum', 'wp-automation-editor' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'wp-automation-editor' ); ?></th>
                                    <th><?php esc_html_e( 'Kategorien', 'wp-automation-editor' ); ?></th>
                                    <th><?php esc_html_e( 'Aktionen', 'wp-automation-editor' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ( $posts_query->have_posts() ) : $posts_query->the_post(); ?>
                                    <?php
                                    $post_id = get_the_ID();
                                    $status_key = WPA_Automation_Editor_Helpers::get_post_workflow_status( $post_id );
                                    $status_label = isset( $status_options[ $status_key ] ) ? $status_options[ $status_key ] : $status_options['unbearbeitet'];
                                    $categories = get_the_category( $post_id );
                                    $category_names = ! empty( $categories ) ? wp_list_pluck( $categories, 'name' ) : array();
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( get_the_title() ); ?></strong>
                                            <div class="wpa-post-meta-line">
                                                <?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 16 ) ); ?>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html( get_the_date( 'd.m.Y H:i', $post_id ) ); ?></td>
                                        <td>
                                            <span class="wpa-status-badge wpa-status-<?php echo esc_attr( $status_key ); ?>">
                                                <?php echo esc_html( $status_label ); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html( ! empty( $category_names ) ? implode( ', ', $category_names ) : '—' ); ?></td>
                                        <td class="actions">
                                            <a class="wpa-button wpa-button-primary wpa-icon-button" href="<?php echo esc_url( WPA_Automation_Editor_Helpers::get_edit_url( $post_id ) ); ?>" title="<?php esc_attr_e( 'Bearbeiten', 'wp-automation-editor' ); ?>" aria-label="<?php esc_attr_e( 'Bearbeiten', 'wp-automation-editor' ); ?>">
                                                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                                <span class="wpa-screen-reader-text"><?php esc_html_e( 'bearbeiten', 'wp-automation-editor' ); ?></span>
                                            </a>

                                            <?php echo WPA_Automation_Editor_Helpers::render_trash_post_form( $post_id, WPA_Automation_Editor_Helpers::get_base_page_url() ); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php echo WPA_Automation_Editor_Helpers::render_pagination( $posts_query, $current_page, $current_status_filter, $current_date_filter ); ?>
                <?php else : ?>
                    <div class="wpa-empty-state">
                        <p><?php esc_html_e( 'Es wurden keine passenden Beiträge gefunden.', 'wp-automation-editor' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            wp_reset_postdata();

            return ob_get_clean();
        }

        private function render_edit_form( $post_id, $author_id ) {
            $post = get_post( $post_id );

            if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
                return WPA_Automation_Editor_Helpers::render_message(
                    __( 'Der Beitrag wurde nicht gefunden.', 'wp-automation-editor' ),
                    'error'
                );
            }

            if ( (int) $post->post_author !== (int) $author_id ) {
                return WPA_Automation_Editor_Helpers::render_message(
                    __( 'Dieser Beitrag gehört nicht zum Automatisierungs-Workflow.', 'wp-automation-editor' ),
                    'error'
                );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return WPA_Automation_Editor_Helpers::render_message(
                    __( 'Du darfst diesen Beitrag nicht bearbeiten.', 'wp-automation-editor' ),
                    'error'
                );
            }

            WPA_Automation_Editor_Helpers::load_post_lock_functions();

            $locked_by = wp_check_post_lock( $post_id );

            if ( $locked_by ) {
                $lock_holder_name = WPA_Automation_Editor_Helpers::get_lock_holder_name( $locked_by );

                ob_start();
                ?>
                <div class="wpa-card wpa-edit-card">
                    <a class="wpa-back-link" href="<?php echo esc_url( WPA_Automation_Editor_Helpers::get_base_page_url() ); ?>">← <?php esc_html_e( 'Zur Übersicht zurück', 'wp-automation-editor' ); ?></a>
                    <?php
                    echo WPA_Automation_Editor_Helpers::render_message(
                        sprintf(
                            __( 'Dieser Beitrag wird aktuell von %s bearbeitet.', 'wp-automation-editor' ),
                            $lock_holder_name
                        ),
                        'error'
                    );
                    ?>
                </div>
                <?php
                return ob_get_clean();
            }

            wp_set_post_lock( $post_id );

            $status_options = WPA_Automation_Editor_Helpers::get_workflow_status_options();
            $current_status = WPA_Automation_Editor_Helpers::get_post_workflow_status( $post_id );
            $selected_category_ids = wp_get_post_categories( $post_id );

            $all_categories = get_categories(
                array(
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                )
            );

            $selected_tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
            $all_tags = get_tags(
                array(
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                )
            );

            $all_tag_names = array();

            foreach ( $all_tags as $tag ) {
                $all_tag_names[] = $tag->name;
            }

            foreach ( $selected_tags as $selected_tag ) {
                if ( ! in_array( $selected_tag, $all_tag_names, true ) ) {
                    $all_tag_names[] = $selected_tag;
                }
            }

            natcasesort( $all_tag_names );

            if ( function_exists( 'get_field' ) ) {
                $newsletter_id = get_field( 'newsletter_id', $post_id );
                $midjourney_prompt = get_field( 'midjourney_prompt_en', $post_id );
            } else {
                $newsletter_id = get_post_meta( $post_id, 'newsletter_id', true );
                $midjourney_prompt = get_post_meta( $post_id, 'midjourney_prompt_en', true );
            }

            $newsletter_id = '' !== (string) $newsletter_id ? absint( $newsletter_id ) : '';
            $remote_publish_schedule = WPA_Automation_Editor_Helpers::get_post_remote_publish_schedule( $post_id );
            $remote_publish_date = $remote_publish_schedule['date'];
            $remote_publish_time = $remote_publish_schedule['time'];
            $remote_publish_time_options = WPA_Automation_Editor_Helpers::get_remote_publish_time_options();
            $remote_publish_type = 'newsletter';

            if ( '' === (string) $newsletter_id && ( '' !== $remote_publish_date || '' !== $remote_publish_time ) ) {
                $remote_publish_type = 'immonews';
            }
            $occupied_remote_publish_slots = array();
            $occupied_remote_publish_slots_by_time = array();

            if ( '' !== $remote_publish_date ) {
                $occupied_remote_publish_slots = WPA_Automation_Editor_Helpers::get_remote_publish_occupied_slots( $remote_publish_date, $post_id );

                foreach ( $occupied_remote_publish_slots as $occupied_remote_publish_slot ) {
                    if ( isset( $occupied_remote_publish_slot['time'] ) ) {
                        $occupied_remote_publish_slots_by_time[ $occupied_remote_publish_slot['time'] ] = $occupied_remote_publish_slot;
                    }
                }
            }
            $featured_image_html = get_the_post_thumbnail( $post_id, 'large', array( 'class' => 'wpa-featured-image-preview' ) );

            ob_start();
            ?>
            <div class="wpa-card wpa-edit-card">
                <div class="wpa-card-header">
                    <div>
                        <a class="wpa-back-link" href="<?php echo esc_url( WPA_Automation_Editor_Helpers::get_base_page_url() ); ?>">← <?php esc_html_e( 'Zur Übersicht zurück', 'wp-automation-editor' ); ?></a>
                        <h2><?php esc_html_e( 'Beitrag bearbeiten', 'wp-automation-editor' ); ?></h2>
                        <p class="wpa-help-text"><?php esc_html_e( 'Solange diese Seite offen ist, bleibt der Beitrag für andere Personen gesperrt.', 'wp-automation-editor' ); ?></p>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpa-edit-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="wpa_save_post">
                    <input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
                    <input type="hidden" name="redirect_url" value="<?php echo esc_url( WPA_Automation_Editor_Helpers::get_edit_url( $post_id ) ); ?>">
                    <?php wp_nonce_field( 'wpa_save_post_' . $post_id, 'wpa_nonce' ); ?>

                    <div class="wpa-form-section wpa-publish-type-section">
                        <h3><?php esc_html_e( 'Ausgabeart', 'wp-automation-editor' ); ?></h3>

                        <div class="wpa-radio-group">
                            <label class="wpa-radio-card">
                                <input
                                    type="radio"
                                    name="remote_publish_type"
                                    value="newsletter"
                                    <?php checked( $remote_publish_type, 'newsletter' ); ?>
                                >
                                <span>
                                    <strong><?php esc_html_e( 'Newsletter', 'wp-automation-editor' ); ?></strong>
                                    <small><?php esc_html_e( 'Der Beitrag wird am Newsletter-Tag veröffentlicht.', 'wp-automation-editor' ); ?></small>
                                </span>
                            </label>

                            <label class="wpa-radio-card">
                                <input
                                    type="radio"
                                    name="remote_publish_type"
                                    value="immonews"
                                    <?php checked( $remote_publish_type, 'immonews' ); ?>
                                >
                                <span>
                                    <strong><?php esc_html_e( 'immoNews (nur als immo-invest.ch Beitrag veröffentlichen)', 'wp-automation-editor' ); ?></strong>
                                    <small><?php esc_html_e( 'Der Beitrag wird mit Veröffentlichungsdatum und Uhrzeit geplant.', 'wp-automation-editor' ); ?></small>
                                </span>
                            </label>
                        </div>

                        <div class="wpa-publish-type-panel" data-wpa-publish-panel="newsletter">
                            <div class="wpa-form-row">
                                <label for="wpa_newsletter_id"><?php esc_html_e( 'Newsletter ID', 'wp-automation-editor' ); ?></label>

                                <input
                                    type="number"
                                    id="wpa_newsletter_id"
                                    name="newsletter_id"
                                    min="0"
                                    step="1"
                                    value="<?php echo esc_attr( $newsletter_id ); ?>"
                                >

                                <p class="wpa-help-text">
                                    <?php esc_html_e( 'Hier die Newsletter-Nummer angeben, falls der Beitrag in den Newsletter kommen soll.', 'wp-automation-editor' ); ?>
                                </p>
                            </div>
                        </div>

                        <div class="wpa-publish-type-panel" data-wpa-publish-panel="immonews">
                            <div class="wpa-form-grid">
                                <div class="wpa-form-row">
                                    <label for="wpa_remote_publish_date"><?php esc_html_e( 'Veröffentlichungsdatum (immo-invest.ch)', 'wp-automation-editor' ); ?></label>

                                    <input
                                        type="date"
                                        id="wpa_remote_publish_date"
                                        name="remote_publish_date"
                                        value="<?php echo esc_attr( $remote_publish_date ); ?>"
                                    >

                                    <p class="wpa-help-text">
                                        <?php esc_html_e( 'Datum für die Veröffentlichung auf der importierten Webseite.', 'wp-automation-editor' ); ?>
                                    </p>
                                </div>

                                <div class="wpa-form-row">
                                    <label for="wpa_remote_publish_time"><?php esc_html_e( 'Veröffentlichungszeit (immo-invest.ch)', 'wp-automation-editor' ); ?></label>

                                    <select id="wpa_remote_publish_time" name="remote_publish_time">
                                        <option value=""><?php esc_html_e( 'Keine Zeit auswählen', 'wp-automation-editor' ); ?></option>

                                        <?php foreach ( $remote_publish_time_options as $time_value => $time_label ) : ?>
                                            <?php
                                            $occupied_remote_publish_slot = isset( $occupied_remote_publish_slots_by_time[ $time_value ] )
                                                ? $occupied_remote_publish_slots_by_time[ $time_value ]
                                                : array();

                                            $is_time_occupied = ! empty( $occupied_remote_publish_slot );

                                            $occupied_post_title = isset( $occupied_remote_publish_slot['title'] )
                                                ? $occupied_remote_publish_slot['title']
                                                : '';

                                            $time_option_label = $is_time_occupied && '' !== $occupied_post_title
                                                ? sprintf( __( '%1$s – Belegt: %2$s', 'wp-automation-editor' ), $time_label, $occupied_post_title )
                                                : $time_label;
                                            ?>

                                            <option
                                                value="<?php echo esc_attr( $time_value ); ?>"
                                                class="<?php echo esc_attr( $is_time_occupied ? 'wpa-remote-publish-time-option-occupied' : '' ); ?>"
                                                data-occupied-title="<?php echo esc_attr( $occupied_post_title ); ?>"
                                                <?php selected( $remote_publish_time, $time_value ); ?>
                                                <?php disabled( $is_time_occupied ); ?>
                                            >
                                                <?php echo esc_html( $time_option_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <p class="wpa-help-text">
                                        <?php esc_html_e( 'Erlaubte Zeiten: 07:30, 09:00, 10:00, 11:00, 12:30, 14:00, 15:00, 16:00, 17:30 Uhr oder 19:00 Uhr.', 'wp-automation-editor' ); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wpa-form-section">
                        <h3><?php esc_html_e( 'Bild & Prompt', 'wp-automation-editor' ); ?></h3>

                        <div class="wpa-featured-image-box">
                            <label for="wpa_featured_image"><?php esc_html_e( 'Beitragsbild', 'wp-automation-editor' ); ?></label>

                            <?php if ( ! empty( $featured_image_html ) ) : ?>
                                <div class="wpa-featured-image-wrap">
                                    <?php echo wp_kses_post( $featured_image_html ); ?>
                                </div>

                                <?php if ( current_user_can( 'upload_files' ) ) : ?>
                                    <div class="wpa-featured-image-upload">
                                        <input
                                            type="file"
                                            id="wpa_featured_image"
                                            name="wpa_featured_image"
                                            accept="image/jpeg,image/png,image/gif,image/webp"
                                        >

                                        <p class="wpa-help-text">
                                            <?php esc_html_e( 'Optional ein neues Bild auswählen. Es wird beim Speichern als neues Beitragsbild gesetzt.', 'wp-automation-editor' ); ?>
                                        </p>

                                        <label class="wpa-checkbox-label">
                                            <input type="checkbox" name="remove_featured_image" value="1">
                                            <?php esc_html_e( 'Aktuelles Beitragsbild entfernen', 'wp-automation-editor' ); ?>
                                        </label>
                                    </div>
                                <?php endif; ?>
                            <?php else : ?>
                                <div class="wpa-featured-image-empty">
                                    <?php esc_html_e( 'Für diesen Beitrag ist noch kein Beitragsbild gesetzt.', 'wp-automation-editor' ); ?>
                                </div>

                                <?php if ( current_user_can( 'upload_files' ) ) : ?>
                                    <div class="wpa-featured-image-upload">
                                        <input
                                            type="file"
                                            id="wpa_featured_image"
                                            name="wpa_featured_image"
                                            accept="image/jpeg,image/png,image/gif,image/webp"
                                        >

                                        <p class="wpa-help-text">
                                            <?php esc_html_e( 'Bild auswählen und danach unten auf Speichern klicken.', 'wp-automation-editor' ); ?>
                                        </p>
                                    </div>
                                <?php else : ?>
                                    <p class="wpa-help-text">
                                        <?php esc_html_e( 'Du hast keine Berechtigung, Bilder hochzuladen.', 'wp-automation-editor' ); ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="wpa-midjourney-box">
                            <label><?php esc_html_e( 'Midjourney Prompt', 'wp-automation-editor' ); ?></label>

                            <div class="wpa-featured-image-wrap">
                                <?php echo nl2br( esc_html( $midjourney_prompt ) ); ?>
                            </div>
                        </div>
                    </div>

                    <div class="wpa-form-section">
                        <h3><?php esc_html_e( 'Beitragsinhalt', 'wp-automation-editor' ); ?></h3>

                        <div class="wpa-form-row">
                            <label for="wpa_post_title"><?php esc_html_e( 'Titel', 'wp-automation-editor' ); ?></label>
                            <input type="text" id="wpa_post_title" name="post_title" value="<?php echo esc_attr( $post->post_title ); ?>" required>
                        </div>

                        <div class="wpa-form-row">
                            <label for="wpa_post_excerpt"><?php esc_html_e( 'Textauszug', 'wp-automation-editor' ); ?></label>
                            <textarea id="wpa_post_excerpt" name="post_excerpt" rows="5"><?php echo esc_textarea( $post->post_excerpt ); ?></textarea>
                        </div>

                        <div class="wpa-form-row">
                            <label><?php esc_html_e( 'Inhalt', 'wp-automation-editor' ); ?></label>

                            <div class="wpa-editor-box">
                                <?php
                                wp_editor(
                                    $post->post_content,
                                    'wpa_post_content_editor',
                                    array(
                                        'textarea_name' => 'post_content',
                                        'textarea_rows' => 18,
                                        'media_buttons' => false,
                                        'teeny'         => false,
                                    )
                                );
                                ?>
                            </div>
                        </div>

                        <div class="wpa-form-grid">
                            <div class="wpa-form-row">
                                <label for="wpa_post_categories"><?php esc_html_e( 'Kategorien', 'wp-automation-editor' ); ?></label>

                                <select id="wpa_post_categories" name="post_categories[]" multiple class="wpa-categories-select">
                                    <?php foreach ( $all_categories as $category ) : ?>
                                        <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( (int) $category->term_id, $selected_category_ids, true ) ); ?>>
                                            <?php echo esc_html( $category->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <p class="wpa-help-text"><?php esc_html_e( 'Mehrere Kategorien können ausgewählt werden.', 'wp-automation-editor' ); ?></p>
                            </div>

                            <div class="wpa-form-row">
                                <label for="wpa_post_tags"><?php esc_html_e( 'Schlagwörter', 'wp-automation-editor' ); ?></label>

                                <select id="wpa_post_tags" name="post_tags[]" multiple class="wpa-tags-select">
                                    <?php foreach ( $all_tag_names as $tag_name ) : ?>
                                        <option value="<?php echo esc_attr( $tag_name ); ?>" <?php selected( in_array( $tag_name, $selected_tags, true ) ); ?>>
                                            <?php echo esc_html( $tag_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <p class="wpa-help-text"><?php esc_html_e( 'Vorhandene Schlagwörter auswählen oder neue eintippen und mit Enter bestätigen.', 'wp-automation-editor' ); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="wpa-form-row">
                        <label for="wpa_workflow_status"><?php esc_html_e( 'Workflow-Status', 'wp-automation-editor' ); ?></label>

                        <select id="wpa_workflow_status" name="workflow_status">
                            <?php foreach ( $status_options as $status_key => $status_label ) : ?>
                                <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_key, $current_status ); ?>>
                                    <?php echo esc_html( $status_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="wpa-form-actions">
                        <button type="submit" class="wpa-button wpa-button-primary"><?php esc_html_e( 'Änderungen speichern', 'wp-automation-editor' ); ?></button>
                        <a class="wpa-button" href="<?php echo esc_url( WPA_Automation_Editor_Helpers::get_base_page_url() ); ?>"><?php esc_html_e( 'Abbrechen', 'wp-automation-editor' ); ?></a>
                    </div>
                </form>
            </div>
            <?php

            return ob_get_clean();
        }
    }
}