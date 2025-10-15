<?php
/**
 * WebUntis Room Display slide template.
 */

$slide = new Foyer_Slide( get_the_id() );

$has_source_meta = metadata_exists( 'post', $slide->ID, Foyer_Admin_Slide_Format_Webuntis_Room_Display::META_SOURCE );
$source = get_post_meta( $slide->ID, Foyer_Admin_Slide_Format_Webuntis_Room_Display::META_SOURCE, true );
if ( ! $has_source_meta ) {
    $source = Foyer_Admin_Slide_Format_Webuntis_Room_Display::DEFAULT_SOURCE;
}
$fallback_url = trailingslashit( FOYER_PLUGIN_URL ) . Foyer_Admin_Slide_Format_Webuntis_Room_Display::FALLBACK_FILENAME;
$resolved_source = '' !== $source ? $source : $fallback_url;

$rooms = get_post_meta( $slide->ID, Foyer_Admin_Slide_Format_Webuntis_Room_Display::META_ROOMS, true );
$rooms = is_array( $rooms ) ? array_map( 'sanitize_text_field', $rooms ) : array();
$rooms = array_values( array_unique( array_filter( $rooms, 'strlen' ) ) );

$refresh = get_post_meta( $slide->ID, Foyer_Admin_Slide_Format_Webuntis_Room_Display::META_REFRESH, true );
$refresh = ( $refresh && $refresh > 0 ) ? absint( $refresh ) : Foyer_Admin_Slide_Format_Webuntis_Room_Display::DEFAULT_REFRESH;
if ( $refresh < 15 ) {
    $refresh = 15;
}

$hide_upcoming = get_post_meta( $slide->ID, Foyer_Admin_Slide_Format_Webuntis_Room_Display::META_HIDE_UPCOMING, true ) === 'yes';
$hide_current  = get_post_meta( $slide->ID, Foyer_Admin_Slide_Format_Webuntis_Room_Display::META_HIDE_CURRENT, true ) === 'yes';

$locale   = get_locale();
$timezone = wp_timezone_string();

wp_enqueue_script( 'foyer-webuntis-room-display' );

$dataset = array(
    'source'          => esc_url( $resolved_source ),
    'rooms'           => esc_attr( wp_json_encode( $rooms ) ),
    'refresh'         => esc_attr( $refresh ),
    'locale'          => esc_attr( $locale ),
    'timezone'        => esc_attr( $timezone ),
    'labelFree'       => esc_attr__( 'Frei', 'foyer' ),
    'labelOccupied'   => esc_attr__( 'Belegt', 'foyer' ),
    'labelCurrent'    => esc_attr__( 'Aktuell', 'foyer' ),
    'labelUpcoming'   => esc_attr__( 'Nächste Belegungen', 'foyer' ),
    'labelNoUpcoming' => esc_attr__( 'Keine weiteren Belegungen heute.', 'foyer' ),
    'labelFreeNow'    => esc_attr__( 'Der Raum ist aktuell frei.', 'foyer' ),
    'labelUpdated'    => esc_attr__( 'Aktualisiert um %s', 'foyer' ),
    'labelSubject'    => esc_attr__( 'Fach', 'foyer' ),
    'labelClasses'    => esc_attr__( 'Klasse(n)', 'foyer' ),
    'labelTeachers'   => esc_attr__( 'Lehrperson(en)', 'foyer' ),
    'labelRemarks'    => esc_attr__( 'Hinweis', 'foyer' ),
    'errorMessage'    => esc_attr__( 'Daten konnten nicht geladen werden.', 'foyer' ),
    'errorNoRooms'    => esc_attr__( 'Bitte wählen Sie mindestens einen Raum in den Folieneinstellungen aus.', 'foyer' ),
    'hideUpcoming'    => $hide_upcoming ? '1' : '0',
    'hideCurrent'     => $hide_current ? '1' : '0',
);
?>
<div<?php $slide->classes( array( 'foyer-slide-webuntis-room-display' ) ); ?><?php $slide->data_attr(); ?>>
    <div class="foyer-webuntis-room-display"
        data-source="<?php echo $dataset['source']; ?>"
        data-rooms="<?php echo $dataset['rooms']; ?>"
        data-refresh="<?php echo $dataset['refresh']; ?>"
        data-locale="<?php echo $dataset['locale']; ?>"
        data-timezone="<?php echo $dataset['timezone']; ?>"
        data-label-free="<?php echo $dataset['labelFree']; ?>"
        data-label-occupied="<?php echo $dataset['labelOccupied']; ?>"
        data-label-current="<?php echo $dataset['labelCurrent']; ?>"
        data-label-upcoming="<?php echo $dataset['labelUpcoming']; ?>"
        data-label-no-upcoming="<?php echo $dataset['labelNoUpcoming']; ?>"
        data-label-free-now="<?php echo $dataset['labelFreeNow']; ?>"
        data-label-updated="<?php echo $dataset['labelUpdated']; ?>"
        data-label-subject="<?php echo $dataset['labelSubject']; ?>"
        data-label-classes="<?php echo $dataset['labelClasses']; ?>"
        data-label-teachers="<?php echo $dataset['labelTeachers']; ?>"
        data-label-remarks="<?php echo $dataset['labelRemarks']; ?>"
        data-error-message="<?php echo $dataset['errorMessage']; ?>"
        data-error-no-rooms="<?php echo $dataset['errorNoRooms']; ?>"
        data-hide-upcoming="<?php echo esc_attr( $dataset['hideUpcoming'] ); ?>"
        data-hide-current="<?php echo esc_attr( $dataset['hideCurrent'] ); ?>">
        <header class="foyer-webuntis-room-display__page-header">
            <div class="foyer-webuntis-room-display__page-meta">
                <div class="foyer-webuntis-room-display__clock" data-format-date="<?php echo esc_attr( _x( 'd.m.Y', 'WebUntis Room Display date format', 'foyer' ) ); ?>" data-format-time="<?php echo esc_attr( _x( 'H:i', 'WebUntis Room Display time format', 'foyer' ) ); ?>">
                    <span class="foyer-webuntis-room-display__clock-date">--.--.----</span>
                    <span class="foyer-webuntis-room-display__clock-time">--:--</span>
                </div>
                <p class="foyer-webuntis-room-display__updated" aria-live="polite"><?php echo esc_html( sprintf( __( 'Aktualisiert um %s', 'foyer' ), '--:--' ) ); ?></p>
            </div>
        </header>
        <div class="foyer-webuntis-room-display__grid" aria-live="polite">
            <div class="foyer-webuntis-room-display__placeholder"><?php esc_html_e( 'Lade Belegungen …', 'foyer' ); ?></div>
        </div>
        <div class="foyer-webuntis-room-display__error" role="alert" hidden></div>
        <noscript>
            <p class="foyer-webuntis-room-display__noscript"><?php esc_html_e( 'Diese Folie benötigt JavaScript, um die Raumbelegungen anzuzeigen.', 'foyer' ); ?></p>
        </noscript>
    </div>
    <?php $slide->background(); ?>
</div>
