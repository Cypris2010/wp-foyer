<?php

/**
 * Admin UI for the Printer Status slide format.
 *
 * @package Foyer
 * @subpackage Foyer/admin
 */
class Foyer_Admin_Slide_Format_Printer_Status {

	const NONCE_FIELD = 'foyer_printer_status_meta';

	/**
	 * Renders the printer status meta box.
	 *
	 * @param WP_Post $post Current slide.
	 * @return void
	 */
	public static function slide_meta_box( $post ) {
		wp_nonce_field( self::NONCE_FIELD, self::NONCE_FIELD );

		$config    = Foyer_Printer_Status_Slide::get_config( $post->ID );
		$providers = Foyer_Printer_Status_Manager::get_provider_choices();
		if ( empty( $providers ) ) {
			$providers = array( '' => __( 'Kein Anbieter verfügbar', 'foyer' ) );
		}

		$devices = $config['devices'];
		if ( empty( $devices ) ) {
			$devices = array(
				array(
					'name'        => '',
					'host'        => '',
					'access_code' => '',
					'camera_url'  => '',
					'meta'        => array(
						'serial'       => '',
						'use_tls'      => '1',
						'mqtt_port'    => 8883,
						'status_topic' => '',
						'scheme'       => 'http',
					),
				),
			);
		}

		?>
		<p><?php esc_html_e( 'Zeigt den Status mehrerer 3D-Drucker in einem Grid mit Live-Daten aus der lokalen API.', 'foyer' ); ?></p>

		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><label for="foyer_printer_status_provider"><?php esc_html_e( 'Hersteller', 'foyer' ); ?></label></th>
					<td>
				<select id="foyer_printer_status_provider" name="foyer_printer_status_provider">
					<?php foreach ( $providers as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $config['provider'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Wählt den gewünschten Hersteller. Ohne Eintrag steht kein Provider zur Verfügung.', 'foyer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="foyer_printer_status_refresh"><?php esc_html_e( 'Aktualisierungsintervall (Sekunden)', 'foyer' ); ?></label></th>
					<td>
						<input type="number" min="15" step="5" id="foyer_printer_status_refresh" name="foyer_printer_status_refresh" value="<?php echo esc_attr( $config['refresh'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Wie häufig sollen die Druckdaten aktualisiert werden? Mindestwert 15 Sekunden.', 'foyer' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<h4><?php esc_html_e( 'Drucker', 'foyer' ); ?></h4>
        <p class="description"><?php esc_html_e( 'Ein Drucker pro Zeile. Seriennummer, LAN-Code und bei Bedarf individuelle MQTT-Zugangsdaten eintragen.', 'foyer' ); ?></p>
        <table class="widefat striped" id="foyer_printer_status_devices">
            <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'Host/IP', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'Seriennummer', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'MQTT-Port', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'TLS', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'Topic (optional)', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'MQTT Benutzer (optional)', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'MQTT Passwort (optional)', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'Access Code', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'Kamera-URL (optional)', 'foyer' ); ?></th>
                <th><?php esc_html_e( 'Entfernen', 'foyer' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $devices as $index => $device ) : ?>
                <?php
                $meta         = isset( $device['meta'] ) && is_array( $device['meta'] ) ? $device['meta'] : array();
                $serial       = isset( $meta['serial'] ) ? $meta['serial'] : '';
                $mqtt_port    = isset( $meta['mqtt_port'] ) ? $meta['mqtt_port'] : 8883;
                $use_tls      = isset( $meta['use_tls'] ) ? $meta['use_tls'] : '1';
                $status_topic = isset( $meta['status_topic'] ) ? $meta['status_topic'] : '';
                $mqtt_username = isset( $meta['mqtt_username'] ) ? $meta['mqtt_username'] : '';
                $mqtt_password = isset( $meta['mqtt_password'] ) ? $meta['mqtt_password'] : '';
                ?>
                <tr>
                    <td><input type="text" class="foyer-printer-name" name="foyer_printer_status_devices[name][]" value="<?php echo esc_attr( $device['name'] ); ?>" placeholder="<?php esc_attr_e( 'Bambu P1P', 'foyer' ); ?>" /></td>
                    <td><input type="text" class="foyer-printer-host" name="foyer_printer_status_devices[host][]" value="<?php echo esc_attr( $device['host'] ); ?>" placeholder="192.168.1.100" /></td>
                    <td><input type="text" class="foyer-printer-serial" name="foyer_printer_status_devices[serial][]" value="<?php echo esc_attr( $serial ); ?>" placeholder="A123B45C678D" /></td>
                    <td><input type="number" class="foyer-printer-mqtt-port" name="foyer_printer_status_devices[mqtt_port][]" value="<?php echo esc_attr( $mqtt_port ); ?>" min="1" step="1" /></td>
                    <td>
                        <select name="foyer_printer_status_devices[use_tls][]" class="foyer-printer-use-tls">
                            <option value="1" <?php selected( in_array( $use_tls, array( '1', 'yes', 'true', 'on' ), true ) ); ?>><?php esc_html_e( 'Ja (TLS)', 'foyer' ); ?></option>
                            <option value="0" <?php selected( in_array( $use_tls, array( '0', 'no', 'false', '' ), true ) ); ?>><?php esc_html_e( 'Nein', 'foyer' ); ?></option>
                        </select>
                    </td>
                    <td><input type="text" class="foyer-printer-topic" name="foyer_printer_status_devices[status_topic][]" value="<?php echo esc_attr( $status_topic ); ?>" placeholder="device/XXXX/report" /></td>
                    <td><input type="text" class="foyer-printer-mqtt-username" name="foyer_printer_status_devices[mqtt_username][]" value="<?php echo esc_attr( $mqtt_username ); ?>" placeholder="<?php esc_attr_e( 'z. B. Seriennummer', 'foyer' ); ?>" /></td>
                    <td><input type="text" class="foyer-printer-mqtt-password" name="foyer_printer_status_devices[mqtt_password][]" value="<?php echo esc_attr( $mqtt_password ); ?>" autocomplete="off" /></td>
                    <td><input type="text" class="foyer-printer-access-code" name="foyer_printer_status_devices[access_code][]" value="<?php echo esc_attr( $device['access_code'] ); ?>" autocomplete="off" /></td>
                    <td><input type="url" class="foyer-printer-camera" name="foyer_printer_status_devices[camera_url][]" value="<?php echo esc_attr( $device['camera_url'] ); ?>" placeholder="http://192.168.1.100:6000/" /></td>
                    <td><button type="button" class="button button-link-delete foyer-printer-remove-row" aria-label="<?php esc_attr_e( 'Zeile entfernen', 'foyer' ); ?>">&times;</button></td>
                </tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="foyer_printer_add_row"><?php esc_html_e( 'Weitere Zeile hinzufügen', 'foyer' ); ?></button>
		</p>
		<?php self::render_inline_script(); ?>
		<?php
	}

	/**
	 * Saves the meta box values.
	 *
	 * @param int $post_id Slide ID.
	 * @return void
	 */
	public static function save_slide( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_FIELD ], self::NONCE_FIELD ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$provider = isset( $_POST['foyer_printer_status_provider'] ) ? sanitize_key( wp_unslash( $_POST['foyer_printer_status_provider'] ) ) : Foyer_Printer_Status_Slide::DEFAULT_PROVIDER;
		update_post_meta( $post_id, Foyer_Printer_Status_Slide::META_PROVIDER, $provider );

		$refresh = isset( $_POST['foyer_printer_status_refresh'] ) ? absint( $_POST['foyer_printer_status_refresh'] ) : Foyer_Printer_Status_Slide::DEFAULT_REFRESH;
		if ( $refresh < 15 ) {
			$refresh = 15;
		}
		update_post_meta( $post_id, Foyer_Printer_Status_Slide::META_REFRESH, $refresh );

		$devices = array();
		if ( isset( $_POST['foyer_printer_status_devices'] ) && is_array( $_POST['foyer_printer_status_devices'] ) ) {
			$raw = wp_unslash( $_POST['foyer_printer_status_devices'] );
			$hosts = isset( $raw['host'] ) && is_array( $raw['host'] ) ? $raw['host'] : array();
			$count = count( $hosts );
			for ( $i = 0; $i < $count; $i++ ) {
				$host = isset( $raw['host'][ $i ] ) ? sanitize_text_field( $raw['host'][ $i ] ) : '';
				if ( empty( $host ) ) {
					continue;
				}
                $serial        = isset( $raw['serial'][ $i ] ) ? sanitize_text_field( $raw['serial'][ $i ] ) : '';
                $mqtt_port     = isset( $raw['mqtt_port'][ $i ] ) ? absint( $raw['mqtt_port'][ $i ] ) : 0;
                $use_tls       = isset( $raw['use_tls'][ $i ] ) ? sanitize_text_field( $raw['use_tls'][ $i ] ) : '1';
                $status_topic  = isset( $raw['status_topic'][ $i ] ) ? sanitize_text_field( $raw['status_topic'][ $i ] ) : '';
                $mqtt_username = isset( $raw['mqtt_username'][ $i ] ) ? sanitize_text_field( $raw['mqtt_username'][ $i ] ) : '';
                $mqtt_password = isset( $raw['mqtt_password'][ $i ] ) ? sanitize_text_field( $raw['mqtt_password'][ $i ] ) : '';

				$devices[] = array(
					'name'        => isset( $raw['name'][ $i ] ) ? sanitize_text_field( $raw['name'][ $i ] ) : '',
					'host'        => $host,
					'port'        => 0,
					'access_code' => isset( $raw['access_code'][ $i ] ) ? sanitize_text_field( $raw['access_code'][ $i ] ) : '',
					'camera_url'  => isset( $raw['camera_url'][ $i ] ) ? esc_url_raw( $raw['camera_url'][ $i ] ) : '',
					'meta'        => array(
                        'serial'        => $serial,
                        'use_tls'       => $use_tls,
                        'mqtt_port'     => $mqtt_port,
                        'status_topic'  => $status_topic,
                        'mqtt_username' => $mqtt_username,
                        'mqtt_password' => $mqtt_password,
                        'scheme'        => 'http',
                    ),
                );
			}
		}

		if ( empty( $devices ) ) {
			delete_post_meta( $post_id, Foyer_Printer_Status_Slide::META_DEVICES );
		} else {
			update_post_meta( $post_id, Foyer_Printer_Status_Slide::META_DEVICES, $devices );
		}

		Foyer_Printer_Status_Manager::clear_cache_for_slide( $post_id );
	}

	/**
	 * Prints a minimal inline script to handle row management.
	 *
	 * @return void
	 */
	private static function render_inline_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
			( function( $ ) {
				var table = $( '#foyer_printer_status_devices tbody' );
				$( '#foyer_printer_add_row' ).on( 'click', function( e ) {
					e.preventDefault();
					var $last = table.find( 'tr:last' );
					var $clone = $last.clone();
					$clone.find( 'input' ).val( '' );
					$clone.find( '.foyer-printer-mqtt-port' ).val( '8883' );
					$clone.find( '.foyer-printer-use-tls' ).val( '1' );
					table.append( $clone );
				} );
				table.on( 'click', '.foyer-printer-remove-row', function( e ) {
					e.preventDefault();
					if ( table.find( 'tr' ).length <= 1 ) {
						table.find( 'input' ).val( '' );
						table.find( '.foyer-printer-mqtt-port' ).val( '8883' );
						table.find( '.foyer-printer-use-tls' ).val( '1' );
						return;
					}
					$( this ).closest( 'tr' ).remove();
				} );
			} )( window.jQuery || window.$ );
		</script>
		<?php
	}
}
