<?php

/**
 * Adds admin functionality for the RSS Feed slide format.
 *
 * @since		1.9.1
 *
 * @package		Foyer
 * @subpackage	Foyer/admin
 */
class Foyer_Admin_Slide_Format_RSS {

	/**
	 * Saves additional data for the RSS Feed slide format.
	 *
	 * @since	1.9.1
	 *
	 * @param	int		$post_id	The ID of the post being saved.
	 * @return	void
	 */
	static function save_slide( $post_id ) {

		$zoom_meta_key = 'slide_rss_zoom_preference';

		$feed_url = '';
		if ( isset( $_POST['slide_rss_feed_url'] ) ) {
			$feed_url = esc_url_raw( trim( wp_unslash( $_POST['slide_rss_feed_url'] ) ) );
		}

		$limit = '';
		if ( isset( $_POST['slide_rss_limit'] ) ) {
			$limit = absint( $_POST['slide_rss_limit'] );
			if ( $limit < 1 ) {
				$limit = '';
			}
		}

		$cache_minutes = '';
		if ( isset( $_POST['slide_rss_cache_minutes'] ) ) {
			$cache_minutes = absint( $_POST['slide_rss_cache_minutes'] );
			if ( $cache_minutes < 1 ) {
				$cache_minutes = '';
			}
		}

		$show_feed_title = isset( $_POST['slide_rss_show_feed_title'] ) ? 1 : 0;

		$zoom_preference = isset( $_POST['slide_rss_zoom_preference'] ) ? sanitize_text_field( wp_unslash( $_POST['slide_rss_zoom_preference'] ) ) : '';
		if ( ! in_array( $zoom_preference, array( 'inherit', 'enabled', 'disabled' ), true ) ) {
			$zoom_preference = '';
		}

		update_post_meta( $post_id, 'slide_rss_feed_url', $feed_url );
		update_post_meta( $post_id, 'slide_rss_limit', $limit );
		update_post_meta( $post_id, 'slide_rss_cache_minutes', $cache_minutes );
		update_post_meta( $post_id, 'slide_rss_show_feed_title', $show_feed_title );

		if ( '' === $zoom_preference || 'inherit' === $zoom_preference ) {
			delete_post_meta( $post_id, $zoom_meta_key );
		} else {
			update_post_meta( $post_id, $zoom_meta_key, $zoom_preference );
		}
	}

	/**
	 * Outputs the meta box for the RSS Feed slide format.
	 *
	 * @since	1.9.1
	 *
	 * @param	WP_Post	$post	The post of the current slide.
	 * @return	void
	 */
	static function slide_meta_box( $post ) {

		$feed_url = get_post_meta( $post->ID, 'slide_rss_feed_url', true );

		$limit = get_post_meta( $post->ID, 'slide_rss_limit', true );
		if ( '' !== $limit ) {
			$limit = absint( $limit );
		}

		$cache_minutes = get_post_meta( $post->ID, 'slide_rss_cache_minutes', true );
		if ( '' !== $cache_minutes ) {
			$cache_minutes = absint( $cache_minutes );
		}

		$show_feed_title = get_post_meta( $post->ID, 'slide_rss_show_feed_title', true );
		$show_feed_title = ( '' === $show_feed_title ) ? 1 : (int) $show_feed_title;

		$zoom_meta_key = 'slide_rss_zoom_preference';
		$zoom_preference = get_post_meta( $post->ID, $zoom_meta_key, true );
		if ( '' === $zoom_preference ) {
			$zoom_preference = get_post_meta( $post->ID, 'slide_bg_image_zoom', true );
		}
		if ( ! in_array( $zoom_preference, array( 'enabled', 'disabled' ), true ) ) {
			$zoom_preference = 'inherit';
		}

		$global_zoom_enabled = (bool) get_option( Foyer_Admin_Settings::OPTION_BACKGROUND_ZOOM, 0 );

		?><table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="slide_rss_feed_url"><?php _e( 'RSS feed URL', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="url" name="slide_rss_feed_url" id="slide_rss_feed_url" class="large-text" value="<?php echo esc_attr( $feed_url ); ?>" placeholder="<?php echo esc_attr__( 'https://example.com/feed/', 'foyer' ); ?>" />
						<p class="description"><?php echo esc_html__( 'Provide the feed that should be parsed for entries.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_rss_limit"><?php _e( 'Maximum entries', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" step="1" name="slide_rss_limit" id="slide_rss_limit" class="small-text" value="<?php echo ( '' !== $limit ) ? intval( $limit ) : ''; ?>" />
						<p class="description"><?php echo esc_html__( 'Leave empty to default to 5 items.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_rss_cache_minutes"><?php _e( 'Cache duration (minutes)', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" step="1" name="slide_rss_cache_minutes" id="slide_rss_cache_minutes" class="small-text" value="<?php echo ( '' !== $cache_minutes ) ? intval( $cache_minutes ) : ''; ?>" />
						<p class="description"><?php echo esc_html__( 'Optional. Defaults to 15 minutes between feed refreshes.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_rss_show_feed_title"><?php _e( 'Display feed title?', 'foyer' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="slide_rss_show_feed_title" id="slide_rss_show_feed_title" value="1" <?php checked( $show_feed_title, 1 ); ?> />
							<?php _e( 'Yes, show the website / feed title above entries.', 'foyer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_rss_zoom_preference"><?php esc_html_e( 'Zoom animation', 'foyer' ); ?></label>
					</th>
					<td>
						<select id="slide_rss_zoom_preference" name="slide_rss_zoom_preference">
							<option value="inherit" <?php selected( $zoom_preference, 'inherit', true ); ?>>
								<?php
									printf(
										esc_html__( 'Use global setting (%s)', 'foyer' ),
										$global_zoom_enabled ? esc_html__( 'enabled', 'foyer' ) : esc_html__( 'disabled', 'foyer' )
									);
								?>
							</option>
							<option value="enabled" <?php selected( $zoom_preference, 'enabled', true ); ?>>
								<?php esc_html_e( 'Always zoom feed images', 'foyer' ); ?>
							</option>
							<option value="disabled" <?php selected( $zoom_preference, 'disabled', true ); ?>>
								<?php esc_html_e( 'Never zoom feed images', 'foyer' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Overrides the global background zoom for this RSS slide.', 'foyer' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table><?php
	}
}
