<?php

/**
 * Adds admin functionality for the Calendar slide format.
 *
 * @since	1.10.0
 *
 * @package	Foyer
 * @subpackage	Foyer/admin
 */
class Foyer_Admin_Slide_Format_Calendar {

	/**
	 * Saves additional data for the Calendar slide format.
	 *
	 * @since	1.10.0
	 *
	 * @param int $post_id The ID of the post being saved.
	 * @return void
	 */
	public static function save_slide( $post_id ) {

		$title = '';
		if ( isset( $_POST['slide_calendar_title'] ) ) {
			$title = sanitize_text_field( wp_unslash( $_POST['slide_calendar_title'] ) );
		}

		$days_ahead = isset( $_POST['slide_calendar_days_ahead'] ) ? absint( $_POST['slide_calendar_days_ahead'] ) : 0;
		if ( $days_ahead < 1 ) {
			$days_ahead = 7;
		}

		$cache_minutes = isset( $_POST['slide_calendar_cache_minutes'] ) ? absint( $_POST['slide_calendar_cache_minutes'] ) : 0;
		if ( $cache_minutes < 1 ) {
			$cache_minutes = 30;
		}

		$feeds = array();

		if ( isset( $_POST['slide_calendar_feeds'] ) && is_array( $_POST['slide_calendar_feeds'] ) ) {
			$raw = wp_unslash( $_POST['slide_calendar_feeds'] );
			$urls = isset( $raw['url'] ) && is_array( $raw['url'] ) ? $raw['url'] : array();
			$tags = isset( $raw['tag'] ) && is_array( $raw['tag'] ) ? $raw['tag'] : array();
			$colors = isset( $raw['color'] ) && is_array( $raw['color'] ) ? $raw['color'] : array();

			$max = max( count( $urls ), count( $tags ), count( $colors ) );

			for ( $i = 0; $i < $max; $i++ ) {
				$url = isset( $urls[ $i ] ) ? esc_url_raw( trim( $urls[ $i ] ) ) : '';
				$tag = isset( $tags[ $i ] ) ? sanitize_text_field( $tags[ $i ] ) : '';
				$color = isset( $colors[ $i ] ) ? sanitize_hex_color( $colors[ $i ] ) : '';

				if ( empty( $url ) ) {
					continue;
				}

				if ( empty( $color ) ) {
					$color = '';
				}

				$feeds[] = array(
					'url' => $url,
					'tag' => $tag,
					'color' => $color,
				);
			}
		}

		update_post_meta( $post_id, 'slide_calendar_title', $title );
		update_post_meta( $post_id, 'slide_calendar_days_ahead', $days_ahead );
		update_post_meta( $post_id, 'slide_calendar_cache_minutes', $cache_minutes );
		update_post_meta( $post_id, 'slide_calendar_feeds', $feeds );
	}

	/**
	 * Outputs the meta box for the Calendar slide format.
	 *
	 * @since	1.10.0
	 *
	 * @param WP_Post $post The post of the current slide.
	 * @return void
	 */
	public static function slide_meta_box( $post ) {

		wp_enqueue_script( 'wp-util' );

		$title = get_post_meta( $post->ID, 'slide_calendar_title', true );
		if ( ! is_string( $title ) ) {
			$title = '';
		}
		$title = trim( $title );

		$days_ahead = absint( get_post_meta( $post->ID, 'slide_calendar_days_ahead', true ) );
		if ( $days_ahead < 1 ) {
			$days_ahead = 7;
		}

		$cache_minutes = absint( get_post_meta( $post->ID, 'slide_calendar_cache_minutes', true ) );
		if ( $cache_minutes < 1 ) {
			$cache_minutes = 30;
		}

		$feeds = get_post_meta( $post->ID, 'slide_calendar_feeds', true );
		if ( ! is_array( $feeds ) ) {
			$feeds = array();
		}

		if ( empty( $feeds ) ) {
			$feeds[] = array( 'url' => '', 'tag' => '', 'color' => '' );
		}

		?><table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="slide_calendar_title"><?php esc_html_e( 'Slide title', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="text" id="slide_calendar_title" name="slide_calendar_title" class="regular-text" value="<?php echo esc_attr( $title ); ?>" maxlength="160" />
						<p class="description"><?php esc_html_e( 'Optional heading shown above the calendar.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_calendar_days_ahead"><?php esc_html_e( 'Show events for the next', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" step="1" id="slide_calendar_days_ahead" name="slide_calendar_days_ahead" class="small-text" value="<?php echo esc_attr( $days_ahead ); ?>" /> <?php esc_html_e( 'days', 'foyer' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_calendar_cache_minutes"><?php esc_html_e( 'Cache duration', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" step="1" id="slide_calendar_cache_minutes" name="slide_calendar_cache_minutes" class="small-text" value="<?php echo esc_attr( $cache_minutes ); ?>" /> <?php esc_html_e( 'minutes', 'foyer' ); ?>
						<p class="description"><?php esc_html_e( 'How long fetched calendar data is kept before refreshing.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Calendars', 'foyer' ); ?>
					</th>
					<td>
						<div id="foyer-calendar-feeds" class="foyer-calendar-feeds">
							<?php foreach ( $feeds as $feed ) { ?>
								<div class="foyer-calendar-feed">
									<div class="foyer-calendar-feed-row">
										<label>
											<span class="foyer-calendar-field-label"><?php esc_html_e( 'ICS URL', 'foyer' ); ?></span>
											<input type="url" class="large-text" name="slide_calendar_feeds[url][]" value="<?php echo esc_attr( isset( $feed['url'] ) ? $feed['url'] : '' ); ?>" placeholder="<?php echo esc_attr__( 'https://example.com/calendar.ics', 'foyer' ); ?>" />
										</label>
									</div>
									<div class="foyer-calendar-feed-row foyer-calendar-feed-row-split">
										<label>
											<span class="foyer-calendar-field-label"><?php esc_html_e( 'Tag label', 'foyer' ); ?></span>
											<input type="text" name="slide_calendar_feeds[tag][]" value="<?php echo esc_attr( isset( $feed['tag'] ) ? $feed['tag'] : '' ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Team A', 'foyer' ); ?>" />
										</label>
										<label>
											<span class="foyer-calendar-field-label"><?php esc_html_e( 'Tag color', 'foyer' ); ?></span>
											<input type="color" name="slide_calendar_feeds[color][]" value="<?php echo esc_attr( isset( $feed['color'] ) ? $feed['color'] : '' ); ?>" />
										</label>
									</div>
									<button type="button" class="button button-link-delete foyer-calendar-feed-remove" aria-label="<?php esc_attr_e( 'Remove calendar', 'foyer' ); ?>">
										<?php esc_html_e( 'Remove calendar', 'foyer' ); ?>
									</button>
									<hr />
								</div>
							<?php } ?>
						</div>
						<button type="button" class="button" id="foyer-calendar-add-feed"><?php esc_html_e( 'Add calendar', 'foyer' ); ?></button>
						<p class="description"><?php esc_html_e( 'Each calendar can have its own label and color. Events that appear in multiple calendars are merged and display all associated labels.', 'foyer' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<script type="text/html" id="tmpl-foyer-calendar-feed">
			<div class="foyer-calendar-feed">
				<div class="foyer-calendar-feed-row">
					<label>
						<span class="foyer-calendar-field-label"><?php echo esc_html( __( 'ICS URL', 'foyer' ) ); ?></span>
						<input type="url" class="large-text" name="slide_calendar_feeds[url][]" value="" placeholder="<?php echo esc_attr( __( 'https://example.com/calendar.ics', 'foyer' ) ); ?>" />
					</label>
				</div>
				<div class="foyer-calendar-feed-row foyer-calendar-feed-row-split">
					<label>
						<span class="foyer-calendar-field-label"><?php echo esc_html( __( 'Tag label', 'foyer' ) ); ?></span>
						<input type="text" name="slide_calendar_feeds[tag][]" value="" placeholder="<?php echo esc_attr( __( 'e.g. Team A', 'foyer' ) ); ?>" />
					</label>
					<label>
						<span class="foyer-calendar-field-label"><?php echo esc_html( __( 'Tag color', 'foyer' ) ); ?></span>
						<input type="color" name="slide_calendar_feeds[color][]" value="" />
					</label>
				</div>
				<button type="button" class="button button-link-delete foyer-calendar-feed-remove" aria-label="<?php echo esc_attr( __( 'Remove calendar', 'foyer' ) ); ?>">
					<?php echo esc_html( __( 'Remove calendar', 'foyer' ) ); ?>
				</button>
				<hr />
			</div>
		</script>
		<script>
		(function( $ ) {
			var hasWpTemplate = typeof window.wp !== 'undefined' && wp && typeof wp.template === 'function';
			$( document ).on( 'click', '#foyer-calendar-add-feed', function( event ) {
				event.preventDefault();
				var $container = $( '#foyer-calendar-feeds' );
				var template = hasWpTemplate ? wp.template( 'foyer-calendar-feed' ) : null;
				if ( template ) {
					$container.append( template( {} ) );
					return;
				}

				var fallback = [
					'<div class="foyer-calendar-feed">',
						'<div class="foyer-calendar-feed-row">',
							'<label>',
								'<span class="foyer-calendar-field-label">', <?php echo json_encode( __( 'ICS URL', 'foyer' ) ); ?>, '</span>',
								'<input type="url" class="large-text" name="slide_calendar_feeds[url][]" value="" placeholder="', <?php echo json_encode( __( 'https://example.com/calendar.ics', 'foyer' ) ); ?>, '" />',
							'</label>',
						'</div>',
						'<div class="foyer-calendar-feed-row foyer-calendar-feed-row-split">',
							'<label>',
								'<span class="foyer-calendar-field-label">', <?php echo json_encode( __( 'Tag label', 'foyer' ) ); ?>, '</span>',
								'<input type="text" name="slide_calendar_feeds[tag][]" value="" placeholder="', <?php echo json_encode( __( 'e.g. Team A', 'foyer' ) ); ?>, '" />',
							'</label>',
							'<label>',
								'<span class="foyer-calendar-field-label">', <?php echo json_encode( __( 'Tag color', 'foyer' ) ); ?>, '</span>',
								'<input type="color" name="slide_calendar_feeds[color][]" value="" />',
							'</label>',
						'</div>',
						'<button type="button" class="button button-link-delete foyer-calendar-feed-remove" aria-label="', <?php echo json_encode( __( 'Remove calendar', 'foyer' ) ); ?>, '">', <?php echo json_encode( __( 'Remove calendar', 'foyer' ) ); ?>, '</button>',
						'<hr />',
					'</div>'
				].join('');
				$container.append( fallback );
			} );

			$( document ).on( 'click', '.foyer-calendar-feed-remove', function( event ) {
				event.preventDefault();
				var $feeds = $( '#foyer-calendar-feeds' ).find( '.foyer-calendar-feed' );
				if ( $feeds.length <= 1 ) {
					// Keep at least one row as a placeholder.
					$feeds.first().find( 'input' ).val( '' );
					return;
				}
				$( this ).closest( '.foyer-calendar-feed' ).remove();
			} );
		})( jQuery );
		</script>
		<?php
	}
}
