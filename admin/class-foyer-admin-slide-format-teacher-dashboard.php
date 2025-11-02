<?php

/**
 * Adds admin functionality for the WebUntis Teacher Dashboard slide format.
 *
 * @since		1.?.?
 *
 * @package		Foyer
 * @subpackage	Foyer/admin
 */
class Foyer_Admin_Slide_Format_Teacher_Dashboard {

	const META_SOURCE   = 'slide_teacher_dashboard_source';
	const META_TEACHERS = 'slide_teacher_dashboard_teachers';
	const META_THEME    = 'slide_teacher_dashboard_theme';
	const META_LEGEND   = 'slide_teacher_dashboard_show_legend';
	const DEFAULT_SOURCE = 'https://bigbrother2.lgsit.de/untisdata/lehrer.txt';
	const DEFAULT_THEME  = 'dark';
	const DEFAULT_LEGEND = 'yes';
	const CACHE_TTL = 300; // 5 minutes

	/**
	 * Outputs the meta box for the WebUntis Teacher Dashboard slide format.
	 *
	 * @since	1.?.?
	 *
	 * @param	WP_Post	$post	The post of the current slide.
	 * @return	void
	 */
	public static function slide_meta_box( $post ) {
		wp_nonce_field( 'foyer_teacher_dashboard_meta', 'foyer_teacher_dashboard_meta' );

		$source = get_post_meta( $post->ID, self::META_SOURCE, true );
		if ( empty( $source ) ) {
			$source = self::DEFAULT_SOURCE;
		}

		$selected = get_post_meta( $post->ID, self::META_TEACHERS, true );
		$selected = is_array( $selected ) ? array_map( 'sanitize_text_field', $selected ) : array();

		$teachers = self::get_teacher_choices( $source );
		$is_error = is_wp_error( $teachers );

		$theme = get_post_meta( $post->ID, self::META_THEME, true );
		if ( ! in_array( $theme, array( 'dark', 'light' ), true ) ) {
			$theme = self::DEFAULT_THEME;
		}

		$legend = get_post_meta( $post->ID, self::META_LEGEND, true );
		$legend = ( 'no' === $legend ) ? 'no' : self::DEFAULT_LEGEND;

		?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( self::META_SOURCE ); ?>"><?php esc_html_e( 'Datenquelle (TXT/CSV URL)', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="url" name="<?php echo esc_attr( self::META_SOURCE ); ?>" id="<?php echo esc_attr( self::META_SOURCE ); ?>" class="large-text" value="<?php echo esc_attr( $source ); ?>" placeholder="https://example.com/lehrer.txt" />
						<p class="description"><?php esc_html_e( 'Erwartet eine | (Pipe) getrennte Datei wie von WebUntis exportiert.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( self::META_TEACHERS ); ?>"><?php esc_html_e( 'Lehrer auf dieser Folie', 'foyer' ); ?></label>
					</th>
					<td>
						<?php if ( $is_error ) : ?>
							<div class="notice notice-error inline">
								<p><?php echo esc_html( $teachers->get_error_message() ); ?></p>
							</div>
							<?php if ( ! empty( $selected ) ) : ?>
								<p class="description"><?php esc_html_e( 'Die gespeicherte Auswahl bleibt erhalten, konnte aber nicht aktualisiert werden.', 'foyer' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<select name="<?php echo esc_attr( self::META_TEACHERS ); ?>[]" id="<?php echo esc_attr( self::META_TEACHERS ); ?>" class="widefat" multiple size="10">
								<?php foreach ( $teachers as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( in_array( $value, $selected, true ) ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Mehrfachauswahl mit gedrückter Strg- oder Befehlstaste. Ohne Auswahl werden alle verfügbaren Lehrer angezeigt.', 'foyer' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( self::META_THEME ); ?>"><?php esc_html_e( 'Darstellung', 'foyer' ); ?></label>
					</th>
					<td>
						<select name="<?php echo esc_attr( self::META_THEME ); ?>" id="<?php echo esc_attr( self::META_THEME ); ?>">
							<option value="dark" <?php selected( 'dark', $theme ); ?>><?php esc_html_e( 'Dunkel', 'foyer' ); ?></option>
							<option value="light" <?php selected( 'light', $theme ); ?>><?php esc_html_e( 'Hell', 'foyer' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Wählt das Farbschema für die Kacheln und den Hintergrund.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( self::META_LEGEND ); ?>"><?php esc_html_e( 'Legende anzeigen', 'foyer' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::META_LEGEND ); ?>" id="<?php echo esc_attr( self::META_LEGEND ); ?>" value="yes" <?php checked( $legend, 'yes' ); ?> />
							<?php esc_html_e( 'Status-Filterleiste unterhalb der Kacheln einblenden.', 'foyer' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Saves additional data for the WebUntis Teacher Dashboard slide format.
	 *
	 * @since	1.?.?
	 *
	 * @param	int	$post_id	The ID of the post being saved.
	 * @return	void
	 */
	public static function save_slide( $post_id ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['foyer_teacher_dashboard_meta'] ) || ! wp_verify_nonce( $_POST['foyer_teacher_dashboard_meta'], 'foyer_teacher_dashboard_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$source = isset( $_POST[ self::META_SOURCE ] ) ? esc_url_raw( trim( wp_unslash( $_POST[ self::META_SOURCE ] ) ) ) : '';
		if ( empty( $source ) ) {
			$source = self::DEFAULT_SOURCE;
		}
		update_post_meta( $post_id, self::META_SOURCE, $source );
		delete_transient( 'foyer_td_choices_' . md5( $source ) );

		$selected = array();
		if ( isset( $_POST[ self::META_TEACHERS ] ) && is_array( $_POST[ self::META_TEACHERS ] ) ) {
			$selected = array_values( array_unique( array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::META_TEACHERS ] ) ) ) );
		}
		update_post_meta( $post_id, self::META_TEACHERS, $selected );

		$theme = isset( $_POST[ self::META_THEME ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_THEME ] ) ) : self::DEFAULT_THEME;
		if ( ! in_array( $theme, array( 'dark', 'light' ), true ) ) {
			$theme = self::DEFAULT_THEME;
		}
		update_post_meta( $post_id, self::META_THEME, $theme );

		$legend = isset( $_POST[ self::META_LEGEND ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ self::META_LEGEND ] ) ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_LEGEND, $legend );
	}

	/**
	 * Returns unique teacher choices from the configured data source.
	 *
	 * @since	1.?.?
	 *
	 * @param	string	$source	URL to the teacher file.
	 * @return	array|WP_Error
	 */
	private static function get_teacher_choices( $source ) {
		if ( empty( $source ) ) {
			return new WP_Error( 'foyer_teacher_dashboard_missing_source', __( 'Bitte geben Sie eine gültige Datenquelle an.', 'foyer' ) );
		}

		$cache_key = 'foyer_td_choices_' . md5( $source );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( $source, array( 'timeout' => 5, 'headers' => array( 'Accept' => 'text/plain' ) ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'foyer_teacher_dashboard_remote_error', sprintf( __( 'Daten konnten nicht geladen werden: %s', 'foyer' ), $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'foyer_teacher_dashboard_http_error', sprintf( __( 'Antwort der Datenquelle: HTTP %d', 'foyer' ), intval( $code ) ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'foyer_teacher_dashboard_empty', __( 'Die Datenquelle liefert keine Inhalte.', 'foyer' ) );
		}

		$lines = preg_split( "/\r\n|\r|\n/", $body );
		$choices = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( empty( $parts ) || empty( $parts[0] ) ) {
				continue;
			}

			$key = sanitize_text_field( $parts[0] );
			if ( isset( $choices[ $key ] ) ) {
				continue;
			}

			$label = $key;
			$alt = '';
			if ( ! empty( $parts[4] ) && '-' !== $parts[4] ) {
				$alt = $parts[4];
			} elseif ( ! empty( $parts[3] ) && '-' !== $parts[3] ) {
				$alt = $parts[3];
			}

			if ( ! empty( $alt ) && strcasecmp( $alt, $label ) !== 0 ) {
				$label = sprintf( '%s – %s', $label, $alt );
			}

			$choices[ $key ] = $label;
		}

		if ( empty( $choices ) ) {
			return new WP_Error( 'foyer_teacher_dashboard_no_teachers', __( 'Keine Lehrer in der Datenquelle gefunden.', 'foyer' ) );
		}

		asort( $choices, SORT_NATURAL | SORT_FLAG_CASE );
		set_transient( $cache_key, $choices, self::CACHE_TTL );

		return $choices;
	}
}
