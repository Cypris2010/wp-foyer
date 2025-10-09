<?php

/**
 * Adds admin functionality for the Image slide background.
 *
 * Functionality was copied from Foyer_Admin_Slide_Format_Video (removed in 1.4.0).
 *
 * @since		1.4.0
 *
 * @package		Foyer
 * @subpackage	Foyer/admin
 * @author		Menno Luitjes <menno@mennoluitjes.nl>
 */
class Foyer_Admin_Slide_Background_Image {

	/**
	 * Saves the additional data of the Image slide background.
	 *
	 * @since	1.4.0
	 *
	 * @param 	int		$post_id	The Post ID of the slide being saved.
	 * @return 	void
	 */
	static function save_slide_background( $post_id ) {
		$slide_bg_image_image = intval( $_POST['slide_bg_image_image'] );
		if ( empty( $slide_bg_image_image ) ) {
			$slide_bg_image_image = '';
		}

		update_post_meta( $post_id, 'slide_bg_image_image', $slide_bg_image_image );

		$slide_bg_image_zoom = isset( $_POST['slide_bg_image_zoom'] ) ? sanitize_text_field( wp_unslash( $_POST['slide_bg_image_zoom'] ) ) : '';
		if ( ! in_array( $slide_bg_image_zoom, array( 'inherit', 'enabled', 'disabled' ), true ) ) {
			$slide_bg_image_zoom = '';
		}

		if ( '' === $slide_bg_image_zoom || 'inherit' === $slide_bg_image_zoom ) {
			delete_post_meta( $post_id, 'slide_bg_image_zoom' );
		}
		else {
			update_post_meta( $post_id, 'slide_bg_image_zoom', $slide_bg_image_zoom );
		}
	}

	/**
	 * Outputs the meta box for the Image slide background.
	 *
	 * @since	1.4.0
	 * @since	1.5.2	Added a hint about minimal image sizes.
	 *					Removed the height attribute of the preview image, sizing is now done with CSS.
	 * @since	1.6.0	Renamed everything slide_image_* to slide_file_*, and 'Upload image' to 'Select image'.
	 *
	 * @param 	WP_Post	$post	The post of the slide that is being edited.
	 * @return 	void
	 */
	static function slide_background_meta_box( $post ) {

		wp_enqueue_media();

		$slide_bg_image_image = get_post_meta( $post->ID, 'slide_bg_image_image', true );

		$slide_bg_image_zoom = get_post_meta( $post->ID, 'slide_bg_image_zoom', true );
		if ( ! in_array( $slide_bg_image_zoom, array( 'enabled', 'disabled' ), true ) ) {
			$slide_bg_image_zoom = 'inherit';
		}

		$global_zoom_enabled = (bool) get_option( Foyer_Admin_Settings::OPTION_BACKGROUND_ZOOM, 0 );

		?><table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="slide_bg_image_image"><?php esc_html_e( 'Background image', 'foyer' ); ?></label>
					</th>
					<td>
						<div class="slide_file_field file_type_image<?php if ( empty( $slide_bg_image_image ) ) { ?> empty<?php } ?>">
							<div class="slide_file_preview_wrapper">
								<img class="slide_file_preview" src="<?php echo esc_url( wp_get_attachment_url( $slide_bg_image_image ) ); ?>">
							</div>

							<input type="button" class="button slide_file_upload_button" value="<?php esc_html_e( 'Select image', 'foyer' ); ?>" />
							<input type="button" class="button slide_file_delete_button" value="<?php esc_html_e( 'Remove image', 'foyer' ); ?>" />
							<input type="hidden" name="slide_bg_image_image" class="slide_file_value" value='<?php echo intval( $slide_bg_image_image ); ?>'>
							<p class="slide_file_empty_message"><?php _e( 'For the best results use an image that is at least 1920 x 1080 pixels (landscape), or 1080 x 1920 pixels (portrait).', 'foyer' ); ?></p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_bg_image_zoom"><?php esc_html_e( 'Zoom animation', 'foyer' ); ?></label>
					</th>
					<td>
						<select id="slide_bg_image_zoom" name="slide_bg_image_zoom">
							<option value="inherit" <?php selected( $slide_bg_image_zoom, 'inherit', true ); ?>>
								<?php
									printf(
										esc_html__( 'Use global setting (%s)', 'foyer' ),
										$global_zoom_enabled ? esc_html__( 'enabled', 'foyer' ) : esc_html__( 'disabled', 'foyer' )
									);
								?>
							</option>
							<option value="enabled" <?php selected( $slide_bg_image_zoom, 'enabled', true ); ?>>
								<?php esc_html_e( 'Always zoom this background', 'foyer' ); ?>
							</option>
							<option value="disabled" <?php selected( $slide_bg_image_zoom, 'disabled', true ); ?>>
								<?php esc_html_e( 'Never zoom this background', 'foyer' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Overrides the global setting for this slide when an image background is used.', 'foyer' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table><?php
	}

}
