<?php

/**
 * Admin helpers for the WebUntis Room Display slide format.
 */
class Foyer_Admin_Slide_Format_Webuntis_Room_Display {

    const META_SOURCE  = 'slide_webuntis_room_display_source';
    const META_ROOMS   = 'slide_webuntis_room_display_rooms';
    const META_REFRESH = 'slide_webuntis_room_display_refresh';

    const DEFAULT_SOURCE       = 'https://bigbrother2.lgsit.de/untisdata/raeume_ganzer_tag.txt';
    const DEFAULT_REFRESH      = 60; // seconds
    const CACHE_TTL            = 300; // 5 minutes
    const META_HIDE_UPCOMING   = 'slide_webuntis_room_display_hide_upcoming';
    const META_HIDE_CURRENT    = 'slide_webuntis_room_display_hide_current';

    /**
     * Render the meta box for configuring the slide format.
     *
     * @param WP_Post $post
     */
    public static function slide_meta_box( $post ) {
        wp_nonce_field( 'foyer_webuntis_room_display_meta', 'foyer_webuntis_room_display_meta' );

        $source = get_post_meta( $post->ID, self::META_SOURCE, true );
        if ( empty( $source ) ) {
            $source = self::DEFAULT_SOURCE;
        }

        $selected_rooms = get_post_meta( $post->ID, self::META_ROOMS, true );
        $selected_rooms = is_array( $selected_rooms ) ? array_map( 'sanitize_text_field', $selected_rooms ) : array();
        $selected_rooms = array_values( array_unique( array_filter( $selected_rooms, 'strlen' ) ) );

        $refresh = get_post_meta( $post->ID, self::META_REFRESH, true );
        $refresh = ( $refresh && $refresh > 0 ) ? absint( $refresh ) : self::DEFAULT_REFRESH;

        $hide_upcoming = get_post_meta( $post->ID, self::META_HIDE_UPCOMING, true ) === 'yes';
        $hide_current  = get_post_meta( $post->ID, self::META_HIDE_CURRENT, true ) === 'yes';

        $rooms = self::get_room_choices( $source );
        $rooms_error = is_wp_error( $rooms );

        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr( self::META_SOURCE ); ?>">
                            <?php esc_html_e( 'Datenquelle (TXT URL)', 'foyer' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url" class="large-text" id="<?php echo esc_attr( self::META_SOURCE ); ?>" name="<?php echo esc_attr( self::META_SOURCE ); ?>" value="<?php echo esc_attr( $source ); ?>" placeholder="https://example.com/raeume.txt" />
                        <p class="description"><?php esc_html_e( 'Erwartet eine Pipe-getrennte Untis-Liste (raeume_ganzer_tag.txt).', 'foyer' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr( self::META_ROOMS ); ?>">
                            <?php esc_html_e( 'Räume auf dieser Folie', 'foyer' ); ?>
                        </label>
                    </th>
                    <td>
                        <?php if ( $rooms_error ) : ?>
                            <div class="notice notice-error inline">
                                <p><?php echo esc_html( $rooms->get_error_message() ); ?></p>
                            </div>
                            <?php if ( ! empty( $selected_rooms ) ) : ?>
                                <p class="description"><?php esc_html_e( 'Die gespeicherte Auswahl bleibt erhalten, konnte aber nicht aktualisiert werden.', 'foyer' ); ?></p>
                            <?php endif; ?>
                        <?php else : ?>
                            <select multiple size="10" class="widefat" id="<?php echo esc_attr( self::META_ROOMS ); ?>" name="<?php echo esc_attr( self::META_ROOMS ); ?>[]">
                                <?php foreach ( $rooms as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( in_array( $value, $selected_rooms, true ) ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Wählen Sie die Räume aus, die auf dieser Folie erscheinen sollen. Ohne Auswahl wird kein Inhalt angezeigt.', 'foyer' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr( self::META_REFRESH ); ?>">
                            <?php esc_html_e( 'Aktualisierungsintervall (Sekunden)', 'foyer' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" min="15" step="5" id="<?php echo esc_attr( self::META_REFRESH ); ?>" name="<?php echo esc_attr( self::META_REFRESH ); ?>" value="<?php echo esc_attr( $refresh ); ?>" />
                        <p class="description"><?php esc_html_e( 'Die Daten werden zyklisch neu geladen. Empfohlen: 30–120 Sekunden.', 'foyer' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr( self::META_HIDE_UPCOMING ); ?>">
                            <?php esc_html_e( 'Nächste Belegungen ausblenden', 'foyer' ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="<?php echo esc_attr( self::META_HIDE_UPCOMING ); ?>" name="<?php echo esc_attr( self::META_HIDE_UPCOMING ); ?>" value="yes" <?php checked( $hide_upcoming ); ?> />
                            <?php esc_html_e( 'Nur die aktuelle Belegung anzeigen (falls vorhanden).', 'foyer' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr( self::META_HIDE_CURRENT ); ?>">
                            <?php esc_html_e( '"Aktuell"-Überschrift ausblenden', 'foyer' ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="<?php echo esc_attr( self::META_HIDE_CURRENT ); ?>" name="<?php echo esc_attr( self::META_HIDE_CURRENT ); ?>" value="yes" <?php checked( $hide_current ); ?> />
                            <?php esc_html_e( 'Den Abschnittstitel "Aktuell" verbergen.', 'foyer' ); ?>
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Save the meta box data.
     *
     * @param int $post_id
     */
    public static function save_slide( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! isset( $_POST['foyer_webuntis_room_display_meta'] ) || ! wp_verify_nonce( $_POST['foyer_webuntis_room_display_meta'], 'foyer_webuntis_room_display_meta' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $previous_source = get_post_meta( $post_id, self::META_SOURCE, true );

        $source = isset( $_POST[ self::META_SOURCE ] ) ? esc_url_raw( trim( wp_unslash( $_POST[ self::META_SOURCE ] ) ) ) : '';
        if ( empty( $source ) ) {
            $source = self::DEFAULT_SOURCE;
        }
        update_post_meta( $post_id, self::META_SOURCE, $source );
        delete_transient( self::get_cache_key( $source ) );
        if ( ! empty( $previous_source ) && $previous_source !== $source ) {
            delete_transient( self::get_cache_key( $previous_source ) );
        }

        $rooms = array();
        if ( isset( $_POST[ self::META_ROOMS ] ) && is_array( $_POST[ self::META_ROOMS ] ) ) {
            $rooms = array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::META_ROOMS ] ) );
            $rooms = array_values( array_unique( array_filter( $rooms, 'strlen' ) ) );
        }
        update_post_meta( $post_id, self::META_ROOMS, $rooms );

        $refresh = isset( $_POST[ self::META_REFRESH ] ) ? absint( $_POST[ self::META_REFRESH ] ) : self::DEFAULT_REFRESH;
        if ( $refresh < 15 ) {
            $refresh = 15;
        }
        update_post_meta( $post_id, self::META_REFRESH, $refresh );

        $hide_upcoming = ( isset( $_POST[ self::META_HIDE_UPCOMING ] ) && 'yes' === $_POST[ self::META_HIDE_UPCOMING ] ) ? 'yes' : 'no';
        update_post_meta( $post_id, self::META_HIDE_UPCOMING, $hide_upcoming );

        $hide_current = ( isset( $_POST[ self::META_HIDE_CURRENT ] ) && 'yes' === $_POST[ self::META_HIDE_CURRENT ] ) ? 'yes' : 'no';
        update_post_meta( $post_id, self::META_HIDE_CURRENT, $hide_current );
    }

    /**
     * Fetch room choices from the configured source.
     *
     * @param string $source
     * @return array|WP_Error
     */
    private static function get_room_choices( $source ) {
        if ( empty( $source ) ) {
            return new WP_Error( 'foyer_webuntis_room_missing_source', __( 'Bitte geben Sie eine gültige Datenquelle an.', 'foyer' ) );
        }

        $cache_key = self::get_cache_key( $source );
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get( $source, array( 'timeout' => 5, 'headers' => array( 'Accept' => 'text/plain' ) ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'foyer_webuntis_room_remote_error', sprintf( __( 'Daten konnten nicht geladen werden: %s', 'foyer' ), $response->get_error_message() ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            return new WP_Error( 'foyer_webuntis_room_http_error', sprintf( __( 'Antwort der Datenquelle: HTTP %d', 'foyer' ), (int) $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === trim( (string) $body ) ) {
            return new WP_Error( 'foyer_webuntis_room_empty', __( 'Die Datenquelle liefert keine Inhalte.', 'foyer' ) );
        }

        $choices = self::parse_room_names( $body );
        if ( empty( $choices ) ) {
            return new WP_Error( 'foyer_webuntis_room_no_rooms', __( 'In der Datenquelle wurden keine Räume gefunden.', 'foyer' ) );
        }

        set_transient( $cache_key, $choices, self::CACHE_TTL );

        return $choices;
    }

    /**
     * Parse unique room names out of the Untis export.
     *
     * @param string $body
     * @return array<Hash,string>
     */
    private static function parse_room_names( $body ) {
        $lines  = preg_split( "/\r\n|\r|\n/", (string) $body );
        $rooms  = array();

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $parts = explode( '|', $line );
            $room  = isset( $parts[0] ) ? trim( $parts[0] ) : '';
            if ( '' === $room ) {
                continue;
            }

            $rooms[ $room ] = $room;
        }

        if ( ! empty( $rooms ) ) {
            natcasesort( $rooms );
        }

        return $rooms;
    }

    /**
     * Build the transient cache key for a specific source URL.
     *
     * @param string $source
     * @return string
     */
    private static function get_cache_key( $source ) {
        return 'foyer_wr_rooms_' . md5( $source );
    }
}
