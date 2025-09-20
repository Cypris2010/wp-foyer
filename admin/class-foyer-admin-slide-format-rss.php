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

		update_post_meta( $post_id, 'slide_rss_feed_url', $feed_url );
		update_post_meta( $post_id, 'slide_rss_limit', $limit );
		update_post_meta( $post_id, 'slide_rss_cache_minutes', $cache_minutes );
		update_post_meta( $post_id, 'slide_rss_show_feed_title', $show_feed_title );
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
			</tbody>
		</table><?php
	}
}
