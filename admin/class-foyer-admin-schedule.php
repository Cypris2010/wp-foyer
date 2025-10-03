<?php

/**
 * Admin UI for central schedules (foyer_schedule CPT): meta boxes, save handler, and AJAX preview.
 */
class Foyer_Admin_Schedule {

    public static function add_meta_boxes() {
        add_meta_box(
            'foyer_schedule_general',
            __( 'General', 'foyer' ),
            array( __CLASS__, 'render_box_general' ),
            'foyer_schedule',
            'normal',
            'high'
        );
        add_meta_box(
            'foyer_schedule_timing',
            __( 'Timing', 'foyer' ),
            array( __CLASS__, 'render_box_timing' ),
            'foyer_schedule',
            'normal',
            'default'
        );
        add_meta_box(
            'foyer_schedule_exceptions',
            __( 'Exceptions & Additional Dates', 'foyer' ),
            array( __CLASS__, 'render_box_exceptions' ),
            'foyer_schedule',
            'normal',
            'default'
        );
        add_meta_box(
            'foyer_schedule_preview',
            __( 'Preview', 'foyer' ),
            array( __CLASS__, 'render_box_preview' ),
            'foyer_schedule',
            'side',
            'default'
        );
        // Ensure any queued admin notices for schedules are rendered
        add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
    }

    public static function render_box_general( $post ) {
        wp_nonce_field( 'foyer_schedule_save', 'foyer_schedule_nonce' );
        $channel  = intval( get_post_meta( $post->ID, 'foyer_schedule_channel', true ) );
        $displays = get_post_meta( $post->ID, 'foyer_schedule_displays', true );
        if ( ! is_array( $displays ) ) { $displays = array(); }
        $channels = Foyer_Channels::get_posts();
        $all_displays = Foyer_Displays::get_posts( array( 'orderby' => 'title', 'order' => 'ASC' ) );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="foyer_schedule_channel"><?php echo esc_html__( 'Channel', 'foyer' ); ?></label></th>
                <td>
                    <select id="foyer_schedule_channel" name="foyer_schedule_channel">
                        <option value="">—</option>
                        <?php foreach ( $channels as $ch ): ?>
                            <option value="<?php echo intval( $ch->ID ); ?>" <?php selected( $channel, $ch->ID ); ?>><?php echo esc_html( get_the_title( $ch->ID ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Displays', 'foyer' ); ?></th>
                <td>
                    <div style="max-height:220px; overflow:auto; border:1px solid #ccd0d4; padding:8px;">
                        <?php if ( empty( $all_displays ) ): ?>
                            <em><?php echo esc_html__( 'No displays found.', 'foyer' ); ?></em>
                        <?php else: foreach ( $all_displays as $d ): ?>
                            <label style="display:block; margin:2px 0;">
                                <input type="checkbox" name="foyer_schedule_displays[]" value="<?php echo intval( $d->ID ); ?>" <?php checked( in_array( intval( $d->ID ), $displays, true ) ); ?> />
                                <?php echo esc_html( get_the_title( $d->ID ) ); ?>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>
                    <p class="description"><?php echo esc_html__( 'Selected displays will use this schedule.', 'foyer' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function render_box_timing( $post ) {
        $mode = get_post_meta( $post->ID, 'foyer_schedule_mode', true );
        if ( ! in_array( $mode, array( 'single', 'recurring' ), true ) ) { $mode = 'single'; }
        $start_utc = get_post_meta( $post->ID, 'foyer_schedule_start_utc', true );
        $end_utc   = get_post_meta( $post->ID, 'foyer_schedule_end_utc', true );
        $start_iso = $start_utc ? gmdate( 'Y-m-d\TH:i:s\Z', intval( $start_utc ) ) : '';
        $end_iso   = $end_utc ? gmdate( 'Y-m-d\TH:i:s\Z', intval( $end_utc ) ) : '';

        $tz = get_post_meta( $post->ID, 'foyer_schedule_tz', true );
        if ( ! is_string( $tz ) || '' === $tz ) { $tz = wp_timezone_string(); }
        $dtstart_local = get_post_meta( $post->ID, 'foyer_schedule_dtstart_local', true );
        $duration = intval( get_post_meta( $post->ID, 'foyer_schedule_duration', true ) );
        if ( $duration <= 0 ) { $duration = HOUR_IN_SECONDS; }
        $rrule = get_post_meta( $post->ID, 'foyer_schedule_rrule', true );
        ?>
        <fieldset>
            <label><input type="radio" name="foyer_schedule_mode" value="single" <?php checked( $mode, 'single' ); ?> /> <?php echo esc_html__( 'Single occurrence', 'foyer' ); ?></label>
            <label style="margin-left:16px;"><input type="radio" name="foyer_schedule_mode" value="recurring" <?php checked( $mode, 'recurring' ); ?> /> <?php echo esc_html__( 'Recurring', 'foyer' ); ?></label>
        </fieldset>
        <div id="foyer_sched_mode_single" style="margin-top:8px; <?php echo ( 'single' === $mode ? '' : 'display:none;' ); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="foyer_schedule_start_iso"><?php echo esc_html__( 'Start (ISO, UTC)', 'foyer' ); ?></label></th>
                    <td><input type="text" class="regular-text" id="foyer_schedule_start_iso" name="foyer_schedule_start_iso" value="<?php echo esc_attr( $start_iso ); ?>" placeholder="2025-01-01T09:00:00Z" /></td>
                </tr>
                <tr>
                    <th><label for="foyer_schedule_end_iso"><?php echo esc_html__( 'End (ISO, UTC)', 'foyer' ); ?></label></th>
                    <td><input type="text" class="regular-text" id="foyer_schedule_end_iso" name="foyer_schedule_end_iso" value="<?php echo esc_attr( $end_iso ); ?>" placeholder="2025-01-01T10:00:00Z" /></td>
                </tr>
            </table>
        </div>
        <div id="foyer_sched_mode_recurring" style="margin-top:8px; <?php echo ( 'recurring' === $mode ? '' : 'display:none;' ); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="foyer_schedule_tz"><?php echo esc_html__( 'Timezone', 'foyer' ); ?></label></th>
                    <td>
                        <input type="text" id="foyer_schedule_tz" name="foyer_schedule_tz" class="regular-text" value="<?php echo esc_attr( $tz ); ?>" />
                        <p class="description"><?php echo esc_html__( 'PHP timezone identifier (e.g. Europe/Berlin).', 'foyer' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="foyer_schedule_dtstart_local"><?php echo esc_html__( 'Start (local)', 'foyer' ); ?></label></th>
                    <td><input type="text" id="foyer_schedule_dtstart_local" name="foyer_schedule_dtstart_local" class="regular-text" value="<?php echo esc_attr( $dtstart_local ); ?>" placeholder="2025-01-01 09:00:00" /></td>
                </tr>
                <tr>
                    <th><label for="foyer_schedule_duration"><?php echo esc_html__( 'Duration (seconds)', 'foyer' ); ?></label></th>
                    <td><input type="number" min="1" id="foyer_schedule_duration" name="foyer_schedule_duration" value="<?php echo esc_attr( $duration ); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="foyer_schedule_rrule"><?php echo esc_html__( 'RRULE', 'foyer' ); ?></label></th>
                    <td>
                        <input type="text" id="foyer_schedule_rrule" name="foyer_schedule_rrule" class="regular-text" value="<?php echo esc_attr( $rrule ); ?>" placeholder="FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,TU,WE,TH,FR;UNTIL=20251231T235959Z" />
                        <p class="description"><?php echo esc_html__( 'You can enter RRULE manually or use the builder below. Supported: FREQ=DAILY/WEEKLY/MONTHLY; INTERVAL; BYDAY; BYMONTHDAY; UNTIL; COUNT.', 'foyer' ); ?></p>
                        <fieldset style="border:1px solid #ccd0d4; padding:8px; margin-top:8px;">
                            <legend><?php echo esc_html__( 'RRULE builder', 'foyer' ); ?></legend>
                            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                                <label><input type="radio" name="foyer_rrule_freq" value="DAILY" /> <?php echo esc_html__( 'Daily', 'foyer' ); ?></label>
                                <label><input type="radio" name="foyer_rrule_freq" value="WEEKLY" /> <?php echo esc_html__( 'Weekly', 'foyer' ); ?></label>
                                <label><input type="radio" name="foyer_rrule_freq" value="MONTHLY" /> <?php echo esc_html__( 'Monthly', 'foyer' ); ?></label>
                                <label style="margin-left:auto;">
                                    <?php echo esc_html__( 'Interval', 'foyer' ); ?>
                                    <input type="number" min="1" name="foyer_rrule_interval" value="1" class="small-text" />
                                </label>
                            </div>
                            <div id="foyer_rrule_weekly_opts" style="margin-top:6px; display:none;">
                                <span><?php echo esc_html__( 'Days', 'foyer' ); ?>:</span>
                                <?php $days = array('MO'=>'Mon','TU'=>'Tue','WE'=>'Wed','TH'=>'Thu','FR'=>'Fri','SA'=>'Sat','SU'=>'Sun'); foreach ($days as $code=>$label): ?>
                                    <label style="margin-right:6px;"><input type="checkbox" name="foyer_rrule_byday[]" value="<?php echo esc_attr($code); ?>" /> <?php echo esc_html($label); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div id="foyer_rrule_monthly_opts" style="margin-top:6px; display:none;">
                                <label><?php echo esc_html__( 'Month days (comma-separated)', 'foyer' ); ?>
                                    <input type="text" name="foyer_rrule_bymonthday" class="regular-text" placeholder="1,15,31" />
                                </label>
                            </div>
                            <div style="margin-top:6px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                                <label><?php echo esc_html__( 'Until (local)', 'foyer' ); ?> <input type="text" name="foyer_rrule_until" class="regular-text" placeholder="2025-12-31 23:59:59" /></label>
                                <label><?php echo esc_html__( 'Count', 'foyer' ); ?> <input type="number" min="1" name="foyer_rrule_count" class="small-text" /></label>
                            </div>
                        </fieldset>
                        <script>
                        (function(){
                            function toggleBuilder(){
                                var freq = document.querySelector('input[name="foyer_rrule_freq"]:checked');
                                var weekly = document.getElementById('foyer_rrule_weekly_opts');
                                var monthly = document.getElementById('foyer_rrule_monthly_opts');
                                if (!freq) { weekly.style.display='none'; monthly.style.display='none'; return; }
                                if (freq.value==='WEEKLY'){ weekly.style.display=''; monthly.style.display='none'; }
                                else if (freq.value==='MONTHLY'){ weekly.style.display='none'; monthly.style.display=''; }
                                else { weekly.style.display='none'; monthly.style.display='none'; }
                            }
                            document.addEventListener('change', function(e){ if(e.target && e.target.name==='foyer_rrule_freq'){ toggleBuilder(); } });
                            document.addEventListener('DOMContentLoaded', toggleBuilder);
                        })();
                        </script>
                    </td>
                </tr>
            </table>
        </div>
        <script>
        (function(){
            function syncMode(){
                var m = document.querySelector('input[name="foyer_schedule_mode"]:checked');
                var single = document.getElementById('foyer_sched_mode_single');
                var recurring = document.getElementById('foyer_sched_mode_recurring');
                if(!m) return; var v = m.value;
                if (v==='single'){ single.style.display=''; recurring.style.display='none'; }
                else { single.style.display='none'; recurring.style.display=''; }
            }
            document.addEventListener('change', function(e){ if(e.target && e.target.name==='foyer_schedule_mode'){ syncMode(); } });
            document.addEventListener('DOMContentLoaded', syncMode);
        })();
        </script>
        <?php
    }

    public static function render_box_exceptions( $post ) {
        $rdates = get_post_meta( $post->ID, 'foyer_schedule_rdates', true );
        if ( ! is_array( $rdates ) ) { $rdates = array(); }
        $exdates = get_post_meta( $post->ID, 'foyer_schedule_exdates', true );
        if ( ! is_array( $exdates ) ) { $exdates = array(); }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="foyer_schedule_rdates"><?php echo esc_html__( 'Additional dates (local)', 'foyer' ); ?></label></th>
                <td>
                    <textarea id="foyer_schedule_rdates" name="foyer_schedule_rdates" rows="4" class="large-text" placeholder="YYYY-mm-dd HH:ii:ss, one per line"><?php echo esc_textarea( implode("\n", $rdates ) ); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="foyer_schedule_exdates"><?php echo esc_html__( 'Exceptions (local)', 'foyer' ); ?></label></th>
                <td>
                    <textarea id="foyer_schedule_exdates" name="foyer_schedule_exdates" rows="4" class="large-text" placeholder="YYYY-mm-dd HH:ii:ss, one per line"><?php echo esc_textarea( implode("\n", $exdates ) ); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function render_box_preview( $post ) {
        $nonce = wp_create_nonce( 'foyer_schedule_save' );
        ?>
        <p><button type="button" class="button" id="foyer_schedule_do_preview"><?php echo esc_html__( 'Preview next 10 occurrences', 'foyer' ); ?></button></p>
        <div id="foyer_schedule_preview_result" style="max-height:240px; overflow:auto; border:1px solid #ccd0d4; padding:8px; background:#fff;"></div>
        <script>
        (function($){
            $('#foyer_schedule_do_preview').on('click', function(){
                var $btn = $(this); var $res = $('#foyer_schedule_preview_result');
                var data = $('#post').serializeArray();
                data.push({name:'action', value:'foyer_schedule_preview'});
                data.push({name:'nonce', value:'<?php echo esc_js( $nonce ); ?>'});
                $btn.prop('disabled', true); $res.text('...');
                $.post(ajaxurl, data).done(function(resp){
                    if (resp && resp.success) {
                        var html=[];
                        if (resp.data && resp.data.occurrences && resp.data.occurrences.length){
                            html.push('<strong><?php echo esc_js( __( 'Occurrences', 'foyer' ) ); ?>:</strong><br/>');
                            resp.data.occurrences.slice(0,10).forEach(function(o){
                                html.push('<div>• '+ (o.start_display||o.start_utc) +' → '+ (o.end_display||o.end_utc) +' <em>('+(o.source||'')+')</em></div>');
                            });
                        } else {
                            html.push('<?php echo esc_js( __( 'No occurrences in preview window.', 'foyer' ) ); ?>');
                        }
                        if (resp.data && resp.data.conflicts && resp.data.conflicts.length){
                            html.push('<hr/><strong style="color:#b32d2e;">'+<?php echo json_encode( __( 'Conflicts', 'foyer' ) ); ?>+':</strong><br/>'+ resp.data.conflicts.join('<br/>'));
                        }
                        $res.html(html.join(''));
                    } else {
                        $res.text('<?php echo esc_js( __( 'Preview failed.', 'foyer' ) ); ?>');
                    }
                }).fail(function(){ $res.text('<?php echo esc_js( __( 'Preview failed.', 'foyer' ) ); ?>'); })
                  .always(function(){ $btn.prop('disabled', false); });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function save_schedule( $post_id, $post ) {
        if ( ! isset( $_POST['foyer_schedule_nonce'] ) || ! wp_verify_nonce( $_POST['foyer_schedule_nonce'], 'foyer_schedule_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $meta = self::normalize_form( $_POST );

        // Conflict validation window: now .. now + 12 months
        $now = current_time( 'timestamp', true );
        $winStart = $now;
        $winEnd   = $now + 365 * DAY_IN_SECONDS;

        // Expand occurrences of this schedule
        $new_occ = Foyer_Schedule_Engine::expand_occurrences( $meta, $winStart, $winEnd );

        // Check for overlaps per selected display against other schedules
        if ( ! empty( $meta['displays'] ) && ! empty( $new_occ ) ) {
            foreach ( $meta['displays'] as $did ) {
                $others = get_posts( array(
                    'post_type'      => 'foyer_schedule',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'post__not_in'   => array( intval( $post_id ) ),
                    'meta_query'     => array(
                        array(
                            'key'     => 'foyer_schedule_displays',
                            'value'   => '"' . intval( $did ) . '"',
                            'compare' => 'LIKE',
                        ),
                    ),
                ) );
                if ( empty( $others ) ) { continue; }
                foreach ( $others as $op ) {
                    $m = Foyer_Schedules::read_meta( $op->ID );
                    $o_occ = Foyer_Schedule_Engine::expand_occurrences( $m, $winStart, $winEnd );
                    if ( empty( $o_occ ) ) { continue; }
                    foreach ( $new_occ as $a ) {
                        foreach ( $o_occ as $b ) {
                            if ( self::overlaps( $a, $b ) ) {
                                $msg = sprintf( __( 'Cannot save. Display "%1$s" conflicts with schedule "%2$s" at %3$s.', 'foyer' ),
                                    get_the_title( $did ), get_the_title( $op->ID ),
                                    wp_date( get_option('date_format').' '.get_option('time_format'), intval( $a['start_utc'] ), wp_timezone() )
                                );
                                self::add_admin_notice( 'error', $msg );
                                // Redirect back to edit screen without saving meta changes
                                $location = add_query_arg( array( 'post' => intval( $post_id ), 'action' => 'edit' ), admin_url( 'post.php' ) );
                                wp_safe_redirect( $location );
                                exit;
                            }
                        }
                    }
                }
            }
        }

        // No conflicts: persist meta
        if ( empty( $meta['channel'] ) ) { delete_post_meta( $post_id, 'foyer_schedule_channel' ); }
        else { update_post_meta( $post_id, 'foyer_schedule_channel', $meta['channel'] ); }

        update_post_meta( $post_id, 'foyer_schedule_displays', $meta['displays'] );
        update_post_meta( $post_id, 'foyer_schedule_mode', $meta['mode'] );

        if ( 'single' === $meta['mode'] ) {
            if ( ! is_null( $meta['start_utc'] ) ) { update_post_meta( $post_id, 'foyer_schedule_start_utc', $meta['start_utc'] ); }
            else { delete_post_meta( $post_id, 'foyer_schedule_start_utc' ); }
            if ( ! is_null( $meta['end_utc'] ) ) { update_post_meta( $post_id, 'foyer_schedule_end_utc', $meta['end_utc'] ); }
            else { delete_post_meta( $post_id, 'foyer_schedule_end_utc' ); }
            // Clear recurrence fields
            delete_post_meta( $post_id, 'foyer_schedule_tz' );
            delete_post_meta( $post_id, 'foyer_schedule_dtstart_local' );
            delete_post_meta( $post_id, 'foyer_schedule_duration' );
            delete_post_meta( $post_id, 'foyer_schedule_rrule' );
        } else {
            // Recurrence fields
            update_post_meta( $post_id, 'foyer_schedule_tz', $meta['tz'] );
            update_post_meta( $post_id, 'foyer_schedule_dtstart_local', $meta['dtstart_local'] );
            update_post_meta( $post_id, 'foyer_schedule_duration', $meta['duration'] );
            update_post_meta( $post_id, 'foyer_schedule_rrule', $meta['rrule'] );
            update_post_meta( $post_id, 'foyer_schedule_rdates', $meta['rdates'] );
            update_post_meta( $post_id, 'foyer_schedule_exdates', $meta['exdates'] );
            // Clear single fields
            delete_post_meta( $post_id, 'foyer_schedule_start_utc' );
            delete_post_meta( $post_id, 'foyer_schedule_end_utc' );
        }
    }

    public static function ajax_preview() {
        check_ajax_referer( 'foyer_schedule_save', 'nonce', true );
        if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( array( 'message' => __( 'Not allowed', 'foyer' ) ), 403 ); }
        $meta = self::normalize_form( $_POST );
        $now = current_time( 'timestamp', true );
        $windowStart = $now;
        $windowEnd = $now + 90 * DAY_IN_SECONDS;
        $occ = Foyer_Schedule_Engine::expand_occurrences( $meta, $windowStart, $windowEnd );
        $fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $occ_out = array();
        foreach ( $occ as $o ) {
            $occ_out[] = array(
                'start_utc' => intval( $o['start_utc'] ),
                'end_utc'   => intval( $o['end_utc'] ),
                'start_display' => wp_date( $fmt, intval( $o['start_utc'] ), wp_timezone() ),
                'end_display'   => wp_date( $fmt, intval( $o['end_utc'] ), wp_timezone() ),
                'source'    => isset( $o['source'] ) ? $o['source'] : '',
            );
        }

        // Optional: basic conflict messages
        $conflicts = self::find_conflicts_messages( $meta, $occ, $windowStart, $windowEnd );

        wp_send_json_success( array( 'occurrences' => array_slice( $occ_out, 0, 10 ), 'conflicts' => $conflicts ) );
    }

    private static function find_conflicts_messages( $meta, $occ, $windowStart, $windowEnd ) {
        if ( empty( $meta['displays'] ) ) { return array(); }
        $messages = array();
        foreach ( $meta['displays'] as $did ) {
            $others = get_posts( array(
                'post_type'      => 'foyer_schedule',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => 'foyer_schedule_displays',
                        'value'   => '"' . intval( $did ) . '"',
                        'compare' => 'LIKE',
                    ),
                ),
            ) );
            foreach ( $others as $op ) {
                $m = Foyer_Schedules::read_meta( $op->ID );
                $o_occ = Foyer_Schedule_Engine::expand_occurrences( $m, $windowStart, $windowEnd );
                foreach ( $occ as $a ) {
                    foreach ( $o_occ as $b ) {
                        if ( self::overlaps( $a, $b ) ) {
                            $messages[] = sprintf( __( 'Display %1$s conflicts with schedule %2$s at %3$s.', 'foyer' ),
                                get_the_title( $did ), get_the_title( $op->ID ),
                                wp_date( get_option('date_format').' '.get_option('time_format'), intval( $a['start_utc'] ), wp_timezone() )
                            );
                            break 2;
                        }
                    }
                }
            }
        }
        return $messages;
    }

    private static function overlaps( $a, $b ) {
        return ( intval( $a['end_utc'] ) > intval( $b['start_utc'] ) ) && ( intval( $b['end_utc'] ) > intval( $a['start_utc'] ) );
    }

    private static function normalize_form( $src ) {
        $out = array(
            'channel' => isset( $src['foyer_schedule_channel'] ) ? intval( $src['foyer_schedule_channel'] ) : 0,
            'displays' => array(),
            'mode' => isset( $src['foyer_schedule_mode'] ) && in_array( $src['foyer_schedule_mode'], array('single','recurring'), true ) ? $src['foyer_schedule_mode'] : 'single',
            'start_utc' => null,
            'end_utc'   => null,
            'tz' => '',
            'dtstart_local' => '',
            'duration' => HOUR_IN_SECONDS,
            'rrule' => '',
            'rdates' => array(),
            'exdates' => array(),
            'overrides' => array(),
        );

        if ( ! empty( $src['foyer_schedule_displays'] ) && is_array( $src['foyer_schedule_displays'] ) ) {
            foreach ( $src['foyer_schedule_displays'] as $v ) { $out['displays'][] = intval( $v ); }
            $out['displays'] = array_values( array_unique( array_filter( $out['displays'] ) ) );
        }

        if ( 'single' === $out['mode'] ) {
            $start_iso = isset( $src['foyer_schedule_start_iso'] ) ? trim( $src['foyer_schedule_start_iso'] ) : '';
            $end_iso   = isset( $src['foyer_schedule_end_iso'] ) ? trim( $src['foyer_schedule_end_iso'] ) : '';
            $out['start_utc'] = self::parse_iso_utc_to_ts( $start_iso );
            $out['end_utc']   = self::parse_iso_utc_to_ts( $end_iso );
            if ( ! is_null( $out['start_utc'] ) && ! is_null( $out['end_utc'] ) && $out['end_utc'] <= $out['start_utc'] ) {
                $out['end_utc'] = $out['start_utc'] + HOUR_IN_SECONDS;
            }
        } else {
            $out['tz'] = isset( $src['foyer_schedule_tz'] ) ? sanitize_text_field( $src['foyer_schedule_tz'] ) : wp_timezone_string();
            $out['dtstart_local'] = isset( $src['foyer_schedule_dtstart_local'] ) ? trim( $src['foyer_schedule_dtstart_local'] ) : '';
            $dur = isset( $src['foyer_schedule_duration'] ) ? intval( $src['foyer_schedule_duration'] ) : HOUR_IN_SECONDS;
            $out['duration'] = max( 1, $dur );
            // Prefer manual RRULE if provided; otherwise build from builder fields
            $manual_rrule = isset( $src['foyer_schedule_rrule'] ) ? strtoupper( trim( $src['foyer_schedule_rrule'] ) ) : '';
            if ( $manual_rrule ) {
                $out['rrule'] = $manual_rrule;
            } else {
                $parts = array();
                $freq = isset( $src['foyer_rrule_freq'] ) ? strtoupper( trim( $src['foyer_rrule_freq'] ) ) : '';
                if ( in_array( $freq, array('DAILY','WEEKLY','MONTHLY'), true ) ) {
                    $parts[] = 'FREQ=' . $freq;
                    $interval = isset( $src['foyer_rrule_interval'] ) ? intval( $src['foyer_rrule_interval'] ) : 1;
                    if ( $interval > 1 ) { $parts[] = 'INTERVAL=' . $interval; }
                    if ( 'WEEKLY' === $freq && ! empty( $src['foyer_rrule_byday'] ) && is_array( $src['foyer_rrule_byday'] ) ) {
                        $byday = array();
                        foreach ( $src['foyer_rrule_byday'] as $d ) { $d = strtoupper( trim( $d ) ); if ( preg_match('/^(MO|TU|WE|TH|FR|SA|SU)$/', $d ) ) { $byday[] = $d; } }
                        if ( ! empty( $byday ) ) { $parts[] = 'BYDAY=' . implode( ',', $byday ); }
                    }
                    if ( 'MONTHLY' === $freq && ! empty( $src['foyer_rrule_bymonthday'] ) ) {
                        $raw = explode( ',', $src['foyer_rrule_bymonthday'] );
                        $md = array();
                        foreach ( $raw as $v ) { $n = intval( trim( $v ) ); if ( $n >= 1 && $n <= 31 ) { $md[] = $n; } }
                        if ( ! empty( $md ) ) { $parts[] = 'BYMONTHDAY=' . implode( ',', $md ); }
                    }
                    // UNTIL or COUNT
                    $until_in = isset( $src['foyer_rrule_until'] ) ? trim( $src['foyer_rrule_until'] ) : '';
                    if ( $until_in !== '' ) {
                        // parse local -> UTC -> RFC UNTIL format
                        $tz = new DateTimeZone( $out['tz'] );
                        $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace('T',' ', $until_in ), $tz );
                        if ( false === $dt ) { try { $dt = new DateTimeImmutable( $until_in, $tz ); } catch (Exception $e) { $dt = false; } }
                        if ( $dt instanceof DateTimeImmutable ) {
                            $dt_utc = $dt->setTimezone( new DateTimeZone('UTC') );
                            $parts[] = 'UNTIL=' . $dt_utc->format('Ymd\THis\Z');
                        }
                    } else {
                        $count = isset( $src['foyer_rrule_count'] ) ? intval( $src['foyer_rrule_count'] ) : 0;
                        if ( $count > 0 ) { $parts[] = 'COUNT=' . $count; }
                    }
                }
                $out['rrule'] = implode( ';', $parts );
            }
            $out['rdates'] = self::split_lines( isset( $src['foyer_schedule_rdates'] ) ? $src['foyer_schedule_rdates'] : '' );
            $out['exdates'] = self::split_lines( isset( $src['foyer_schedule_exdates'] ) ? $src['foyer_schedule_exdates'] : '' );
        }

        return $out;
    }

    private static function split_lines( $value ) {
        $lines = preg_split( '/\r\n|\n|\r/', (string) $value );
        $out = array();
        foreach ( $lines as $line ) {
            $line = trim( (string) $line ); if ( '' === $line ) continue;
            $out[] = $line;
        }
        return $out;
    }

    private static function parse_iso_utc_to_ts( $value ) {
        $value = trim( (string) $value ); if ( '' === $value ) { return null; }
        try {
            $dt = new DateTimeImmutable( $value );
            return $dt->getTimestamp();
        } catch ( Exception $e ) {
            return null;
        }
    }

    public static function add_admin_notice( $type, $message ) {
        $uid = get_current_user_id();
        if ( empty( $uid ) ) { return; }
        $key = 'foyer_schedule_notices_' . $uid;
        $notices = get_transient( $key );
        if ( empty( $notices ) || ! is_array( $notices ) ) { $notices = array(); }
        $notices[] = array( 'type' => $type, 'message' => $message );
        set_transient( $key, $notices, MINUTE_IN_SECONDS );
    }

    public static function render_notices() {
        $uid = get_current_user_id();
        if ( empty( $uid ) ) { return; }
        $key = 'foyer_schedule_notices_' . $uid;
        $notices = get_transient( $key );
        if ( empty( $notices ) || ! is_array( $notices ) ) { return; }
        delete_transient( $key );
        foreach ( $notices as $n ) {
            $class = 'notice';
            switch ( $n['type'] ) {
                case 'error': $class .= ' notice-error'; break;
                case 'warning': $class .= ' notice-warning'; break;
                case 'success': $class .= ' notice-success'; break;
                default: $class .= ' notice-info'; break;
            }
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $n['message'] ) . '</p></div>';
        }
    }
}
