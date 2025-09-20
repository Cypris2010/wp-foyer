<?php

/**
 * Adds admin functionality for the Text slide format.
 *
 * @since		1.5.0
 *
 * @package		Foyer
 * @subpackage	Foyer/admin
 * @author		Menno Luitjes <menno@mennoluitjes.nl>
 */
class Foyer_Admin_Slide_Format_Text {

	/**
	 * Saves additional data for the Text slide format.
	 *
	 * @since	1.5.0
	 *
	 * @param	int		$post_id	The ID of the post being saved.
	 * @return	void
	 */
	static function save_slide( $post_id ) {
		$slide_text_pretitle = sanitize_text_field( $_POST['slide_text_pretitle'] );
		$slide_text_title = sanitize_text_field( $_POST['slide_text_title'] );
		$slide_text_subtitle = sanitize_text_field( $_POST['slide_text_subtitle'] );
		$slide_text_content = wp_kses_post( $_POST['slide_text_content'] );
		$slide_text_qr = isset( $_POST['slide_text_qr'] ) ? sanitize_textarea_field( $_POST['slide_text_qr'] ) : '';
		$slide_text_qr_ecc = isset( $_POST['slide_text_qr_ecc'] ) ? strtoupper( sanitize_text_field( $_POST['slide_text_qr_ecc'] ) ) : 'M';

		update_post_meta( $post_id, 'slide_text_pretitle', $slide_text_pretitle );
		update_post_meta( $post_id, 'slide_text_title', $slide_text_title );
		update_post_meta( $post_id, 'slide_text_subtitle', $slide_text_subtitle );
		update_post_meta( $post_id, 'slide_text_content', $slide_text_content );
		update_post_meta( $post_id, 'slide_text_qr', $slide_text_qr );
		update_post_meta( $post_id, 'slide_text_qr_ecc', in_array( $slide_text_qr_ecc, array( 'L','M','Q','H' ), true ) ? $slide_text_qr_ecc : 'M' );

		// Generate or clear QR SVG depending on input
		$prev_hash = get_post_meta( $post_id, 'slide_text_qr_hash', true );
		if ( '' === $slide_text_qr ) {
			delete_post_meta( $post_id, 'slide_text_qr_svg' );
			delete_post_meta( $post_id, 'slide_text_qr_hash' );
		} else {
			$ecc = in_array( $slide_text_qr_ecc, array( 'L','M','Q','H' ), true ) ? $slide_text_qr_ecc : 'M';
			$new_hash = md5( $slide_text_qr . '|' . $ecc );
			$current_svg = get_post_meta( $post_id, 'slide_text_qr_svg', true );
			// Regenerate if content/ecc changed, if empty, or if legacy base64 data URI was stored
			$needs_regen = ( $new_hash !== $prev_hash ) || empty( $current_svg ) || ( is_string( $current_svg ) && 0 === strpos( $current_svg, 'data:' ) );
			if ( $needs_regen ) {
				$svg = class_exists( 'Foyer_QR' ) ? Foyer_QR::svg( $slide_text_qr, $ecc, 0 ) : '';
				if ( ! empty( $svg ) ) {
					update_post_meta( $post_id, 'slide_text_qr_svg', $svg );
					update_post_meta( $post_id, 'slide_text_qr_hash', $new_hash );
				}
			}
		}
	}

	/**
	 * Outputs the meta box for the Text slide format.
	 *
	 * @since	1.5.0
	 *
	 * @param	WP_Post	$post	The post of the current slide.
	 * @return	void
	 */
	static function slide_meta_box( $post ) {
		$slide_text_pretitle = get_post_meta( $post->ID, 'slide_text_pretitle', true );
		$slide_text_title = get_post_meta( $post->ID, 'slide_text_title', true );
		$slide_text_subtitle = get_post_meta( $post->ID, 'slide_text_subtitle', true );
		$slide_text_content = get_post_meta( $post->ID, 'slide_text_content', true );
		$slide_text_qr = get_post_meta( $post->ID, 'slide_text_qr', true );
		$slide_text_qr_ecc = strtoupper( get_post_meta( $post->ID, 'slide_text_qr_ecc', true ) );
		if ( ! in_array( $slide_text_qr_ecc, array( 'L','M','Q','H' ), true ) ) { $slide_text_qr_ecc = 'M'; }

		?><table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="slide_text_pretitle"><?php _e( 'Pre-title', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="text" name="slide_text_pretitle" id="slide_text_pretitle" class="large-text" value="<?php echo esc_html( $slide_text_pretitle ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_text_title"><?php _e( 'Title', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="text" name="slide_text_title" id="slide_text_title" class="large-text" value="<?php echo esc_html( $slide_text_title ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_text_subtitle"><?php _e( 'Subtitle', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="text" name="slide_text_subtitle" id="slide_text_subtitle" class="large-text" value="<?php echo esc_html( $slide_text_subtitle ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_text_content"><?php _e( 'Content', 'foyer' ); ?></label>
					</th>
					<td>
						<textarea name="slide_text_content" id="slide_text_content" class="large-text" rows="8"><?php echo esc_html( $slide_text_content ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_text_qr"><?php _e( 'QR code', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="text" name="slide_text_qr" id="slide_text_qr" class="large-text" value="<?php echo esc_attr( $slide_text_qr ); ?>" placeholder="<?php echo esc_attr__( 'URL or text to encode', 'foyer' ); ?>" />
						<p class="description"><?php echo esc_html__( 'If filled, a QR will be generated server-side and shown on the slide.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="slide_text_qr_ecc"><?php _e( 'QR error correction', 'foyer' ); ?></label>
					</th>
					<td>
						<select name="slide_text_qr_ecc" id="slide_text_qr_ecc">
							<?php foreach ( array( 'L','M','Q','H' ) as $lvl ) { ?>
								<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $slide_text_qr_ecc, $lvl ); ?>><?php echo esc_html( $lvl ); ?></option>
							<?php } ?>
						</select>
						<p class="description"><?php echo esc_html__( 'Higher levels make codes more robust but denser.', 'foyer' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table><?php
	}
}
