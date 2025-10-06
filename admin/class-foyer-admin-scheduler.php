<?php

/**
 * Admin page to view and edit scheduled channels per display.
 *
 * @package Foyer\admin
 */
class Foyer_Admin_Scheduler {

    /**
     * Registers the "Scheduler" submenu under Foyer.
     */
    public static function admin_menu() {
        add_submenu_page(
            'foyer',
            __( 'Scheduler', 'foyer' ),
            __( 'Scheduler', 'foyer' ),
            'edit_posts',
            'foyer_scheduler',
            array( __CLASS__, 'render_page' )
        );

        // Register AJAX endpoints for calendar-based schedules
        add_action( 'wp_ajax_foyer_schedules_get_events', array( __CLASS__, 'ajax_get_events' ) );
        add_action( 'wp_ajax_foyer_schedules_create_event', array( __CLASS__, 'ajax_create_event' ) );
        add_action( 'wp_ajax_foyer_schedules_update_event', array( __CLASS__, 'ajax_update_event' ) );
        add_action( 'wp_ajax_foyer_schedules_delete_event', array( __CLASS__, 'ajax_delete_event' ) );
        add_action( 'wp_ajax_foyer_schedules_get_schedule', array( __CLASS__, 'ajax_get_schedule' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Nonce used by the calendar AJAX endpoints.
     */
    private static function get_calendar_nonce() {
        return wp_create_nonce( 'foyer_calendar_nonce' );
    }

    /**
     * Enqueue assets for the scheduler page (calendar UI, styles, and script).
     *
     * @param string $hook
     * @return void
     */
    public static function enqueue_assets( $hook = '' ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( 'foyer_scheduler' !== $page ) {
            return;
        }

        // EventCalendar: ensure local hosting; download once if missing
        $__base_url  = plugin_dir_url( __FILE__ );
        $__base_path = plugin_dir_path( __FILE__ );
        $ev_dir_rel  = 'vendor/event-calendar/';
        $ev_local_css = $ev_dir_rel . 'event-calendar.min.css';
        $ev_local_js  = $ev_dir_rel . 'event-calendar.min.js';
        $ev_local_js_map  = $ev_dir_rel . 'event-calendar.min.js.map';
        $ev_css_abs   = $__base_path . $ev_local_css;
        $ev_js_abs    = $__base_path . $ev_local_js;
        $ev_js_map_abs    = $__base_path . $ev_local_js_map;

        
        wp_enqueue_style( 'foyer-event-calendar', $__base_url . $ev_local_css, array(), '4.6.0' );
        wp_enqueue_script( 'foyer-event-calendar', $__base_url . $ev_local_js, array(), '4.6.0', true );

        
        $base_url  = plugin_dir_url( __FILE__ );
        $base_path = plugin_dir_path( __FILE__ );
        $css_rel = 'css/foyer-scheduler.css';
        $js_rel  = 'js/foyer-scheduler.js';
        $css_ver = file_exists( $base_path . $css_rel ) ? filemtime( $base_path . $css_rel ) : null;
        $js_ver  = file_exists( $base_path . $js_rel ) ? filemtime( $base_path . $js_rel ) : null;

        // Our scheduler assets
        wp_enqueue_style( 'foyer-scheduler', $base_url . $css_rel, array(), $css_ver );
        wp_register_script( 'foyer-scheduler', $base_url . $js_rel, array( 'foyer-event-calendar' ), $js_ver, true );

        // Localized data for JS (nonce, AJAX url, site TZ, channels)
        $channels = Foyer_Channels::get_posts();
        $channels_data = array();
        if ( ! empty( $channels ) ) {
            foreach ( $channels as $ch ) {
                $author_name = get_the_author_meta( 'display_name', $ch->post_author );
                $modified_gmt = get_post_modified_time( 'c', true, $ch );
                $permalink = get_permalink( $ch->ID );
                $preview_url = add_query_arg( 'foyer-preview', 1, $permalink );
                $slides_count = 0;
                $created_ts = get_post_time( 'U', true, $ch );
                $favorite = get_post_meta( $ch->ID, 'foyer_channel_is_favorite', true ) ? 1 : 0;
                try {
                    $channel_obj = new Foyer_Channel( $ch );
                    if ( method_exists( $channel_obj, 'get_slides' ) ) {
                        $slides = $channel_obj->get_slides();
                        if ( is_array( $slides ) ) { $slides_count = count( $slides ); }
                    }
                } catch ( Exception $e ) {}
                $channels_data[] = array(
                    'id'           => intval( $ch->ID ),
                    'title'        => get_the_title( $ch->ID ),
                    'author_name'  => $author_name,
                    'modified_gmt' => $modified_gmt,
                    'slides_count' => $slides_count,
                    'preview_url'  => $preview_url,
                    'favorite'     => $favorite,
                    'created_ts'   => intval( $created_ts ),
                );
            }
        }

        $debug_enabled = false;
        if ( class_exists( 'Foyer_Admin_Settings' ) ) {
            $debug_enabled = (bool) get_option( Foyer_Admin_Settings::OPTION_SCHEDULER_DEBUG, 0 );
        }

        $data = array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => self::get_calendar_nonce(),
            'siteTz'  => wp_timezone_string(),
            'channels'=> $channels_data,
            'debug'   => $debug_enabled,
        );
        wp_localize_script( 'foyer-scheduler', 'foyerSchedulerData', $data );

        // Provide translatable UI strings to JS
        $i18n = array(
            'selectDisplay'    => __( 'Please select at least one display.', 'foyer' ),
            'selectChannel'    => __( 'Please select a channel.', 'foyer' ),
            'createTitle'      => __( 'Create new Schedule', 'foyer' ),
            'editTitle'        => __( 'Edit Schedule', 'foyer' ),
            'startRequired'    => __( 'Start is required.', 'foyer' ),
            'cancel'           => __( 'Cancel', 'foyer' ),
            'save'             => __( 'Save', 'foyer' ),
            'deleteSchedule'   => __( 'Delete schedule', 'foyer' ),
            'deleteFailed'     => __( 'Delete failed', 'foyer' ),
            'deleteOccurrence' => __( 'Delete only this occurrence', 'foyer' ),
            'confirmDelete'    => __( 'Delete entire schedule? This affects all displays and all occurrences.', 'foyer' ),
            'confirmDeleteOcc' => __( 'Delete this single occurrence? This affects all displays of this schedule.', 'foyer' ),
            'channelsHeading'  => __( 'Channels', 'foyer' ),
            'displaysHeading'  => __( 'Displays', 'foyer' ),
            'selectAll'        => __( 'Select all', 'foyer' ),
            'searchPlaceholder'=> __( 'Search…', 'foyer' ),
            // Extended i18n for overlay UI
            'startLabel'       => __( 'Start', 'foyer' ),
            'endLabel'         => __( 'End', 'foyer' ),
            'freqSingle'       => __( 'Single', 'foyer' ),
            'freqDaily'        => __( 'Daily', 'foyer' ),
            'freqWeekly'       => __( 'Weekly', 'foyer' ),
            'freqMonthly'      => __( 'Monthly', 'foyer' ),
            'freqGroupLabel'   => __( 'Frequency', 'foyer' ),
            'every'            => __( 'every', 'foyer' ),
            'unitDay'          => __( 'day(s)', 'foyer' ),
            'unitWeek'         => __( 'week(s)', 'foyer' ),
            'unitMonth'        => __( 'month(s)', 'foyer' ),
            'weekDaysLabel'    => __( 'Days:', 'foyer' ),
            'monthDaysLabel'   => __( 'Month days (e.g. 1,15,31)', 'foyer' ),
            'endNever'         => __( 'Never ends', 'foyer' ),
            'endUntil'         => __( 'Ends at', 'foyer' ),
            'endCount'         => __( 'Ends after', 'foyer' ),
            'termsWord'        => __( 'occurrences', 'foyer' ),
            'onWord'           => __( 'on', 'foyer' ),
            'onDaysWord'       => __( 'on day(s)', 'foyer' ),
            'perPage'          => __( 'Per page', 'foyer' ),
            'pageWord'         => __( 'Page', 'foyer' ),
            'hitsWord'         => __( 'hits', 'foyer' ),
            'slidesWord'       => __( 'Slides', 'foyer' ),
            'loadingLabel'     => __( 'Loading…', 'foyer' ),
            'calendarInitError'=> __( 'Calendar failed to initialize', 'foyer' ),
            'updateFailed'     => __( 'Update failed', 'foyer' ),
            'fetchFailed'      => __( 'Fetch failed', 'foyer' ),
            'saveFailed'       => __( 'Save failed', 'foyer' ),
            'scheduleFallback' => __( 'Schedule', 'foyer' ),
            'displayNumber'    => __( 'Display #%d', 'foyer' ),
            'channelNumber'    => __( 'Channel #%d', 'foyer' ),
            'eventsLabel'      => __( 'Events', 'foyer' ),
            'firstLabel'       => __( 'First', 'foyer' ),
            'renderedLabel'    => __( 'Rendered', 'foyer' ),
            'errorLabel'       => __( 'Error', 'foyer' ),
        );
        wp_localize_script( 'foyer-scheduler', 'foyerSchedulerI18n', $i18n );

        wp_enqueue_script( 'foyer-scheduler' );
    }

    /**
     * Deterministic color per display for calendar event backgrounds.
     */
    private static function color_for_display( $display_id ) {
        $display_id = intval( $display_id );
        $h = ( $display_id * 57 ) % 360; // pseudo-random hue
        return sprintf( 'hsl(%d, 60%%, 70%%)', $h );
    }

    private static function parse_iso_to_ts( $value ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( '' === $value ) { return null; }
        try {
            $dt = new DateTimeImmutable( $value );
            return $dt->getTimestamp();
        } catch ( Exception $e ) { return null; }
    }

    /**
     * Returns events for the selected displays and time window.
     * Output format tailored for calendar consumption.
     */
    public static function ajax_get_events() {
        check_ajax_referer( 'foyer_calendar_nonce', 'nonce', true );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed', 'foyer' ) ), 403 );
        }

        $display_ids = isset( $_POST['display_ids'] ) ? (array) $_POST['display_ids'] : array();
        $display_ids = array_values( array_unique( array_map( 'intval', $display_ids ) ) );
        $start_iso = isset( $_POST['start'] ) ? (string) $_POST['start'] : '';
        $end_iso   = isset( $_POST['end'] )   ? (string) $_POST['end']   : '';
        $start_ts = self::parse_iso_to_ts( $start_iso );
        $end_ts   = self::parse_iso_to_ts( $end_iso );
        if ( empty( $display_ids ) || is_null( $start_ts ) || is_null( $end_ts ) ) {
            wp_send_json_success( array( 'events' => array() ) );
        }

        // Simple cache per schedule post to avoid repeated expand across displays
        $occ_cache = array();

        // Group events by schedule occurrence (schedule_post_id + occ_id)
        $groups = array();
        foreach ( $display_ids as $did ) {
            // Find schedules that include this display
            $posts = get_posts( array(
                'post_type'      => 'foyer_schedule',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'     => 'foyer_schedule_displays',
                        'value'   => 'i:' . $did . ';',
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => 'foyer_schedule_displays',
                        'value'   => '"' . $did . '"',
                        'compare' => 'LIKE',
                    ),
                ),
            ) );

            if ( empty( $posts ) ) { continue; }

            foreach ( $posts as $p ) {
                if ( isset( $occ_cache[ $p->ID ] ) ) {
                    $meta = $occ_cache[ $p->ID ]['meta'];
                    $occ  = $occ_cache[ $p->ID ]['occ'];
                } else {
                    $meta = Foyer_Schedules::read_meta( $p->ID );
                    $occ  = Foyer_Schedule_Engine::expand_occurrences( $meta, $start_ts, $end_ts );
                    $occ_cache[ $p->ID ] = array( 'meta' => $meta, 'occ' => $occ );
                }
                if ( empty( $occ ) ) { continue; }
                foreach ( $occ as $o ) {
                    $start_utc = intval( $o['start_utc'] );
                    $end_utc   = intval( $o['end_utc'] );
                    $occ_id    = isset( $o['occ_id'] ) ? (string) $o['occ_id'] : gmdate( 'Y-m-d\TH:i:s\Z', $start_utc );
                    $cid       = isset( $o['channel'] ) && intval( $o['channel'] ) > 0 ? intval( $o['channel'] ) : intval( $meta['channel'] );
                    $key       = $p->ID . '|' . $occ_id;

                    if ( ! isset( $groups[ $key ] ) ) {
                        $ctitle = $cid ? get_the_title( $cid ) : __( '(No channel)', 'foyer' );
                        // Build full display set for this schedule (all affected displays)
                        $sched_displays = get_post_meta( $p->ID, 'foyer_schedule_displays', true );
                        if ( ! is_array( $sched_displays ) ) { $sched_displays = array(); }
                        $sched_displays = array_values( array_unique( array_map( 'intval', $sched_displays ) ) );

                        $groups[ $key ] = array(
                            'id'        => $key,
                            'title'     => $ctitle,
                            'startDate' => gmdate( 'Y-m-d\TH:i:s\Z', $start_utc ),
                            'endDate'   => gmdate( 'Y-m-d\TH:i:s\Z', $end_utc ),
                            'extendedProps' => array(
                                'schedule_post_id' => intval( $p->ID ),
                                'occ_id'           => $occ_id,
                                'channel_id'       => $cid,
                                'display_ids'      => $sched_displays,
                                'displays'         => array(),
                                'source'           => isset( $o['source'] ) ? (string) $o['source'] : '',
                                'tz'               => isset( $meta['tz'] ) && $meta['tz'] ? (string) $meta['tz'] : wp_timezone_string(),
                            ),
                        );

                        foreach ( $sched_displays as $sdid ) {
                            $groups[ $key ]['extendedProps']['displays'][] = array(
                                'id'    => intval( $sdid ),
                                'title' => get_the_title( $sdid ),
                                'color' => self::color_for_display( $sdid ),
                            );
                        }
                    }
                }
            }
        }

        // Normalize groups: de-duplicate displays
        $events = array();
        foreach ( $groups as $g ) {
            // unique display_ids
            $g['extendedProps']['display_ids'] = array_values( array_unique( array_map( 'intval', $g['extendedProps']['display_ids'] ) ) );
            // unique displays by id
            $seen = array();
            $uniq = array();
            foreach ( $g['extendedProps']['displays'] as $d ) {
                $id = isset( $d['id'] ) ? intval( $d['id'] ) : 0;
                if ( $id && ! isset( $seen[ $id ] ) ) {
                    $seen[ $id ] = true;
                    $uniq[] = $d;
                }
            }
            $g['extendedProps']['displays'] = $uniq;
            $events[] = $g;
        }

        wp_send_json_success( array( 'events' => $events ) );
    }

    public static function ajax_create_event() {
        check_ajax_referer( 'foyer_calendar_nonce', 'nonce', true );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed', 'foyer' ) ), 403 );
        }
        $display_ids = isset( $_POST['display_ids'] ) ? array_map( 'intval', (array) $_POST['display_ids'] ) : array();
        $channel_id  = isset( $_POST['channel_id'] ) ? intval( $_POST['channel_id'] ) : 0;
        $tzid        = isset( $_POST['tz'] ) ? (string) $_POST['tz'] : wp_timezone_string();
        $mode        = isset( $_POST['mode'] ) ? strtolower( sanitize_text_field( (string) $_POST['mode'] ) ) : 'single';

        // Validate permissions for all selected displays
        if ( ! empty( $display_ids ) ) {
            $display_ids = array_values( array_unique( array_filter( $display_ids ) ) );
            foreach ( $display_ids as $did_check ) {
                if ( ! current_user_can( 'edit_post', $did_check ) ) {
                    wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this display.', 'foyer' ) ), 403 );
                }
            }
        }

        if ( 'recur' === $mode ) {
            // Create a recurring schedule via RRULE builder fields
            $display_ids = array_values( array_unique( array_filter( $display_ids ) ) );
            if ( empty( $display_ids ) ) {
                wp_send_json_error( array( 'message' => __( 'No displays selected.', 'foyer' ) ) );
            }
            if ( $channel_id <= 0 ) {
                wp_send_json_error( array( 'message' => __( 'Invalid channel.', 'foyer' ) ) );
            }
            $dtstart_local = isset( $_POST['dtstart_local'] ) ? trim( (string) $_POST['dtstart_local'] ) : '';
            $duration      = isset( $_POST['duration'] ) ? intval( $_POST['duration'] ) : 0;
            if ( '' === $dtstart_local ) {
                wp_send_json_error( array( 'message' => __( 'Start time required.', 'foyer' ) ) );
            }
            if ( $duration <= 0 ) { $duration = HOUR_IN_SECONDS; }
            try { $tz = new DateTimeZone( $tzid ); } catch ( Exception $e ) { $tz = wp_timezone(); $tzid = wp_timezone_string(); }
            $start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace('T',' ', $dtstart_local ), $tz );
            if ( false === $start_dt ) { try { $start_dt = new DateTimeImmutable( $dtstart_local, $tz ); } catch ( Exception $e ) { $start_dt = false; } }
            if ( ! ( $start_dt instanceof DateTimeImmutable ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid start time.', 'foyer' ) ) );
            }

            // Build RRULE
            $rrule = Foyer_Schedules::build_rrule_from_builder_fields( $_POST );
            if ( '' === $rrule ) {
                wp_send_json_error( array( 'message' => __( 'Invalid recurrence rule.', 'foyer' ) ) );
            }

            // Conflict check window
            $candidate_meta = array(
                'post_id'       => 0,
                'channel'       => $channel_id,
                'displays'      => $display_ids,
                'tz'            => $tzid,
                'dtstart_local' => $start_dt->format('Y-m-d H:i:s'),
                'duration'      => $duration,
                'rrule'         => $rrule,
                'rdates'        => array(),
                'exdates'       => array(),
                'overrides'     => array(),
            );
            $winStart = $start_dt->setTimezone( new DateTimeZone('UTC') )->getTimestamp() - DAY_IN_SECONDS;
            $winEnd   = $winStart + 365 * DAY_IN_SECONDS;
            $new_occ  = Foyer_Schedule_Engine::expand_occurrences( $candidate_meta, $winStart, $winEnd );

            foreach ( $display_ids as $did ) {
                $others = get_posts( array(
                    'post_type'      => 'foyer_schedule',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'meta_query'     => array(
                        'relation' => 'OR',
                        array( 'key' => 'foyer_schedule_displays', 'value' => 'i:' . intval($did) . ';', 'compare' => 'LIKE' ),
                        array( 'key' => 'foyer_schedule_displays', 'value' => '"' . intval($did) . '"', 'compare' => 'LIKE' ),
                    ),
                ) );
                foreach ( $others as $op ) {
                    $m = Foyer_Schedules::read_meta( $op->ID );
                    $o_occ = Foyer_Schedule_Engine::expand_occurrences( $m, $winStart, $winEnd );
                    foreach ( $o_occ as $b ) {
                        $bs = intval( $b['start_utc'] ); $be = intval( $b['end_utc'] );
                        foreach ( $new_occ as $a ) {
                            $as = intval( $a['start_utc'] ); $ae = intval( $a['end_utc'] );
                            if ( $be > $as && $ae > $bs ) {
                                $msg = sprintf( __( 'Display "%1$s" conflicts with schedule "%2$s".', 'foyer' ), get_the_title( $did ), get_the_title( $op->ID ) );
                                wp_send_json_error( array( 'message' => $msg ) );
                            }
                        }
                    }
                }
            }

            // Create post
            $title = sprintf( __( 'Schedule (recur): %1$s (%2$s)', 'foyer' ), get_the_title( $channel_id ), $start_dt->format( 'Y-m-d H:i' ) );
            $pid = wp_insert_post( array( 'post_title' => $title, 'post_type' => 'foyer_schedule', 'post_status' => 'publish' ) );
            if ( is_wp_error( $pid ) || ! $pid ) {
                wp_send_json_error( array( 'message' => __( 'Could not create schedule.', 'foyer' ) ) );
            }
            update_post_meta( $pid, 'foyer_schedule_channel', $channel_id );
            update_post_meta( $pid, 'foyer_schedule_displays', $display_ids );
            update_post_meta( $pid, 'foyer_schedule_tz', $tzid );
            update_post_meta( $pid, 'foyer_schedule_dtstart_local', $start_dt->format('Y-m-d H:i:s') );
            update_post_meta( $pid, 'foyer_schedule_duration', $duration );
            update_post_meta( $pid, 'foyer_schedule_rrule', $rrule );
            update_post_meta( $pid, 'foyer_schedule_mode', 'recurring' );

            wp_send_json_success( array( 'ok' => true, 'post_id' => intval( $pid ) ) );
        }

        // Default single-occurrence path
        $display_ids = array_values( array_unique( array_filter( $display_ids ) ) );
        $start_local = isset( $_POST['start_local'] ) ? trim( (string) $_POST['start_local'] ) : '';
        $end_local   = isset( $_POST['end_local'] ) ? trim( (string) $_POST['end_local'] ) : '';
        if ( empty( $display_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No displays selected.', 'foyer' ) ) );
        }
        if ( $channel_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid channel.', 'foyer' ) ) );
        }
        if ( '' === $start_local ) {
            wp_send_json_error( array( 'message' => __( 'Start time required.', 'foyer' ) ) );
        }
        try {
            $tz = new DateTimeZone( $tzid );
        } catch ( Exception $e ) {
            $tz = wp_timezone();
            $tzid = wp_timezone_string();
        }
        $start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace('T',' ', $start_local ), $tz );
        if ( false === $start_dt ) { try { $start_dt = new DateTimeImmutable( $start_local, $tz ); } catch ( Exception $e ) { $start_dt = false; } }
        $end_dt   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace('T',' ', $end_local ), $tz );
        if ( false === $end_dt ) { try { $end_dt = new DateTimeImmutable( $end_local, $tz ); } catch ( Exception $e ) { $end_dt = false; } }
        if ( ! ( $start_dt instanceof DateTimeImmutable ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid start time.', 'foyer' ) ) );
        }
        if ( ! ( $end_dt instanceof DateTimeImmutable ) ) {
            $end_dt = $start_dt->modify( '+1 hour' );
        }
        $s = $start_dt->setTimezone( new DateTimeZone('UTC') )->getTimestamp();
        $e = $end_dt->setTimezone( new DateTimeZone('UTC') )->getTimestamp();
        if ( $e <= $s ) { $e = $s + HOUR_IN_SECONDS; }

        // Conflict check per display
        $winStart = $s - DAY_IN_SECONDS; $winEnd = $e + DAY_IN_SECONDS;
        foreach ( $display_ids as $did ) {
            $others = get_posts( array(
                'post_type'      => 'foyer_schedule',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    'relation' => 'OR',
                    array( 'key' => 'foyer_schedule_displays', 'value' => 'i:' . intval($did) . ';', 'compare' => 'LIKE' ),
                    array( 'key' => 'foyer_schedule_displays', 'value' => '"' . intval($did) . '"', 'compare' => 'LIKE' ),
                ),
            ) );
            foreach ( $others as $op ) {
                $m = Foyer_Schedules::read_meta( $op->ID );
                $o_occ = Foyer_Schedule_Engine::expand_occurrences( $m, $winStart, $winEnd );
                foreach ( $o_occ as $b ) {
                    $bs = intval( $b['start_utc'] ); $be = intval( $b['end_utc'] );
                    if ( $be > $s && $e > $bs ) {
                        $msg = sprintf( __( 'Display "%1$s" conflicts with schedule "%2$s".', 'foyer' ), get_the_title( $did ), get_the_title( $op->ID ) );
                        wp_send_json_error( array( 'message' => $msg ) );
                    }
                }
            }
        }

        // Create new foyer_schedule post
        $title = sprintf( __( 'Schedule: %1$s (%2$s)', 'foyer' ), get_the_title( $channel_id ), wp_date( 'Y-m-d H:i', $s, wp_timezone() ) );
        $pid = wp_insert_post( array( 'post_title' => $title, 'post_type' => 'foyer_schedule', 'post_status' => 'publish' ) );
        if ( is_wp_error( $pid ) || ! $pid ) {
            wp_send_json_error( array( 'message' => __( 'Could not create schedule.', 'foyer' ) ) );
        }
        update_post_meta( $pid, 'foyer_schedule_channel', $channel_id );
        update_post_meta( $pid, 'foyer_schedule_displays', $display_ids );
        update_post_meta( $pid, 'foyer_schedule_tz', $tzid );
        update_post_meta( $pid, 'foyer_schedule_start_utc', $s );
        update_post_meta( $pid, 'foyer_schedule_end_utc', $e );
        update_post_meta( $pid, 'foyer_schedule_mode', 'single' );

        wp_send_json_success( array( 'ok' => true, 'post_id' => intval( $pid ) ) );
    }

    public static function ajax_update_event() {
        check_ajax_referer( 'foyer_calendar_nonce', 'nonce', true );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed', 'foyer' ) ), 403 );
        }
        $pid = isset( $_POST['schedule_post_id'] ) ? intval( $_POST['schedule_post_id'] ) : 0;
        // display_id is optional now; updates apply to all displays of the schedule
        $did = isset( $_POST['display_id'] ) ? intval( $_POST['display_id'] ) : 0;
        $occ_id = isset( $_POST['occ_id'] ) ? (string) $_POST['occ_id'] : '';
        $new_start_local = isset( $_POST['new_start_local'] ) ? trim( (string) $_POST['new_start_local'] ) : '';
        $new_end_local   = isset( $_POST['new_end_local'] ) ? trim( (string) $_POST['new_end_local'] ) : '';
        $apply_to = isset( $_POST['apply_to'] ) ? (string) $_POST['apply_to'] : 'occurrence';
        $channel_id = isset( $_POST['channel_id'] ) ? intval( $_POST['channel_id'] ) : 0;
        // Optional: update displays for this schedule (series-level)
        $display_ids = isset( $_POST['display_ids'] ) ? array_values( array_unique( array_map( 'intval', (array) $_POST['display_ids'] ) ) ) : array();
        if ( ! empty( $display_ids ) ) {
            foreach ( $display_ids as $did_check ) {
                if ( ! current_user_can( 'edit_post', $did_check ) ) {
                    wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this display.', 'foyer' ) ), 403 );
                }
            }
        }

        if ( $pid <= 0 || '' === $occ_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid payload', 'foyer' ) ), 400 );
        }
        if ( ! current_user_can( 'edit_post', $pid ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this schedule.', 'foyer' ) ), 403 );
        }
        $meta = Foyer_Schedules::read_meta( $pid );
        $tzid = isset( $meta['tz'] ) && $meta['tz'] ? (string) $meta['tz'] : wp_timezone_string();
        try { $tz = new DateTimeZone( $tzid ); } catch ( Exception $e ) { $tz = wp_timezone(); $tzid = wp_timezone_string(); }

        // Parse local -> UTC
        $start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace('T',' ', $new_start_local ), $tz );
        if ( false === $start_dt ) { try { $start_dt = new DateTimeImmutable( $new_start_local, $tz ); } catch ( Exception $e ) { $start_dt = false; } }
        $end_dt   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace('T',' ', $new_end_local ), $tz );
        if ( false === $end_dt ) { try { $end_dt = new DateTimeImmutable( $new_end_local, $tz ); } catch ( Exception $e ) { $end_dt = false; } }
        if ( ! ( $start_dt instanceof DateTimeImmutable ) ) { wp_send_json_error( array( 'message' => __( 'Invalid start time.', 'foyer' ) ) ); }
        if ( ! ( $end_dt instanceof DateTimeImmutable ) ) { $end_dt = $start_dt->modify( '+1 hour' ); }
        $s = $start_dt->setTimezone( new DateTimeZone('UTC') )->getTimestamp();
        $e = $end_dt->setTimezone( new DateTimeZone('UTC') )->getTimestamp();
        if ( $e <= $s ) { $e = $s + HOUR_IN_SECONDS; }

        // Conflict check across all displays of this schedule (or provided set), excluding this schedule post
        $winStart = $s - DAY_IN_SECONDS; $winEnd = $e + DAY_IN_SECONDS;
        $schedule_displays = get_post_meta( $pid, 'foyer_schedule_displays', true );
        $affected_displays = is_array( $schedule_displays ) ? array_map( 'intval', $schedule_displays ) : array();
        $candidate_displays = ! empty( $display_ids ) ? $display_ids : $affected_displays;
        // Fallback to single provided display (backward compatibility) if empty
        if ( empty( $candidate_displays ) && $did > 0 ) { $candidate_displays = array( $did ); }

        foreach ( $candidate_displays as $aff_did ) {
            $others = get_posts( array(
                'post_type'      => 'foyer_schedule',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'post__not_in'   => array( $pid ),
                'meta_query'     => array(
                    'relation' => 'OR',
                    array( 'key' => 'foyer_schedule_displays', 'value' => 'i:' . intval($aff_did) . ';', 'compare' => 'LIKE' ),
                    array( 'key' => 'foyer_schedule_displays', 'value' => '"' . intval($aff_did) . '"', 'compare' => 'LIKE' ),
                ),
            ) );
            foreach ( $others as $op ) {
                $m = Foyer_Schedules::read_meta( $op->ID );
                $o_occ = Foyer_Schedule_Engine::expand_occurrences( $m, $winStart, $winEnd );
                foreach ( $o_occ as $b ) {
                    $bs = intval( $b['start_utc'] ); $be = intval( $b['end_utc'] );
                    if ( $be > $s && $e > $bs ) {
                        $msg = sprintf( __( 'Display "%1$s" conflicts with schedule "%2$s".', 'foyer' ), get_the_title( $aff_did ), get_the_title( $op->ID ) );
                        wp_send_json_error( array( 'message' => $msg ) );
                    }
                }
            }
        }

        $apply_to_lower = strtolower( $apply_to );

        // Determine single or recurring
        $is_single = ( ! empty( $meta['start_utc'] ) && ! empty( $meta['end_utc'] ) );
        $has_recur = ( ! empty( $meta['rrule'] ) && ! empty( $meta['dtstart_local'] ) );

        $rrule_freq = isset( $_POST['foyer_rrule_freq'] ) ? strtoupper( trim( (string) $_POST['foyer_rrule_freq'] ) ) : '';
        $series_rrule_update = ( $has_recur && 'series' === $apply_to_lower && $rrule_freq );

        if ( $series_rrule_update ) {
            $tzid = isset( $meta['tz'] ) && $meta['tz'] ? (string) $meta['tz'] : wp_timezone_string();
            try { $tz = new DateTimeZone( $tzid ); } catch ( Exception $e ) { $tz = wp_timezone(); $tzid = wp_timezone_string(); }

            $dtstart_local_in = isset( $_POST['dtstart_local'] ) ? trim( (string) $_POST['dtstart_local'] ) : '';
            if ( '' === $dtstart_local_in && ! empty( $meta['dtstart_local'] ) ) {
                $dtstart_local_in = (string) $meta['dtstart_local'];
            }
            if ( '' === $dtstart_local_in ) {
                wp_send_json_error( array( 'message' => __( 'Start time required.', 'foyer' ) ) );
            }
            $dtstart_local_obj = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace( 'T', ' ', $dtstart_local_in ), $tz );
            if ( false === $dtstart_local_obj ) {
                try { $dtstart_local_obj = new DateTimeImmutable( $dtstart_local_in, $tz ); } catch ( Exception $e ) { $dtstart_local_obj = false; }
            }
            if ( ! ( $dtstart_local_obj instanceof DateTimeImmutable ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid start time.', 'foyer' ) ) );
            }

            $duration_in = isset( $_POST['duration'] ) ? intval( $_POST['duration'] ) : 0;
            if ( $duration_in <= 0 && ! empty( $meta['duration'] ) ) {
                $duration_in = intval( $meta['duration'] );
            }
            if ( $duration_in <= 0 ) { $duration_in = HOUR_IN_SECONDS; }

            $rrule_new = Foyer_Schedules::build_rrule_from_builder_fields( $_POST );
            if ( '' === $rrule_new ) {
                wp_send_json_error( array( 'message' => __( 'Invalid recurrence rule.', 'foyer' ) ) );
            }

            update_post_meta( $pid, 'foyer_schedule_dtstart_local', $dtstart_local_obj->format( 'Y-m-d H:i:s' ) );
            update_post_meta( $pid, 'foyer_schedule_duration', $duration_in );
            update_post_meta( $pid, 'foyer_schedule_rrule', $rrule_new );
            update_post_meta( $pid, 'foyer_schedule_mode', 'recurring' );
            delete_post_meta( $pid, 'foyer_schedule_start_utc' );
            delete_post_meta( $pid, 'foyer_schedule_end_utc' );
            if ( $channel_id > 0 ) { update_post_meta( $pid, 'foyer_schedule_channel', $channel_id ); }

            if ( ! empty( $display_ids ) ) {
                update_post_meta( $pid, 'foyer_schedule_displays', $display_ids );
            }

            wp_send_json_success( array( 'ok' => true ) );
        }

        if ( $is_single || ! $has_recur ) {
            // Update single occurrence schedule
            update_post_meta( $pid, 'foyer_schedule_start_utc', $s );
            update_post_meta( $pid, 'foyer_schedule_end_utc', $e );
            if ( $channel_id > 0 ) { update_post_meta( $pid, 'foyer_schedule_channel', $channel_id ); }
        } else {
            // Recurrence: occurrence override and/or series-level channel change
            if ( 'series' === $apply_to_lower ) {
                if ( $channel_id > 0 ) { update_post_meta( $pid, 'foyer_schedule_channel', $channel_id ); }
            }
            $duration = max( 1, intval( $e - $s ) );
            $overrides = get_post_meta( $pid, 'foyer_schedule_overrides', true );
            if ( ! is_array( $overrides ) ) { $overrides = array(); }
            $ov = array( 'start_local' => $start_dt->format('Y-m-d H:i:s'), 'duration' => $duration );
            if ( $channel_id > 0 ) { $ov['channel'] = $channel_id; }
            $overrides[ $occ_id ] = $ov;
            update_post_meta( $pid, 'foyer_schedule_overrides', $overrides );
        }

        // If caller provided a new set of displays, persist them (series-level update)
        if ( ! empty( $display_ids ) ) {
            update_post_meta( $pid, 'foyer_schedule_displays', $display_ids );
        }

        wp_send_json_success( array( 'ok' => true ) );
    }

    public static function ajax_get_schedule() {
        check_ajax_referer( 'foyer_calendar_nonce', 'nonce', true );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed', 'foyer' ) ), 403 );
        }
        $pid = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( $pid <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid payload', 'foyer' ) ), 400 );
        }
        if ( ! current_user_can( 'edit_post', $pid ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this schedule.', 'foyer' ) ), 403 );
        }
        $meta = Foyer_Schedules::read_meta( $pid );
        wp_send_json_success( array( 'meta' => $meta ) );
    }

    public static function ajax_delete_event() {
        check_ajax_referer( 'foyer_calendar_nonce', 'nonce', true );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed', 'foyer' ) ), 403 );
        }
        $pid = isset( $_POST['schedule_post_id'] ) ? intval( $_POST['schedule_post_id'] ) : 0;
        $occ_id = isset( $_POST['occ_id'] ) ? (string) $_POST['occ_id'] : '';
        $delete_mode = isset( $_POST['delete_mode'] ) ? (string) $_POST['delete_mode'] : 'all';
        if ( $pid <= 0 ) { wp_send_json_error( array( 'message' => __( 'Invalid payload', 'foyer' ) ), 400 ); }
        if ( ! current_user_can( 'edit_post', $pid ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to delete this schedule.', 'foyer' ) ), 403 );
        }

        if ( 'all' === strtolower( $delete_mode ) ) {
            $del = wp_delete_post( $pid, true );
            if ( ! $del ) { wp_send_json_error( array( 'message' => __( 'Could not delete schedule.', 'foyer' ) ) ); }
            wp_send_json_success( array( 'ok' => true ) );
        }

        // occurrence delete (recurrence only): add EXDATE
        $meta = Foyer_Schedules::read_meta( $pid );
        $tzid = isset( $meta['tz'] ) && $meta['tz'] ? (string) $meta['tz'] : wp_timezone_string();
        try { $tz = new DateTimeZone( $tzid ); } catch ( Exception $e ) { $tz = wp_timezone(); $tzid = wp_timezone_string(); }
        if ( '' === $occ_id ) { wp_send_json_error( array( 'message' => __( 'Occurrence ID required.', 'foyer' ) ) ); }
        try {
            $occ_dt_utc = new DateTimeImmutable( $occ_id, new DateTimeZone('UTC') );
        } catch ( Exception $e ) { $occ_dt_utc = false; }
        if ( ! ( $occ_dt_utc instanceof DateTimeImmutable ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid occurrence ID.', 'foyer' ) ) );
        }
        $local = $occ_dt_utc->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
        $exdates = get_post_meta( $pid, 'foyer_schedule_exdates', true );
        if ( ! is_array( $exdates ) ) { $exdates = array(); }
        $exdates[] = $local;
        $exdates = array_values( array_unique( $exdates ) );
        update_post_meta( $pid, 'foyer_schedule_exdates', $exdates );
        // Remove matching override if present
        $overrides = get_post_meta( $pid, 'foyer_schedule_overrides', true );
        if ( is_array( $overrides ) && isset( $overrides[ $occ_id ] ) ) {
            unset( $overrides[ $occ_id ] );
            update_post_meta( $pid, 'foyer_schedule_overrides', $overrides );
        }
        wp_send_json_success( array( 'ok' => true ) );
    }

    /**
     * Handles POST from the Scheduler page to save a display's schedule.
     */
    public static function handle_post() {
        if ( ! isset( $_POST['display_id'] ) ) {
            wp_die( esc_html__( 'Invalid request.', 'foyer' ) );
        }

        $display_id = intval( $_POST['display_id'] );

        // Capability check
        if ( ! current_user_can( 'edit_post', $display_id ) ) {
            wp_die( esc_html__( 'You are not allowed to edit this display.', 'foyer' ) );
        }

        // Nonce check
        if ( ! isset( $_POST['foyer_scheduler_nonce'] ) || ! wp_verify_nonce( $_POST['foyer_scheduler_nonce'], 'foyer_scheduler_' . $display_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'foyer' ) );
        }

        // Read arrays (same names as used in Display meta box)
        $chs = isset( $_POST['foyer_channel_scheduler_list_channel'] ) ? (array) $_POST['foyer_channel_scheduler_list_channel'] : array();
        $sts = isset( $_POST['foyer_channel_scheduler_list_start'] ) ? (array) $_POST['foyer_channel_scheduler_list_start'] : array();
        $eds = isset( $_POST['foyer_channel_scheduler_list_end'] ) ? (array) $_POST['foyer_channel_scheduler_list_end'] : array();

        $cnt = max( count( $chs ), count( $sts ), count( $eds ) );

        // Use same defaults/format as Display admin
        $def = Foyer_Admin_Display::get_channel_scheduler_defaults();
        $fmt = isset( $def['picker_format'] ) ? $def['picker_format'] : 'Y-m-d H:i';
        $tz  = wp_timezone();

        // Build candidate schedules
        $new_schedules = array();
        for ( $i = 0; $i < $cnt; $i++ ) {
            $cid       = intval( $chs[ $i ] ?? 0 );
            $start_str = sanitize_text_field( $sts[ $i ] ?? '' );
            $end_str   = sanitize_text_field( $eds[ $i ] ?? '' );

            if ( empty( $cid ) || empty( $start_str ) || empty( $end_str ) ) { continue; }

            $start = Foyer_Admin_Display::parse_schedule_input( $start_str, $fmt, $tz );
            $end   = Foyer_Admin_Display::parse_schedule_input( $end_str, $fmt, $tz );
            if ( is_null( $start ) || is_null( $end ) ) { continue; }
            if ( $end <= $start ) { $end = $start + ( isset( $def['duration'] ) ? intval( $def['duration'] ) : 3600 ); }

            $new_schedules[] = array( 'channel' => $cid, 'start' => $start, 'end' => $end );
        }

        // Overlap validation
        if ( count( $new_schedules ) > 1 ) {
            usort( $new_schedules, function( $a, $b ) { return ( $a['start'] <=> $b['start'] ); } );
            for ( $i = 1; $i < count( $new_schedules ); $i++ ) {
                if ( intval( $new_schedules[$i-1]['end'] ) > intval( $new_schedules[$i]['start'] ) ) {
                    Foyer_Admin_Display::add_admin_notice( 'error', __( 'Schedule conflict: time windows overlap. Please adjust the planned channels so they do not overlap.', 'foyer' ) );
                    $url = add_query_arg( array( 'page' => 'foyer_scheduler' ), admin_url( 'admin.php' ) );
                    wp_safe_redirect( $url );
                    exit;
                }
            }
        }

        // Replace schedule
        delete_post_meta( $display_id, 'foyer_display_schedule' );
        foreach ( $new_schedules as $schedule ) {
            add_post_meta( $display_id, 'foyer_display_schedule', $schedule, false );
        }

        // Redirect back to scheduler page with notice
        $url = add_query_arg( array( 'page' => 'foyer_scheduler', 'foyer_scheduler_updated' => 1 ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Applies a built schedule template to multiple selected displays.
     */
    public static function handle_apply_template() {
        if ( ! isset( $_POST['foyer_apply_template_nonce'] ) || ! wp_verify_nonce( $_POST['foyer_apply_template_nonce'], 'foyer_apply_template' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'foyer' ) );
        }

        $display_ids = isset( $_POST['display_ids'] ) ? array_map( 'intval', (array) $_POST['display_ids'] ) : array();
        if ( empty( $display_ids ) ) {
            $url = add_query_arg( array( 'page' => 'foyer_scheduler', 'foyer_scheduler_error' => 'no_displays' ), admin_url( 'admin.php' ) );
            wp_safe_redirect( $url );
            exit;
        }

        $chs = isset( $_POST['foyer_channel_scheduler_list_channel'] ) ? (array) $_POST['foyer_channel_scheduler_list_channel'] : array();
        $sts = isset( $_POST['foyer_channel_scheduler_list_start'] ) ? (array) $_POST['foyer_channel_scheduler_list_start'] : array();
        $eds = isset( $_POST['foyer_channel_scheduler_list_end'] ) ? (array) $_POST['foyer_channel_scheduler_list_end'] : array();

        $def = Foyer_Admin_Display::get_channel_scheduler_defaults();
        $fmt = isset( $def['picker_format'] ) ? $def['picker_format'] : 'Y-m-d H:i';
        $tz  = wp_timezone();

        // Parse schedule rows once
        $entries = array();
        $cnt = max( count( $chs ), count( $sts ), count( $eds ) );
        for ( $i = 0; $i < $cnt; $i++ ) {
            $cid = intval( $chs[ $i ] ?? 0 );
            $start_str = sanitize_text_field( $sts[ $i ] ?? '' );
            $end_str   = sanitize_text_field( $eds[ $i ] ?? '' );
            if ( empty( $cid ) || empty( $start_str ) || empty( $end_str ) ) { continue; }
            $start = Foyer_Admin_Display::parse_schedule_input( $start_str, $fmt, $tz );
            $end   = Foyer_Admin_Display::parse_schedule_input( $end_str, $fmt, $tz );
            if ( is_null( $start ) || is_null( $end ) ) { continue; }
            if ( $end <= $start ) { $end = $start + ( isset( $def['duration'] ) ? intval( $def['duration'] ) : 3600 ); }
            $entries[] = array( 'channel' => $cid, 'start' => $start, 'end' => $end );
        }

        // Overlap validation on template entries
        if ( count( $entries ) > 1 ) {
            usort( $entries, function( $a, $b ) { return ( $a['start'] <=> $b['start'] ); } );
            for ( $i = 1; $i < count( $entries ); $i++ ) {
                if ( intval( $entries[$i-1]['end'] ) > intval( $entries[$i]['start'] ) ) {
                    Foyer_Admin_Display::add_admin_notice( 'error', __( 'Schedule conflict: time windows overlap. Please adjust the planned channels so they do not overlap.', 'foyer' ) );
                    $url = add_query_arg( array( 'page' => 'foyer_scheduler' ), admin_url( 'admin.php' ) );
                    wp_safe_redirect( $url );
                    exit;
                }
            }
        }

        if ( empty( $entries ) ) {
            Foyer_Admin_Display::add_admin_notice( 'warning', __( 'No valid schedule entries to apply.', 'foyer' ) );
            $url = add_query_arg( array( 'page' => 'foyer_scheduler' ), admin_url( 'admin.php' ) );
            wp_safe_redirect( $url );
            exit;
        }

        $applied = 0; $skipped = 0;
        foreach ( $display_ids as $did ) {
            if ( ! current_user_can( 'edit_post', $did ) ) { $skipped++; continue; }

            $existing_entries = get_post_meta( $did, 'foyer_display_schedule', false );
            if ( ! is_array( $existing_entries ) ) { $existing_entries = array(); }

            $normalized_existing = array();
            $existing_hash_map   = array();

            foreach ( $existing_entries as $entry ) {
                $normalized = self::normalize_schedule_entry( $entry );
                if ( is_null( $normalized ) ) { continue; }
                $normalized_existing[] = $normalized;
                $existing_hash_map[ self::hash_schedule_entry( $normalized ) ] = true;
            }

            $new_to_add   = array();
            $seen_hashes  = array();

            foreach ( $entries as $schedule ) {
                $normalized_new = self::normalize_schedule_entry( $schedule );
                if ( is_null( $normalized_new ) ) { continue; }

                $hash = self::hash_schedule_entry( $normalized_new );

                if ( isset( $existing_hash_map[ $hash ] ) || isset( $seen_hashes[ $hash ] ) ) {
                    continue;
                }

                $has_conflict = false;
                foreach ( $normalized_existing as $existing_entry ) {
                    if ( self::schedules_overlap( $existing_entry, $normalized_new ) ) {
                        $has_conflict = true;
                        break;
                    }
                }

                if ( $has_conflict ) {
                    $skipped++;
                    $title = get_the_title( $did );
                    if ( empty( $title ) ) {
                        $title = sprintf( __( 'Display #%d', 'foyer' ), $did );
                    }
                    Foyer_Admin_Display::add_admin_notice( 'error', sprintf( __( 'Template not applied to "%s": schedule conflicts with existing plan.', 'foyer' ), $title ) );
                    continue 2;
                }

                $new_to_add[] = $normalized_new;
                $seen_hashes[ $hash ] = true;
                $existing_hash_map[ $hash ] = true;
                $normalized_existing[] = $normalized_new;
            }

            foreach ( $new_to_add as $schedule ) {
                add_post_meta( $did, 'foyer_display_schedule', $schedule, false );
            }

            $applied++;
        }

        $url = add_query_arg( array( 'page' => 'foyer_scheduler', 'foyer_template_applied' => $applied, 'foyer_template_skipped' => $skipped ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Normalizes a schedule entry to keep comparisons consistent.
     *
     * @param array $entry
     * @return array|null
     */
    private static function normalize_schedule_entry( $entry ) {
        if ( ! is_array( $entry ) ) {
            return null;
        }

        return array(
            'channel' => isset( $entry['channel'] ) ? intval( $entry['channel'] ) : 0,
            'start'   => isset( $entry['start'] ) && '' !== $entry['start'] ? intval( $entry['start'] ) : null,
            'end'     => isset( $entry['end'] ) && '' !== $entry['end'] ? intval( $entry['end'] ) : null,
        );
    }

    /**
     * Generates a hash for a schedule entry to detect duplicates.
     *
     * @param array $entry
     * @return string
     */
    private static function hash_schedule_entry( $entry ) {
        $channel = isset( $entry['channel'] ) ? intval( $entry['channel'] ) : 0;
        $start   = ( isset( $entry['start'] ) && ! is_null( $entry['start'] ) ) ? intval( $entry['start'] ) : 0;
        $end     = ( isset( $entry['end'] ) && ! is_null( $entry['end'] ) ) ? intval( $entry['end'] ) : 0;

        return $channel . '|' . $start . '|' . $end;
    }

    /**
     * Determine whether two schedule entries overlap in time.
     *
     * @param array $first
     * @param array $second
     * @return bool
     */
    private static function schedules_overlap( $first, $second ) {
        if ( ! isset( $first['start'], $first['end'], $second['start'], $second['end'] ) ) {
            return false;
        }

        if ( is_null( $first['start'] ) || is_null( $first['end'] ) || is_null( $second['start'] ) || is_null( $second['end'] ) ) {
            return false;
        }

        $start_a = intval( $first['start'] );
        $end_a   = intval( $first['end'] );
        $start_b = intval( $second['start'] );
        $end_b   = intval( $second['end'] );

        if ( $end_a <= $start_b ) {
            return false;
        }

        if ( $end_b <= $start_a ) {
            return false;
        }

        return true;
    }

    /**
     * Renders the Scheduler page.
     */
    public static function render_page() {
        // New Calendar-based Scheduler UI
        $nonce = self::get_calendar_nonce();
        $site_tz = wp_timezone_string();
        $displays = Foyer_Displays::get_posts( array( 'orderby' => 'title', 'order' => 'ASC' ) );
        $displays_data = array();
        if ( ! empty( $displays ) ) {
            foreach ( $displays as $d ) {
                $displays_data[] = array( 'id' => intval( $d->ID ), 'title' => get_the_title( $d->ID ) );
            }
        }
        $channels = Foyer_Channels::get_posts();
        $channels_data = array();
        if ( ! empty( $channels ) ) {
            foreach ( $channels as $ch ) {
                $channels_data[] = array( 'id' => intval( $ch->ID ), 'title' => get_the_title( $ch->ID ) );
            }
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Scheduler', 'foyer' ) . '</h1>';
        // Basic layout: left calendar, right display selector
        echo '<div id="foyer-cal-layout" style="display:flex; gap:16px; align-items:flex-start;">';
        echo '<div id="foyer-cal-main" style="flex:1; min-height:640px;">';
        echo '<div id="foyerSchedulesCalendar" style="min-height:640px; border:1px solid #ccd0d4; background:#fff;"></div>';
        echo '<div id="foyerCalDebug" style="margin-top:8px; font-size:12px; color:#666;"></div>';
                echo '</div>';
        echo '<div id="foyer-cal-sidebar" class="postbox" style="width:320px;">';
        echo '<h2 class="hndle" style="padding:8px 12px; margin:0;">' . esc_html__( 'Display selector', 'foyer' ) . '</h2>';
        echo '<div class="inside" style="padding:8px 12px;">';
        echo '<div id="foyerCalSelectAllRow" class="foyer-display-item foyer-select-all" role="button" tabindex="0" data-color="hsl(210, 20%, 72%)">'
    . '<input type="checkbox" id="foyerCalSelectAll" />'
    . '<span class="foyer-display-swatch foyer-swatch-all"></span>'
    . '<span class="foyer-display-title">' . esc_html__( 'Select all displays', 'foyer' ) . '</span>'
    . '</div>';
        if ( empty( $displays_data ) ) {
            echo '<em>' . esc_html__( 'No displays found.', 'foyer' ) . '</em>';
        } else {
            echo '<div id="foyerCalDisplays" class="foyer-display-list" style="max-height:420px; overflow:auto; background:#fff;">';
            foreach ( $displays_data as $row ) {
                $color = self::color_for_display( $row['id'] );
                echo '<div class="foyer-display-item" data-id="' . intval( $row['id'] ) . '" data-color="' . esc_attr( $color ) . '" role="button" tabindex="0">'
                    . '<input type="checkbox" class="foyerCalDisplay" value="' . intval( $row['id'] ) . '" />'
                    . '<span class="foyer-display-swatch" style="background:' . esc_attr( $color ) . ';"></span>'
                    . '<span class="foyer-display-title">' . esc_html( $row['title'] ) . '</span>'
                    . '</div>';
            }
            echo '</div>';
        }
        echo '</div>'; // inside
        echo '</div>'; // sidebar
        echo '</div>'; // layout

        // Load EventCalendar (CDN) and bootstrap minimal fetch wiring; full CRUD follows in next step
        // We keep times strictly in Site-TZ by shifting render times
        ?>
            
        <?php
        echo '</div>';
        return;

        // Legacy UI (not reached): Ensure defaults are localized for datetimepicker
        Foyer_Admin_Display::localize_scripts();

        $displays = Foyer_Displays::get_posts( array( 'orderby' => 'title', 'order' => 'ASC' ) );
        $channels = Foyer_Channels::get_posts();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Scheduler', 'foyer' ) . '</h1>';
        // Ensure display blocks use full width and are collapsible
        echo '<style>
            .foyer-sched-card{padding:0;margin-top:16px;background:#fff;border:1px solid #ccd0d4;box-shadow:0 1px 1px rgba(0,0,0,.04);width:100%;box-sizing:border-box;display:block;clear:both;margin-right:0;}
            .foyer-sched-head{display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;user-select:none; position:relative; overflow:hidden;}
            .foyer-sched-head:hover{background:#f6f7f7;}
            .foyer-sched-title{margin:0;font-size:1.1em; display:flex; align-items:center; gap:8px;}
            .foyer-sched-meta{margin-left:auto;color:#555; display:flex; align-items:center; gap:12px;}
            .foyer-sched-panel{padding:16px;border-top:1px solid #e2e4e7;}
            .foyer-sched-toggle{background:none;border:0;padding:0;margin:0; position:static; display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px;}
            .foyer-sched-toggle .dashicons{font-size:20px; width:20px; height:20px; line-height:20px; transition:transform .15s ease; color:#1d2327;}
            .foyer-sched-card.is-open .foyer-sched-toggle .dashicons{transform:rotate(90deg);} /* ▶ to ▼ */
        </style>';
        // Global toggler script
        echo '<script>(function($){$(function(){
            $(document).on("click", ".foyer-sched-head", function(e){
                // Ignore clicks on interactive elements (except the toggle button)
                if (($(e.target).closest("a, button, input, label").length) && !$(e.target).closest(".foyer-sched-toggle").length) { return; }
                var $head=$(this); var $card=$head.closest(".foyer-sched-card");
                var targetId=$head.find(".foyer-sched-toggle").attr("aria-controls");
                var $panel=$("#"+targetId);
                var expanded=$head.find(".foyer-sched-toggle").attr("aria-expanded")==="true";
                $head.find(".foyer-sched-toggle").attr("aria-expanded", expanded?"false":"true");
                if(expanded){ $panel.attr("hidden", true); $card.removeClass("is-open"); }
                else { $panel.removeAttr("hidden"); $card.addClass("is-open"); }
            });
        });})(jQuery);</script>';

        if ( isset( $_GET['foyer_scheduler_updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule saved.', 'foyer' ) . '</p></div>';
        }
        if ( isset( $_GET['foyer_template_applied'] ) ) {
            $applied = intval( $_GET['foyer_template_applied'] );
            $skipped = isset( $_GET['foyer_template_skipped'] ) ? intval( $_GET['foyer_template_skipped'] ) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Template applied to %d displays. Skipped: %d', 'foyer' ), $applied, $skipped ) . '</p></div>';
        }
        if ( isset( $_GET['foyer_scheduler_error'] ) && $_GET['foyer_scheduler_error'] === 'no_displays' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No displays selected.', 'foyer' ) . '</p></div>';
        }

        // Global schedule template builder
        echo '<div class="foyer-sched-card">';
        echo '<div class="foyer-sched-head" role="heading">';
        echo '<div class="foyer-sched-head-title"><h2 class="foyer-sched-title">' . esc_html__( 'Build schedule', 'foyer' ) . '</h2></div>';
        echo '<div class="foyer-sched-meta"><span>' . esc_html__( 'Create a schedule and apply it to selected displays below.', 'foyer' ) . '</span></div>';
        echo '</div>';
        echo '<div class="foyer-sched-panel">';
        echo '<form id="foyer_template_form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'foyer_apply_template', 'foyer_apply_template_nonce' );
        echo '<input type="hidden" name="action" value="foyer_apply_scheduler_template" />';

        // Add channels selector (favorites first), search, pagination (scoped IDs for template)
        // Order channels: selected favorites first like in display editor
        $channels_ordered = array(); $favorites = array(); $others = array();
        foreach ( $channels as $ch ) { $is_fav = get_post_meta( $ch->ID, 'foyer_channel_is_favorite', true ); if ( $is_fav ) { $favorites[] = $ch; } else { $others[] = $ch; } }
        foreach ( $favorites as $ch ) { $channels_ordered[] = $ch; }
        foreach ( $others as $ch ) { $channels_ordered[] = $ch; }

        echo '<h3>' . esc_html__( 'Add channels', 'foyer' ) . '</h3>';
        echo '<div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:4px 0 8px;">';
        echo '<label for="foyer_template_selector_search" style="margin-right:6px;">' . esc_html__( 'Search', 'foyer' ) . '</label>';
        echo '<input type="search" id="foyer_template_selector_search" class="regular-text" placeholder="' . esc_attr__( 'Search by title or author…', 'foyer' ) . '" style="max-width:280px;" />';
        echo '<label for="foyer_template_selector_per_page" style="margin-left:auto;">' . esc_html__( 'Rows per page', 'foyer' ) . '</label>';
        echo '<select id="foyer_template_selector_per_page"><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select>';
        echo '</div>';
        echo '<table class="widefat fixed striped" id="foyer_template_selector">';
        echo '<thead><tr>';
        echo '<th style="width:110px;">&nbsp;</th>';
        echo '<th style="width:30px; text-align:center;" title="' . esc_attr__( 'Favorite', 'foyer' ) . '">★</th>';
        echo '<th data-sort="title" class="foyer-sort-col"><span class="sort-label">' . esc_html_x( 'Title', 'post title', 'foyer' ) . '</span> <span class="sort-ind"></span></th>';
        echo '<th data-sort="author" class="foyer-sort-col" style="width:160px;"><span class="sort-label">' . esc_html__( 'Author', 'foyer' ) . '</span> <span class="sort-ind"></span></th>';
        echo '<th data-sort="date" class="foyer-sort-col" style="width:180px;"><span class="sort-label">' . esc_html__( 'Date', 'foyer' ) . '</span> <span class="sort-ind"></span></th>';
        echo '<th data-sort="slides" class="foyer-sort-col" style="width:120px;"><span class="sort-label">' . esc_html__( 'Slides', 'foyer' ) . '</span> <span class="sort-ind"></span></th>';
        echo '</tr></thead><tbody>';
        if ( empty( $channels_ordered ) ) {
            echo '<tr><td colspan="6">' . esc_html__( 'No channels found.', 'foyer' ) . '</td></tr>';
        } else {
            foreach ( $channels_ordered as $channel_post ) {
                $author_name = get_the_author_meta( 'display_name', $channel_post->post_author );
                $date_ts = get_post_time( 'U', true, $channel_post );
                $channel_obj = new Foyer_Channel( $channel_post );
                $slides_count = count( $channel_obj->get_slides() );
                $is_fav = get_post_meta( $channel_post->ID, 'foyer_channel_is_favorite', true ) ? 1 : 0;
                echo '<tr data-title="' . esc_attr( get_the_title( $channel_post->ID ) ) . '" data-author="' . esc_attr( $author_name ) . '" data-date="' . esc_attr( $date_ts ) . '" data-slides="' . esc_attr( $slides_count ) . '" data-fav="' . esc_attr( $is_fav ) . '">';
                echo '<td><button type="button" class="button add-to-template" data-id="' . intval( $channel_post->ID ) . '" data-title="' . esc_attr( get_the_title( $channel_post->ID ) ) . '">' . esc_html__( 'Add', 'foyer' ) . '</button></td>';
                echo '<td style="text-align:center;">' . ( $is_fav ? '★' : '&nbsp;' ) . '</td>';
                echo '<td>' . esc_html( get_the_title( $channel_post->ID ) ) . '</td>';
                echo '<td>' . esc_html( $author_name ) . '</td>';
                echo '<td>' . esc_html( get_the_date( get_option( 'date_format' ), $channel_post ) . ' ' . get_the_time( get_option( 'time_format' ), $channel_post ) ) . '</td>';
                echo '<td>' . esc_html( $slides_count ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '<div style="display:flex;gap:8px;align-items:center;margin:8px 0 12px;">';
        echo '<button type="button" class="button" id="foyer_template_selector_prev">&laquo; ' . esc_html__( 'Prev', 'foyer' ) . '</button>';
        echo '<span id="foyer_template_selector_page_info"></span>';
        echo '<button type="button" class="button" id="foyer_template_selector_next">' . esc_html__( 'Next', 'foyer' ) . ' &raquo;</button>';
        echo '</div>';

        // Scheduled list
        echo '<h3>' . esc_html__( 'Scheduled channels', 'foyer' ) . '</h3>';
        echo '<table class="widefat fixed striped" id="foyer_template_list">';
        echo '<thead><tr>';
        echo '<th style="width:30%">' . esc_html__( 'Channel', 'foyer' ) . '</th>';
        echo '<th style="width:35%">' . esc_html__( 'Show from', 'foyer' ) . '</th>';
        echo '<th style="width:35%">' . esc_html__( 'Until', 'foyer' ) . '</th>';
        echo '<th style="width:180px">&nbsp;</th>';
        echo '</tr></thead><tbody>';
        echo '<tr class="foyer-sched-empty"><td colspan="4">' . esc_html__( 'No scheduled channels.', 'foyer' ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<div style="display:flex;align-items:center;gap:12px;margin-top:12px;">';
        echo '<label><input type="checkbox" id="foyer_select_all_displays" /> ' . esc_html__( 'Select all displays', 'foyer' ) . '</label>';
        echo '<button type="submit" class="button button-primary" id="foyer_apply_template_btn">' . esc_html__( 'Apply to selected displays', 'foyer' ) . '</button>';
        echo '</div>';

        // JS for template selector, list, and apply
        ?>
        <script>
        (function($){
            $(function(){
                var validationError = <?php echo json_encode( __( 'Validation failed', 'foyer' ) ); ?>;
                var missingValueError = <?php echo json_encode( __( 'Please enter both start and end times.', 'foyer' ) ); ?>;
                var statusClasses = ['foyer-sched-active', 'foyer-sched-future', 'foyer-sched-past'];

                function cleanDisplayValue(str){
                    if (!str) { return ''; }
                    var trimmed = $.trim(String(str));
                    if (!trimmed || trimmed === '—') { return ''; }
                    return trimmed;
                }

                function ensureDateHelpers(){
                    if (window.foyerSchedulerDateHelpers) { return window.foyerSchedulerDateHelpers; }
                    if (!window.foyer_channel_scheduler_defaults) { return null; }

                    var defaults = window.foyer_channel_scheduler_defaults;
                    var localeKey = defaults.locale || 'en';
                    if ($.foyer_datetimepicker && $.foyer_datetimepicker.setLocale) {
                        $.foyer_datetimepicker.setLocale(localeKey);
                    }

                    var pickerFormat = defaults.picker_format || defaults.datetime_format || 'Y-m-d H:i';
                    var formatterOptions = {};
                    var baseDefaults = ($.fn.foyer_datetimepicker && $.fn.foyer_datetimepicker.defaults) ? $.fn.foyer_datetimepicker.defaults : null;
                    var localeData = (baseDefaults && baseDefaults.i18n) ? baseDefaults.i18n[ localeKey ] : null;
                    if (localeData) {
                        formatterOptions.dateSettings = {
                            days: localeData.dayOfWeek || [],
                            daysShort: localeData.dayOfWeekShort || [],
                            months: localeData.months || [],
                            monthsShort: $.map(localeData.months || [], function(name){ return name ? name.substring(0,3) : ''; })
                        };
                    }

                    var formatter = (typeof DateFormatter !== 'undefined') ? new DateFormatter(formatterOptions) : null;

                    var parseIsoDate = function(value){
                        if (!value) { return null; }
                        var time = Date.parse(value);
                        return isNaN(time) ? null : new Date(time);
                    };

                    var parseExisting = function(value){
                        var trimmed = cleanDisplayValue(value);
                        if (!trimmed) { return null; }
                        if (formatter) {
                            try {
                                return formatter.parseDate(trimmed, pickerFormat);
                            } catch (err) {}
                        }
                        return parseIsoDate(trimmed);
                    };

                    window.foyerSchedulerDateHelpers = {
                        pickerFormat: pickerFormat,
                        parseExisting: parseExisting
                    };

                    return window.foyerSchedulerDateHelpers;
                }

                function setDisplay($span, value){
                    var placeholder = $span.data('placeholder') || '—';
                    var display = cleanDisplayValue(value);
                    $span.text(display ? display : placeholder);
                    $span.attr('data-display', display);
                }

                function updateStatusClass($row, status){
                    $row.removeClass(statusClasses.join(' '));
                    if (status) { $row.addClass(status); }
                }

                function initPickers($scope){
                    if (!window.foyer_channel_scheduler_defaults) { return; }
                    var helpers = ensureDateHelpers();
                    if (!helpers) { return; }
                    var pickerFormat = helpers.pickerFormat;
                    $scope.find('input.foyer-datetime').each(function(){
                        var $i=$(this); if ($i.data('dtp-init')) return;
                        var options = {
                            format: pickerFormat,
                            dayOfWeekStart: foyer_channel_scheduler_defaults.start_of_week,
                            step: 15,
                            validateOnBlur: false
                        };
                        if (helpers.parseExisting) {
                            options.parseInputDate = helpers.parseExisting;
                        }
                        $i.foyer_datetimepicker(options);
                        $i.data('dtp-init', true);
                    });
                }
                var $tmplForm = $('#foyer_template_form');
                var $selTable = $('#foyer_template_selector');
                var $rows = $selTable.find('tbody > tr');
                var $search = $('#foyer_template_selector_search');
                var $perPage = $('#foyer_template_selector_per_page');
                var $prev = $('#foyer_template_selector_prev');
                var $next = $('#foyer_template_selector_next');
                var $info = $('#foyer_template_selector_page_info');
                var sortKey = 'title'; var sortDir = 'asc'; var currentPage = 1;
                function applyFilters(){
                    var q = ($search.val()||'').toLowerCase();
                    $rows.each(function(){
                        var $tr=$(this);
                        var title=(String($tr.data('title')||'')).toLowerCase();
                        var author=(String($tr.data('author')||'')).toLowerCase();
                        var visible = (!q || title.indexOf(q)!==-1 || author.indexOf(q)!==-1);
                        $tr.toggle(visible);
                    });
                }
                function compareRows(a,b){
                    var $a=$(a),$b=$(b),dir=(sortDir==='asc')?1:-1;
                    var aFav = parseInt($a.data('fav'),10)||0; var bFav = parseInt($b.data('fav'),10)||0;
                    if (aFav !== bFav) return aFav ? -1 : 1;
                    if (sortKey==='date' || sortKey==='slides'){
                        var va=parseInt($a.data(sortKey),10)||0; var vb=parseInt($b.data(sortKey),10)||0;
                        if(va===vb) return 0; return (va<vb?-1:1)*dir;
                    } else {
                        var sa=String($a.data(sortKey)||'').toLowerCase(); var sb=String($b.data(sortKey)||'').toLowerCase();
                        if(sa===sb) return 0; return (sa<sb?-1:1)*dir;
                    }
                }
                function updateSortIndicators(){ var arrows={asc:'\u25B2',desc:'\u25BC'}; $selTable.find('thead th.foyer-sort-col .sort-ind').text(''); $selTable.find('thead th.foyer-sort-col[data-sort="'+sortKey+'"] .sort-ind').text(arrows[sortDir]||''); }
                function sortRows(){ var $tbody=$selTable.find('tbody'); var vis=$rows.filter(':visible').get(); vis.sort(compareRows); $tbody.append(vis); $tbody.append($rows.filter(':hidden')); updateSortIndicators(); }
                function paginate(){ var per=parseInt($perPage.val(),10)||10; $rows.show(); applyFilters(); var vis=$rows.filter(':visible'); var total=vis.length; var totalPages=Math.max(1, Math.ceil(total/per)); if(currentPage>totalPages) currentPage=totalPages; var start=(currentPage-1)*per; var end=start+per; vis.hide().slice(start,end).show(); $info.text(currentPage+' / '+totalPages); $prev.prop('disabled', currentPage<=1); $next.prop('disabled', currentPage>=totalPages); }
                function refresh(){ $rows.show(); applyFilters(); sortRows(); paginate(); }
                $search.on('input', function(){ currentPage=1; refresh(); });
                $perPage.on('change', function(){ currentPage=1; refresh(); });
                $selTable.find('thead').on('click','th.foyer-sort-col', function(){ var key=$(this).data('sort'); if(!key) return; if(key===sortKey){ sortDir=(sortDir==='asc')?'desc':'asc'; } else { sortKey=key; sortDir='asc'; } currentPage=1; refresh(); });
                $prev.on('click', function(){ currentPage=Math.max(1,currentPage-1); paginate(); });
                $next.on('click', function(){ currentPage=currentPage+1; paginate(); });
                refresh();

                // Add to template list
                function ensureListNotEmpty(){ var $tb=$('#foyer_template_list tbody'); if($tb.find('tr').length===0){ $tb.append('<tr class="foyer-sched-empty"><td colspan="4">'+<?php echo json_encode( esc_html__( 'No scheduled channels.', 'foyer' ) ); ?>+'</td></tr>'); } }
                function addRowToTemplate(id,title){
                    var $tb=$('#foyer_template_list tbody');
                    $tb.find('tr.foyer-sched-empty').remove();
                    var rowHtml=''
                        +'<tr>'
                        +'<td><input type="hidden" name="foyer_channel_scheduler_list_channel[]" value="'+id+'" />'
                        +'<span class="foyer-sched-channel-title"></span></td>'
                        +'<td><span class="foyer-sched-start-text" data-placeholder="&mdash;" data-display="">&mdash;</span>'
                        +'<input type="hidden" class="foyer-sched-start-hidden" name="foyer_channel_scheduler_list_start[]" value="" />'
                        +'<input type="text" class="foyer-datetime foyer-sched-start-input" value="" style="display:none;" /></td>'
                        +'<td><span class="foyer-sched-end-text" data-placeholder="&mdash;" data-display="">&mdash;</span>'
                        +'<input type="hidden" class="foyer-sched-end-hidden" name="foyer_channel_scheduler_list_end[]" value="" />'
                        +'<input type="text" class="foyer-datetime foyer-sched-end-input" value="" style="display:none;" /></td>'
                        +'<td>'
                        +'<button type="button" class="button button-primary foyer-sched-save" style="display:none;">'+<?php echo json_encode( __( 'Save', 'foyer' ) ); ?>+'</button> '
                        +'<button type="button" class="button foyer-sched-edit">'+<?php echo json_encode( __( 'Edit', 'foyer' ) ); ?>+'</button> '
                        +'<button type="button" class="button foyer-sched-remove" title="'+<?php echo json_encode( __( 'Remove', 'foyer' ) ); ?>+'">&times;</button>'
                        +'</td>'
                        +'</tr>';
                    var $row=$(rowHtml);
                    $row.find('.foyer-sched-channel-title').text(title);
                    $tb.append($row);
                    initPickers($row);
                }
                $selTable.on('click', '.add-to-template', function(){ var id=$(this).data('id'); var title=$(this).data('title'); addRowToTemplate(id,title); });

                // Edit/save/remove within template list (scoped)
                $('#foyer_template_form').on('click', '.foyer-sched-edit', function(){
                    var $row=$(this).closest('tr');
                    var startDisplay = $row.find('.foyer-sched-start-text').attr('data-display') || '';
                    var endDisplay   = $row.find('.foyer-sched-end-text').attr('data-display') || '';
                    $row.find('.foyer-sched-start-input').val(startDisplay);
                    $row.find('.foyer-sched-end-input').val(endDisplay);
                    $row.find('.foyer-sched-start-text, .foyer-sched-end-text').hide();
                    $row.find('.foyer-sched-start-input, .foyer-sched-end-input').show();
                    $(this).hide();
                    $row.find('.foyer-sched-save').show();
                    initPickers($row);
                });
                $('#foyer_template_form').on('click', '.foyer-sched-save', function(){
                    var $row=$(this).closest('tr');
                    var startVal=$row.find('.foyer-sched-start-input').val();
                    var endVal=$row.find('.foyer-sched-end-input').val();
                    if (!cleanDisplayValue(startVal) || !cleanDisplayValue(endVal)) {
                        alert(missingValueError);
                        return;
                    }
                    var entries=[];
                    var rowRefs=[];
                    $('#foyer_template_list tbody tr').each(function(){
                        var $r=$(this);
                        var channelId=$r.find('input[name=\'foyer_channel_scheduler_list_channel[]\']').val();
                        var startDisplay = ($r.is($row)) ? startVal : ($r.find('.foyer-sched-start-text').attr('data-display') || $r.find('.foyer-sched-start-text').text());
                        var endDisplay   = ($r.is($row)) ? endVal   : ($r.find('.foyer-sched-end-text').attr('data-display') || $r.find('.foyer-sched-end-text').text());
                        var sClean = cleanDisplayValue(startDisplay);
                        var eClean = cleanDisplayValue(endDisplay);
                        if (channelId && sClean && eClean) {
                            entries.push({channel:channelId, start:sClean, end:eClean});
                            rowRefs.push($r);
                        }
                    });
                    if (!entries.length) {
                        alert(missingValueError);
                        return;
                    }

                    $.post(ajaxurl, { action:'foyer_validate_schedule', nonce:(window.foyer_display_ajax?foyer_display_ajax.nonce:''), payload: JSON.stringify({ entries: entries }) })
                        .done(function(resp){
                            if(resp && resp.success){
                                var normalized = (resp.data && resp.data.normalized) ? resp.data.normalized : [];
                                $.each(normalized, function(idx, item){
                                    var $target = rowRefs[idx];
                                    if (!$target || !item) { return; }
                                    var startDisplay = item.start_display || '';
                                    var endDisplay   = item.end_display || '';
                                    setDisplay($target.find('.foyer-sched-start-text'), startDisplay);
                                    setDisplay($target.find('.foyer-sched-end-text'), endDisplay);
                                    $target.find('.foyer-sched-start-hidden').val(item.start_iso || '');
                                    $target.find('.foyer-sched-end-hidden').val(item.end_iso || '');
                                    $target.find('.foyer-sched-start-input').val(startDisplay);
                                    $target.find('.foyer-sched-end-input').val(endDisplay);
                                    updateStatusClass($target, item.status_class || '');
                                });
                                $row.find('.foyer-sched-start-input, .foyer-sched-end-input').hide();
                                $row.find('.foyer-sched-start-text, .foyer-sched-end-text').show();
                                $row.find('.foyer-sched-save').hide();
                                $row.find('.foyer-sched-edit').show();
                            } else {
                                var msg=(resp && resp.data && resp.data.message)?resp.data.message:validationError;
                                alert(msg);
                            }
                        }).fail(function(){ alert(validationError); });
                });
                $('#foyer_template_form').on('click', '.foyer-sched-remove', function(){ var $tb=$('#foyer_template_list tbody'); $(this).closest('tr').remove(); ensureListNotEmpty(); });

                // Select all displays
                $('#foyer_select_all_displays').on('change', function(){ var checked=this.checked; $('.foyer-apply-target').prop('checked', checked); });

                // On submit, collect selected displays into hidden inputs
                var saveBeforeApplyMsg = <?php echo json_encode( __( 'Please save all schedule rows before applying the template.', 'foyer' ) ); ?>;

                $('#foyer_apply_template_btn').on('click', function(e){
                    var pending = false;
                    $('#foyer_template_list tbody tr').each(function(){
                        if ($(this).find('.foyer-sched-save').is(':visible')) { pending = true; return false; }
                    });
                    if (pending) {
                        e.preventDefault();
                        alert(saveBeforeApplyMsg);
                        return false;
                    }

                    $('#foyer_template_form input[name="display_ids[]"]').remove();
                    $('.foyer-apply-target:checked').each(function(){ var id=$(this).val(); $('<input>').attr({type:'hidden', name:'display_ids[]', value:String(id)}).appendTo('#foyer_template_form'); });
                });

                initPickers($('#foyer_template_form'));
            });
        })(jQuery);
        </script>
        <?php
        echo '</form>';
        echo '</div>'; // panel
        echo '</div>'; // card

        if ( empty( $displays ) ) {
            echo '<p>' . esc_html__( 'No displays found.', 'foyer' ) . '</p>';
            echo '</div>';
            return;
        }

        foreach ( $displays as $display_post ) {
            $display = new Foyer_Display( $display_post );
            $schedules = $display->get_schedule();
            if ( empty( $schedules ) || ! is_array( $schedules ) ) { $schedules = array(); }

            // Sort by start ascending for display
            usort( $schedules, function( $a, $b ) {
                $sa = isset( $a['start'] ) && is_numeric( $a['start'] ) ? intval( $a['start'] ) : PHP_INT_MAX;
                $sb = isset( $b['start'] ) && is_numeric( $b['start'] ) ? intval( $b['start'] ) : PHP_INT_MAX;
                if ( $sa === $sb ) { return 0; }
                return ( $sa < $sb ) ? -1 : 1;
            } );

            $default_channel_id = $display->get_default_channel();
            $active_channel_id  = $display->get_active_channel();

            $panel_id = 'foyer-sched-panel-' . intval( $display_post->ID );
            echo '<div class="foyer-sched-card">';
            echo '<div class="foyer-sched-head" role="button" tabindex="0">';
            echo '<label style="margin-right:10px;display:flex;align-items:center;gap:6px;cursor:pointer;">';
            echo '<input type="checkbox" class="foyer-apply-target" value="' . intval( $display_post->ID ) . '" />';
            echo '</label>';
            echo '<div class="foyer-sched-head-title">';
            echo '<h2 class="foyer-sched-title">';
            echo '<button type="button" class="foyer-sched-toggle" aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>';
            echo '<span class="text">' . esc_html( get_the_title( $display_post ) ) . '</span>';
            echo '</h2>';
            echo '</div>';
            echo '<div class="foyer-sched-meta">';
            echo '<div class="foyer-sched-meta-info">';
            echo '<span class="foyer-sched-meta-default">' . esc_html__( 'Default:', 'foyer' ) . ' ' . ( $default_channel_id ? esc_html( get_the_title( $default_channel_id ) ) : esc_html__( 'None', 'foyer' ) ) . '</span>';
            echo '<span class="foyer-sched-meta-active" style="margin-left:12px;">' . esc_html__( 'Active:', 'foyer' ) . ' ' . ( $active_channel_id ? esc_html( get_the_title( $active_channel_id ) ) : esc_html__( 'None', 'foyer' ) ) . '</span>';
            echo '</div>';
            echo '<div class="foyer-sched-meta-actions" style="margin-left:auto;">';
            echo '<a href="' . esc_url( get_edit_post_link( $display_post->ID ) ) . '" class="button">' . esc_html__( 'Edit display', 'foyer' ) . '</a>';
            echo '</div>';
            echo '</div>';
            echo '</div>';

            echo '<div id="' . esc_attr( $panel_id ) . '" class="foyer-sched-panel" hidden>'; // collapsed by default
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="foyer-scheduler-form">';
            wp_nonce_field( 'foyer_scheduler_' . $display_post->ID, 'foyer_scheduler_nonce' );
            echo '<input type="hidden" name="action" value="foyer_save_scheduler" />';
            echo '<input type="hidden" name="display_id" value="' . intval( $display_post->ID ) . '" />';

            // Table styles similar to display meta box
            echo '<style>
                .foyer-scheduler-form .foyer-sched-table td:last-child, .foyer-scheduler-form .foyer-sched-table th:last-child { text-align:right; white-space:nowrap; }
                /* Apply color to TDs to override striped table backgrounds */
                .foyer-sched-table tbody tr.foyer-sched-active td { background-color:#e9f7ef !important; }
                .foyer-sched-table tbody tr.foyer-sched-future td { background-color:#e8f1fd !important; }
                .foyer-sched-table tbody tr.foyer-sched-past td { background-color:#fdecea !important; }
            </style>';

            echo '<table class="widefat fixed striped foyer-sched-table" id="foyer_sched_list">';
            echo '<thead><tr>';
            echo '<th style="width:30%">' . esc_html__( 'Channel', 'foyer' ) . '</th>';
            echo '<th style="width:35%">' . esc_html__( 'Show from', 'foyer' ) . '</th>';
            echo '<th style="width:35%">' . esc_html__( 'Until', 'foyer' ) . '</th>';
            echo '<th style="width:180px">&nbsp;</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            if ( empty( $schedules ) ) {
                echo '<tr class="foyer-sched-empty"><td colspan="4">' . esc_html__( 'No scheduled channels.', 'foyer' ) . '</td></tr>';
            } else {
                $fmt = Foyer_Admin_Display::get_channel_scheduler_defaults()['picker_format'];
                foreach ( $schedules as $sch ) {
                    $cid = ! empty( $sch['channel'] ) ? intval( $sch['channel'] ) : 0;
                    $title = $cid ? get_the_title( $cid ) : '';
                    $start_utc = isset( $sch['start'] ) ? intval( $sch['start'] ) : null;
                    $end_utc   = isset( $sch['end'] ) ? intval( $sch['end'] ) : null;
                    $start_val = $start_utc ? Foyer_Admin_Display::format_schedule_display( $start_utc, $fmt ) : '';
                    $end_val   = $end_utc ? Foyer_Admin_Display::format_schedule_display( $end_utc, $fmt ) : '';
                    $start_iso = $start_utc ? Foyer_Admin_Display::format_schedule_iso( $start_utc ) : '';
                    $end_iso   = $end_utc ? Foyer_Admin_Display::format_schedule_iso( $end_utc ) : '';
                    $status_class = Foyer_Admin_Display::determine_schedule_status_class( $start_utc, $end_utc );

                    echo '<tr class="' . esc_attr( $status_class ) . '">';
                    echo '<td>';
                    echo '<div class="foyer-sched-row-head" style="display:flex; align-items:center; justify-content:space-between; gap:8px;">';
                    echo '<div class="foyer-sched-row-title" style="min-width:0;">';
                    echo '<input type="hidden" name="foyer_channel_scheduler_list_channel[]" value="' . ( $cid ? intval( $cid ) : '' ) . '" />';
                    echo '<span class="foyer-sched-channel-title">' . esc_html( $title ) . '</span>';
                    echo '</div>';
                    echo '</div>';
                    echo '</td>';
                    echo '<td>';
                    echo '<span class="foyer-sched-start-text" data-placeholder="&mdash;" data-display="' . esc_attr( $start_val ) . '">' . ( $start_val ? esc_html( $start_val ) : '&mdash;' ) . '</span>';
                    echo '<input type="hidden" class="foyer-sched-start-hidden" name="foyer_channel_scheduler_list_start[]" value="' . esc_attr( $start_iso ) . '" />';
                    echo '<input type="text" class="foyer-datetime foyer-sched-start-input" value="' . esc_attr( $start_val ) . '" style="display:none;" />';
                    echo '</td>';
                    echo '<td>';
                    echo '<span class="foyer-sched-end-text" data-placeholder="&mdash;" data-display="' . esc_attr( $end_val ) . '">' . ( $end_val ? esc_html( $end_val ) : '&mdash;' ) . '</span>';
                    echo '<input type="hidden" class="foyer-sched-end-hidden" name="foyer_channel_scheduler_list_end[]" value="' . esc_attr( $end_iso ) . '" />';
                    echo '<input type="text" class="foyer-datetime foyer-sched-end-input" value="' . esc_attr( $end_val ) . '" style="display:none;" />';
                    echo '</td>';
                    echo '<td>';
                    echo '<button type="button" class="button button-primary foyer-sched-save" style="display:none;">' . esc_html__( 'Save', 'foyer' ) . '</button> ';
                    echo '<button type="button" class="button foyer-sched-remove" title="' . esc_attr__( 'Remove', 'foyer' ) . '">&times;</button> ';
                    echo '<button type="button" class="button foyer-sched-edit">' . esc_html__( 'Edit', 'foyer' ) . '</button>';
                    echo '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';
            echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save schedule', 'foyer' ) . '</button></p>';

            // Inline JS to toggle edit/save/remove and sync inputs on submit
            ?>
            <script>
            (function($){
                $(function(){
                    var validationError = <?php echo json_encode( __( 'Validation failed', 'foyer' ) ); ?>;
                    var missingValueError = <?php echo json_encode( __( 'Please enter both start and end times.', 'foyer' ) ); ?>;
                    var saveBeforeSubmitMsg = <?php echo json_encode( __( 'Please save all schedule rows before saving.', 'foyer' ) ); ?>;
                    var statusClasses = ['foyer-sched-active', 'foyer-sched-future', 'foyer-sched-past'];

                    function cleanDisplayValue(str){
                        if (!str) { return ''; }
                        var trimmed = $.trim(String(str));
                        if (!trimmed || trimmed === '—') { return ''; }
                        return trimmed;
                    }

                    function ensureDateHelpers(){
                        if (window.foyerSchedulerDateHelpers) { return window.foyerSchedulerDateHelpers; }
                        if (!window.foyer_channel_scheduler_defaults) { return null; }

                        var defaults = window.foyer_channel_scheduler_defaults;
                        var localeKey = defaults.locale || 'en';
                        if ($.foyer_datetimepicker && $.foyer_datetimepicker.setLocale) {
                            $.foyer_datetimepicker.setLocale(localeKey);
                        }

                        var pickerFormat = defaults.picker_format || defaults.datetime_format || 'Y-m-d H:i';
                        var formatterOptions = {};
                        var baseDefaults = ($.fn.foyer_datetimepicker && $.fn.foyer_datetimepicker.defaults) ? $.fn.foyer_datetimepicker.defaults : null;
                        var localeData = (baseDefaults && baseDefaults.i18n) ? baseDefaults.i18n[ localeKey ] : null;
                        if (localeData) {
                            formatterOptions.dateSettings = {
                                days: localeData.dayOfWeek || [],
                                daysShort: localeData.dayOfWeekShort || [],
                                months: localeData.months || [],
                                monthsShort: $.map(localeData.months || [], function(name){ return name ? name.substring(0,3) : ''; })
                            };
                        }

                        var formatter = (typeof DateFormatter !== 'undefined') ? new DateFormatter(formatterOptions) : null;

                        var parseIsoDate = function(value){
                            if (!value) { return null; }
                            var time = Date.parse(value);
                            return isNaN(time) ? null : new Date(time);
                        };

                        var parseExisting = function(value){
                            var trimmed = cleanDisplayValue(value);
                            if (!trimmed) { return null; }
                            if (formatter) {
                                try {
                                    return formatter.parseDate(trimmed, pickerFormat);
                                } catch (err) {}
                            }
                            return parseIsoDate(trimmed);
                        };

                        window.foyerSchedulerDateHelpers = {
                            pickerFormat: pickerFormat,
                            parseExisting: parseExisting
                        };

                        return window.foyerSchedulerDateHelpers;
                    }

                    function setDisplay($span, value){
                        var placeholder = $span.data('placeholder') || '—';
                        var display = cleanDisplayValue(value);
                        $span.text(display ? display : placeholder);
                        $span.attr('data-display', display);
                    }

                    function updateStatusClass($row, status){
                        $row.removeClass(statusClasses.join(' '));
                        if (status) { $row.addClass(status); }
                    }

                    function initPickers($scope){
                        if (!window.foyer_channel_scheduler_defaults) { return; }
                        var helpers = ensureDateHelpers();
                        if (!helpers) { return; }
                        $scope.find('input.foyer-datetime').each(function(){
                            var $i=$(this); if ($i.data('dtp-init')) return;
                            var options = {
                                format: helpers.pickerFormat,
                                dayOfWeekStart: foyer_channel_scheduler_defaults.start_of_week,
                                step: 15,
                                validateOnBlur: false
                            };
                            if (helpers.parseExisting) {
                                options.parseInputDate = helpers.parseExisting;
                            }
                            $i.foyer_datetimepicker(options);
                            $i.data('dtp-init', true);
                        });
                    }
                    $('.foyer-scheduler-form').each(function(){ initPickers($(this)); });
                    $(document).on('click', '.foyer-scheduler-form .foyer-sched-edit', function(){
                        var $row = $(this).closest('tr');
                        var startDisplay = $row.find('.foyer-sched-start-text').attr('data-display') || '';
                        var endDisplay   = $row.find('.foyer-sched-end-text').attr('data-display') || '';
                        $row.find('.foyer-sched-start-input').val(startDisplay);
                        $row.find('.foyer-sched-end-input').val(endDisplay);
                        $row.find('.foyer-sched-start-text, .foyer-sched-end-text').hide();
                        $row.find('.foyer-sched-start-input, .foyer-sched-end-input').show();
                        $row.find('.foyer-sched-edit').hide();
                        $row.find('.foyer-sched-save').show();
                        initPickers($row);
                    });
                    // Save within per-display list (validate overlaps via AJAX)
                    $(document).on('click', '.foyer-scheduler-form .foyer-sched-save', function(){
                        var $row = $(this).closest('tr');
                        var $table = $(this).closest('table');
                        var startVal = $row.find('.foyer-sched-start-input').val();
                        var endVal   = $row.find('.foyer-sched-end-input').val();

                        if (!cleanDisplayValue(startVal) || !cleanDisplayValue(endVal)) {
                            alert(missingValueError);
                            return;
                        }

                        var entries = [];
                        var rowRefs = [];
                        $table.find('tbody tr').each(function(){
                            var $r=$(this);
                            var channelId = $r.find('input[name=\'foyer_channel_scheduler_list_channel[]\']').val();
                            var startDisplay = ($r.is($row)) ? startVal : ($r.find('.foyer-sched-start-text').attr('data-display') || $r.find('.foyer-sched-start-text').text());
                            var endDisplay   = ($r.is($row)) ? endVal   : ($r.find('.foyer-sched-end-text').attr('data-display') || $r.find('.foyer-sched-end-text').text());
                            var sClean = cleanDisplayValue(startDisplay);
                            var eClean = cleanDisplayValue(endDisplay);
                            if (channelId && sClean && eClean) {
                                entries.push({channel:channelId, start:sClean, end:eClean});
                                rowRefs.push($r);
                            }
                        });

                        if (!entries.length) {
                            alert(missingValueError);
                            return;
                        }

                        $.post(ajaxurl, { action:'foyer_validate_schedule', nonce:(window.foyer_display_ajax?foyer_display_ajax.nonce:''), payload: JSON.stringify({ entries: entries }) })
                            .done(function(resp){
                                if (resp && resp.success) {
                                    var normalized = (resp.data && resp.data.normalized) ? resp.data.normalized : [];
                                    $.each(normalized, function(idx, item){
                                        var $target = rowRefs[idx];
                                        if (!$target || !item) { return; }
                                        var startDisplay = item.start_display || '';
                                        var endDisplay   = item.end_display || '';
                                        setDisplay($target.find('.foyer-sched-start-text'), startDisplay);
                                        setDisplay($target.find('.foyer-sched-end-text'), endDisplay);
                                        $target.find('.foyer-sched-start-hidden').val(item.start_iso || '');
                                        $target.find('.foyer-sched-end-hidden').val(item.end_iso || '');
                                        $target.find('.foyer-sched-start-input').val(startDisplay);
                                        $target.find('.foyer-sched-end-input').val(endDisplay);
                                        updateStatusClass($target, item.status_class || '');
                                    });
                                    $row.find('.foyer-sched-start-input, .foyer-sched-end-input').hide();
                                    $row.find('.foyer-sched-start-text, .foyer-sched-end-text').show();
                                    $row.find('.foyer-sched-save').hide();
                                    $row.find('.foyer-sched-edit').show();
                                } else {
                                    var msg=(resp && resp.data && resp.data.message)?resp.data.message:validationError;
                                    alert(msg);
                                }
                            }).fail(function(){ alert(validationError); });
                    });
                    $(document).on('click', '.foyer-sched-remove', function(){
                        var $tb = $(this).closest('table').find('tbody');
                        $(this).closest('tr').remove();
                        if ($tb.find('tr').length === 0) {
                            $tb.append('<tr class="foyer-sched-empty"><td colspan="4"><?php echo esc_js( __( 'No scheduled channels.', 'foyer' ) ); ?></td></tr>');
                        }
                    });
                    $('.foyer-scheduler-form').on('submit', function(e){
                        var pending = false;
                        $(this).find('tbody tr').each(function(){
                            if ($(this).find('.foyer-sched-save').is(':visible')) { pending = true; return false; }
                        });
                        if (pending) {
                            e.preventDefault();
                            alert(saveBeforeSubmitMsg);
                            return false;
                        }
                    });
                });
            })(jQuery);
            </script>
            <?php

            echo '</form>';
            echo '</div>'; // .foyer-sched-panel
            echo '</div>'; // .foyer-sched-card
        }

        echo '</div>';
    }
}
