<?php

/**
 * Central schedules repository and recurrence engine scaffold.
 *
 * Provides a central API for reading schedules for displays and a minimal
 * occurrence expansion engine. Initially supports single occurrences (UTC)
 * and scaffolds for future RRULE/RDATE/EXDATE support.
 *
 * @package Foyer\includes
 */

if ( ! class_exists( 'Foyer_Schedules' ) ) {
	class Foyer_Schedules {

		/**
		 * Returns all schedule occurrences that apply to a display within a window.
		 *
		 * @param int      $display_id     Display post ID.
		 * @param int|null $windowStartUtc Start of window (UTC timestamp). Defaults to now-1d.
		 * @param int|null $windowEndUtc   End of window (UTC timestamp). Defaults to now+30d.
		 * @return array<int, array{start_utc:int,end_utc:int,channel:int,source:string,occ_id:string}>
		 */
		public static function get_for_display( $display_id, $windowStartUtc = null, $windowEndUtc = null ) {
			$display_id = intval( $display_id );
			if ( $display_id <= 0 ) { return array(); }

			if ( is_null( $windowStartUtc ) ) { $windowStartUtc = current_time( 'timestamp', true ) - DAY_IN_SECONDS; }
			if ( is_null( $windowEndUtc ) ) { $windowEndUtc   = current_time( 'timestamp', true ) + 30 * DAY_IN_SECONDS; }

			$args = array(
				'post_type'      => 'foyer_schedule',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					// Serialized array search – support both integer (i:{id};) and string ("{id}") representations
					'relation' => 'OR',
					array(
						'key'     => 'foyer_schedule_displays',
						'value'   => 'i:' . $display_id . ';',
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'foyer_schedule_displays',
						'value'   => '"' . $display_id . '"',
						'compare' => 'LIKE',
					),
				),
			);

			$posts = get_posts( $args );
			if ( empty( $posts ) ) { return array(); }

			$out = array();
			foreach ( $posts as $p ) {
				$meta = self::read_meta( $p->ID );
				$occ  = Foyer_Schedule_Engine::expand_occurrences( $meta, $windowStartUtc, $windowEndUtc );
				if ( empty( $occ ) ) { continue; }
				foreach ( $occ as $o ) {
					$chan = isset( $o['channel'] ) ? intval( $o['channel'] ) : 0;
					if ( $chan <= 0 ) { $chan = intval( $meta['channel'] ); }
					$o['channel'] = $chan;
					$out[] = $o;
				}
			}

			usort( $out, function( $a, $b ) {
				$sa = intval( isset( $a['start_utc'] ) ? $a['start_utc'] : PHP_INT_MAX );
				$sb = intval( isset( $b['start_utc'] ) ? $b['start_utc'] : PHP_INT_MAX );
				if ( $sa === $sb ) { return 0; }
				return ( $sa < $sb ) ? -1 : 1;
			} );

			return $out;
		}

		/**
		 * Reads all relevant meta fields for a schedule post.
		 *
		 * @param int $post_id
		 * @return array
		 */
		public static function read_meta( $post_id ) {
			$channel   = intval( get_post_meta( $post_id, 'foyer_schedule_channel', true ) );
			$displays  = get_post_meta( $post_id, 'foyer_schedule_displays', true );
			if ( ! is_array( $displays ) ) { $displays = array(); }

			// Single occurrence (UTC) path
			$start_utc = get_post_meta( $post_id, 'foyer_schedule_start_utc', true );
			$end_utc   = get_post_meta( $post_id, 'foyer_schedule_end_utc', true );

			// Recurrence scaffolding
			$tz       = get_post_meta( $post_id, 'foyer_schedule_tz', true );
			if ( ! is_string( $tz ) || '' === $tz ) { $tz = wp_timezone_string(); }
			$dtstart_local = get_post_meta( $post_id, 'foyer_schedule_dtstart_local', true );
			$duration = intval( get_post_meta( $post_id, 'foyer_schedule_duration', true ) );
			if ( $duration <= 0 ) { $duration = HOUR_IN_SECONDS; }

			$rrule   = get_post_meta( $post_id, 'foyer_schedule_rrule', true );
			$rdates  = get_post_meta( $post_id, 'foyer_schedule_rdates', true );
			if ( ! is_array( $rdates ) ) { $rdates = array(); }
			$exdates = get_post_meta( $post_id, 'foyer_schedule_exdates', true );
			if ( ! is_array( $exdates ) ) { $exdates = array(); }
			$overrides = get_post_meta( $post_id, 'foyer_schedule_overrides', true );
			if ( ! is_array( $overrides ) ) { $overrides = array(); }

			return array(
				'post_id'        => intval( $post_id ),
				'channel'        => $channel,
				'displays'       => array_map( 'intval', $displays ),
				'start_utc'      => ( '' !== $start_utc ? intval( $start_utc ) : null ),
				'end_utc'        => ( '' !== $end_utc ? intval( $end_utc ) : null ),
				'tz'             => $tz,
				'dtstart_local'  => is_string( $dtstart_local ) ? $dtstart_local : '',
				'duration'       => $duration,
				'rrule'          => is_string( $rrule ) ? $rrule : '',
				'rdates'         => $rdates,
				'exdates'        => $exdates,
				'overrides'      => $overrides,
			);
		}

		/**
		 * Builds an RRULE string from builder-style fields.
		 *
		 * Accepts keys similarly to the schedule meta box builder:
		 * - foyer_rrule_freq: DAILY|WEEKLY|MONTHLY
		 * - foyer_rrule_interval: int >= 1
		 * - foyer_rrule_byday[]: array of MO,TU,WE,TH,FR,SA,SU (WEEKLY only)
		 * - foyer_rrule_bymonthday: comma-separated ints (MONTHLY only)
		 * - foyer_rrule_until: local datetime string (Y-m-d H:i:s). Converted to UTC UNTIL=YYYYMMDDTHHMMSSZ
		 * - foyer_rrule_count: int
		 *
		 * If UNTIL is provided, COUNT is ignored.
		 *
		 * @param array $src
		 * @return string RRULE
		 */
		public static function build_rrule_from_builder_fields( $src ) {
			$parts = array();
			$freq = isset( $src['foyer_rrule_freq'] ) ? strtoupper( trim( (string) $src['foyer_rrule_freq'] ) ) : '';
			if ( in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY' ), true ) ) {
				$parts[] = 'FREQ=' . $freq;
				$interval = isset( $src['foyer_rrule_interval'] ) ? intval( $src['foyer_rrule_interval'] ) : 1;
				$interval = max( 1, $interval );
				if ( $interval > 1 ) { $parts[] = 'INTERVAL=' . $interval; }

				if ( 'WEEKLY' === $freq && ! empty( $src['foyer_rrule_byday'] ) && is_array( $src['foyer_rrule_byday'] ) ) {
					$byday = array();
					foreach ( $src['foyer_rrule_byday'] as $d ) {
						$d = strtoupper( trim( (string) $d ) );
						if ( preg_match( '/^(MO|TU|WE|TH|FR|SA|SU)$/', $d ) ) {
							$byday[] = $d;
						}
					}
					if ( ! empty( $byday ) ) { $parts[] = 'BYDAY=' . implode( ',', $byday ); }
				}

				if ( 'MONTHLY' === $freq && ! empty( $src['foyer_rrule_bymonthday'] ) ) {
					$raw = explode( ',', (string) $src['foyer_rrule_bymonthday'] );
					$md = array();
					foreach ( $raw as $v ) { $v = intval( trim( $v ) ); if ( $v >= 1 && $v <= 31 ) { $md[] = $v; } }
					if ( ! empty( $md ) ) { $parts[] = 'BYMONTHDAY=' . implode( ',', $md ); }
				}

				$until_in = isset( $src['foyer_rrule_until'] ) ? trim( (string) $src['foyer_rrule_until'] ) : '';
				if ( '' !== $until_in ) {
					// Convert local datetime to UTC UNTIL in RFC form
					try {
						$tz = wp_timezone();
						$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', str_replace( 'T', ' ', $until_in ), $tz );
						if ( false === $dt ) { $dt = new DateTimeImmutable( $until_in, $tz ); }
						if ( $dt instanceof DateTimeImmutable ) {
							$parts[] = 'UNTIL=' . $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
						}
					} catch ( Exception $e ) {}
				} else {
					$count = isset( $src['foyer_rrule_count'] ) ? intval( $src['foyer_rrule_count'] ) : 0;
					if ( $count > 0 ) { $parts[] = 'COUNT=' . $count; }
				}
			}
			return implode( ';', $parts );
		}
	}
}

if ( ! class_exists( 'Foyer_Schedule_Engine' ) ) {
	class Foyer_Schedule_Engine {

		/**
		 * Expands a schedule meta payload to concrete occurrences within a window.
		 *
		 * MVP: supports single occurrence via start_utc/end_utc. RRULE/RDATE/EXDATE
		 * scaffolding is left for subsequent iterations.
		 *
		 * @param array $meta
		 * @param int   $windowStartUtc
		 * @param int   $windowEndUtc
		 * @return array<int, array{start_utc:int,end_utc:int,channel:int,source:string,occ_id:string}>
		 */
		public static function expand_occurrences( $meta, $windowStartUtc, $windowEndUtc ) {
			// Transient cache per schedule + window + meta signature to speed up expansion
			$pid = isset( $meta['post_id'] ) ? intval( $meta['post_id'] ) : 0;
			$signature_data = array(
				$meta['channel'] ?? '',
				$meta['start_utc'] ?? '',
				$meta['end_utc'] ?? '',
				$meta['tz'] ?? '',
				$meta['dtstart_local'] ?? '',
				$meta['duration'] ?? '',
				$meta['rrule'] ?? '',
				$meta['rdates'] ?? array(),
				$meta['exdates'] ?? array(),
				$meta['overrides'] ?? array(),
			);
			$meta_hash = md5( wp_json_encode( $signature_data ) );
			$cache_key = 'foyer_occ_' . $pid . '_' . intval($windowStartUtc) . '_' . intval($windowEndUtc) . '_' . $meta_hash;
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			$occ = array();

			// 1) Single-occurrence path
			if ( ! empty( $meta['start_utc'] ) && ! empty( $meta['end_utc'] ) ) {
				$s = intval( $meta['start_utc'] );
				$e = intval( $meta['end_utc'] );
				if ( self::intersects_window( $s, $e, $windowStartUtc, $windowEndUtc ) ) {
					$occ[] = array(
						'start_utc' => $s,
						'end_utc'   => $e,
						'channel'   => intval( $meta['channel'] ),
						'source'    => 'SINGLE',
						'occ_id'    => gmdate( 'Y-m-d\\TH:i:s\\Z', $s ),
					);
				}
			}

			// 2) Recurrence path (RRULE/RDATE/EXDATE)
			$rrule = is_string( $meta['rrule'] ?? '' ) ? trim( $meta['rrule'] ) : '';
			$tz_id = is_string( $meta['tz'] ?? '' ) && $meta['tz'] ? $meta['tz'] : wp_timezone_string();
			$tz    = new DateTimeZone( $tz_id );
			$duration = max( 1, intval( $meta['duration'] ?? HOUR_IN_SECONDS ) );
			$dtstart_local_str = is_string( $meta['dtstart_local'] ?? '' ) ? trim( $meta['dtstart_local'] ) : '';

			$occ_by_id = array();

			if ( $rrule && $dtstart_local_str ) {
				$rule = self::parse_rrule( $rrule );
				$dtstart_local = self::parse_local( $dtstart_local_str, $tz );
				if ( $dtstart_local ) {
					$generated = self::expand_rrule_occurrences( $dtstart_local, $duration, $rule, $tz, $windowStartUtc, $windowEndUtc );
					foreach ( $generated as $g ) {
						$occ_by_id[ $g['occ_id'] ] = $g;
					}
				}
			}

			// RDATE: additional single occurrences (local times)
			if ( ! empty( $meta['rdates'] ) && is_array( $meta['rdates'] ) ) {
				foreach ( $meta['rdates'] as $rdate_str ) {
					$r_local = self::parse_local( $rdate_str, $tz );
					if ( ! $r_local ) { continue; }
					$s = self::to_utc_ts( $r_local );
					$e = $s + $duration;
					if ( ! self::intersects_window( $s, $e, $windowStartUtc, $windowEndUtc ) ) { continue; }
					$occ_id = gmdate( 'Y-m-d\\TH:i:s\\Z', $s );
					$occ_by_id[ $occ_id ] = array(
						'start_utc' => $s,
						'end_utc'   => $e,
						'channel'   => intval( $meta['channel'] ),
						'source'    => 'RDATE',
						'occ_id'    => $occ_id,
					);
				}
			}

			// EXDATE: remove matching local start times
			if ( ! empty( $meta['exdates'] ) && is_array( $meta['exdates'] ) ) {
				$ex_local_norm = array();
				foreach ( $meta['exdates'] as $ex_str ) {
					$dt = self::parse_local( $ex_str, $tz );
					if ( $dt ) { $ex_local_norm[ $dt->format( 'Y-m-d H:i:s' ) ] = true; }
				}
				if ( ! empty( $ex_local_norm ) ) {
					foreach ( $occ_by_id as $id => $o ) {
						$local = self::from_utc_ts_local( $o['start_utc'], $tz );
						if ( $local && isset( $ex_local_norm[ $local->format( 'Y-m-d H:i:s' ) ] ) ) {
							unset( $occ_by_id[ $id ] );
						}
					}
				}
			}

			// Apply overrides (keyed by pre-override occ_id in UTC ISO)
			if ( ! empty( $meta['overrides'] ) && is_array( $meta['overrides'] ) ) {
				foreach ( $meta['overrides'] as $key_occ_id => $ov ) {
					if ( ! isset( $occ_by_id[ $key_occ_id ] ) ) { continue; }
					$base = $occ_by_id[ $key_occ_id ];
					$channel = isset( $ov['channel'] ) && $ov['channel'] ? intval( $ov['channel'] ) : $base['channel'];
					$dur = isset( $ov['duration'] ) && intval( $ov['duration'] ) > 0 ? intval( $ov['duration'] ) : ( $base['end_utc'] - $base['start_utc'] );
					$start_local_str = isset( $ov['start_local'] ) ? trim( strval( $ov['start_local'] ) ) : '';
					if ( $start_local_str !== '' ) {
						$start_local = self::parse_local( $start_local_str, $tz );
						if ( $start_local ) {
							$s = self::to_utc_ts( $start_local );
							$e = $s + $dur;
							$new_id = gmdate( 'Y-m-d\\TH:i:s\\Z', $s );
							unset( $occ_by_id[ $key_occ_id ] );
							$occ_by_id[ $new_id ] = array(
								'start_utc' => $s,
								'end_utc'   => $e,
								'channel'   => $channel,
								'source'    => 'OVERRIDE',
								'occ_id'    => $new_id,
							);
						}
					} else {
						// No new start, only adjust duration/channel
						$base['channel'] = $channel;
						$base['end_utc'] = $base['start_utc'] + $dur;
						$base['source']  = 'OVERRIDE';
						$occ_by_id[ $key_occ_id ] = $base;
					}
				}
			}

			// Merge single-occurrence path (if any) with recurrence-based set
			foreach ( $occ as $o ) {
				$occ_by_id[ $o['occ_id'] ] = $o;
			}

			// Sort and return
			$final = array_values( $occ_by_id );
			usort( $final, function( $a, $b ) {
				return intval( $a['start_utc'] ) <=> intval( $b['start_utc'] );
			} );
			// Cache for a short period to avoid repeated expansion on list views
			set_transient( $cache_key, $final, 5 * MINUTE_IN_SECONDS );
			return $final;
		}

		private static function parse_rrule( $rrule ) {
			$out = array( 'FREQ' => '', 'INTERVAL' => 1, 'BYDAY' => array(), 'BYMONTHDAY' => array(), 'UNTIL_UTC' => null, 'COUNT' => null );
			$parts = preg_split( '/;/', strtoupper( trim( $rrule ) ) );
			foreach ( $parts as $part ) {
				if ( '' === $part ) continue;
				list( $k, $v ) = array_pad( explode( '=', $part, 2 ), 2, '' );
				$k = trim( $k ); $v = trim( $v );
				if ( 'FREQ' === $k ) { $out['FREQ'] = $v; continue; }
				if ( 'INTERVAL' === $k ) { $out['INTERVAL'] = max( 1, intval( $v ) ); continue; }
				if ( 'COUNT' === $k ) { $out['COUNT'] = max( 1, intval( $v ) ); continue; }
				if ( 'UNTIL' === $k ) {
					// Accept YYYYMMDDTHHMMSSZ or ISO; parse into UTC timestamp
					$ts = self::parse_until_utc( $v );
					$out['UNTIL_UTC'] = $ts;
					continue;
				}
				if ( 'BYDAY' === $k ) {
					$out['BYDAY'] = array_filter( array_map( 'trim', explode( ',', $v ) ) );
					continue;
				}
				if ( 'BYMONTHDAY' === $k ) {
					$vals = array_filter( array_map( 'trim', explode( ',', $v ) ) );
					$out['BYMONTHDAY'] = array();
					foreach ( $vals as $d ) { $out['BYMONTHDAY'][] = intval( $d ); }
					continue;
				}
			}
			return $out;
		}

		private static function parse_until_utc( $val ) {
			$val = trim( $val );
			if ( '' === $val ) { return null; }
			// RFC style YYYYMMDDTHHMMSSZ
			if ( preg_match( '/^\d{8}T\d{6}Z$/', $val ) ) {
				$dt = DateTimeImmutable::createFromFormat( 'Ymd\THis\Z', $val, new DateTimeZone( 'UTC' ) );
				if ( $dt ) { return $dt->getTimestamp(); }
			}
			// Try generic parse (assume UTC if ends with Z)
			try {
				$dt = new DateTimeImmutable( $val, new DateTimeZone( 'UTC' ) );
				return $dt->getTimestamp();
			} catch ( Exception $e ) {}
			return null;
		}

		private static function expand_rrule_occurrences( DateTimeImmutable $dtstart_local, $duration, $rule, DateTimeZone $tz, $windowStartUtc, $windowEndUtc ) {
			$max_loops = 5000; // safety guard
			$out = array();
			$freq = strtoupper( strval( $rule['FREQ'] ?? '' ) );
			$interval = max( 1, intval( $rule['INTERVAL'] ?? 1 ) );
			$until_utc = isset( $rule['UNTIL_UTC'] ) ? $rule['UNTIL_UTC'] : null;
			$count = isset( $rule['COUNT'] ) ? intval( $rule['COUNT'] ) : null;

			$emitted = 0; $loops = 0;

			if ( 'DAILY' === $freq ) {
				$cur = $dtstart_local;
				while ( $loops++ < $max_loops ) {
					$s = self::to_utc_ts( $cur ); $e = $s + $duration;
					if ( $until_utc && $s > $until_utc ) break;
					if ( self::intersects_window( $s, $e, $windowStartUtc, $windowEndUtc ) ) {
						$out[] = self::make_occ( $s, $e ); $emitted++;
						if ( $count && $emitted >= $count ) break;
					}
					// advance
					$cur = $cur->modify( '+' . $interval . ' days' );
					// stop when start beyond window and beyond UNTIL
					if ( $s > $windowEndUtc && ! $count ) break;
				}
			}
			elseif ( 'WEEKLY' === $freq ) {
				$byday = is_array( $rule['BYDAY'] ?? null ) ? $rule['BYDAY'] : array();
				if ( empty( $byday ) ) { $byday = array( strtoupper( $dtstart_local->format( 'D' ) ) ); }
				$week_anchor = self::week_anchor( $dtstart_local );
				$weeks = 0;
				while ( $loops++ < $max_loops ) {
					$week_start = $week_anchor->modify( '+' . ( $weeks * $interval ) . ' weeks' );
					foreach ( $byday as $wd ) {
						$day_name = self::weekday_to_name( $wd ); if ( ! $day_name ) { continue; }
						$hour = intval( $dtstart_local->format( 'H' ) ); $min = intval( $dtstart_local->format( 'i' ) ); $sec = intval( $dtstart_local->format( 's' ) );
						$candidate = self::set_weekday_time( $week_start, $day_name, $hour, $min, $sec );
						if ( $candidate < $dtstart_local ) { continue; }
						$s = self::to_utc_ts( $candidate ); $e = $s + $duration;
						if ( $until_utc && $s > $until_utc ) { break 2; }
						if ( self::intersects_window( $s, $e, $windowStartUtc, $windowEndUtc ) ) {
							$out[] = self::make_occ( $s, $e ); $emitted++;
							if ( $count && $emitted >= $count ) { break 2; }
						}
					}
					$weeks++;
					if ( ! $count && ( self::to_utc_ts( $week_start ) > $windowEndUtc ) ) { break; }
				}
			}
			elseif ( 'MONTHLY' === $freq ) {
				$days = is_array( $rule['BYMONTHDAY'] ?? null ) ? $rule['BYMONTHDAY'] : array( intval( $dtstart_local->format( 'j' ) ) );
				$months = 0;
				while ( $loops++ < $max_loops ) {
					$month_start = ( new DateTimeImmutable( $dtstart_local->format( 'Y-m-01 H:i:s' ), $dtstart_local->getTimezone() ) )->modify( '+' . ( $months * $interval ) . ' months' );
					foreach ( $days as $d ) {
						$d = intval( $d ); if ( $d < 1 || $d > 31 ) { continue; }
						$Y = intval( $month_start->format( 'Y' ) ); $M = intval( $month_start->format( 'm' ) );
						$last_day = intval( ( new DateTimeImmutable( date( 'Y-m-t 00:00:00', strtotime( sprintf( '%04d-%02d-01', $Y, $M ) ) ), $dtstart_local->getTimezone() ) )->format( 't' ) );
						if ( $d > $last_day ) { continue; }
						$hour = intval( $dtstart_local->format( 'H' ) ); $min = intval( $dtstart_local->format( 'i' ) ); $sec = intval( $dtstart_local->format( 's' ) );
						$candidate = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $Y, $M, $d, $hour, $min, $sec ), $dtstart_local->getTimezone() );
						if ( ! $candidate ) { continue; }
						if ( $candidate < $dtstart_local ) { continue; }
						$s = self::to_utc_ts( $candidate ); $e = $s + $duration;
						if ( $until_utc && $s > $until_utc ) { break 2; }
						if ( self::intersects_window( $s, $e, $windowStartUtc, $windowEndUtc ) ) {
							$out[] = self::make_occ( $s, $e ); $emitted++;
							if ( $count && $emitted >= $count ) { break 2; }
						}
					}
					$months++;
					if ( ! $count && ( self::to_utc_ts( $month_start ) > $windowEndUtc ) ) { break; }
				}
			}

			// Dedupe and index by occ_id
			$indexed = array();
			foreach ( $out as $o ) { $indexed[ $o['occ_id'] ] = $o; }
			return $indexed;
		}

		private static function intersects_window( $s, $e, $ws, $we ) {
			return ( intval( $e ) > intval( $ws ) && intval( $s ) < intval( $we ) );
		}

		private static function make_occ( $s, $e ) {
			return array(
				'start_utc' => intval( $s ),
				'end_utc'   => intval( $e ),
				'channel'   => 0,
				'source'    => 'RRULE',
				'occ_id'    => gmdate( 'Y-m-d\\TH:i:s\\Z', intval( $s ) ),
			);
		}

		private static function parse_local( $value, DateTimeZone $tz ) {
			$value = trim( (string) $value ); if ( '' === $value ) { return null; }
			// Accept "Y-m-d H:i:s" or ISO "Y-m-dTH:i:s"
			$value_iso = str_replace( 'T', ' ', $value );
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value_iso, $tz );
			if ( false !== $dt ) { return $dt; }
			// Try looser parse
			try { return new DateTimeImmutable( $value, $tz ); } catch ( Exception $e ) { return null; }
		}

		private static function to_utc_ts( DateTimeImmutable $dt ) {
			return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
		}

		private static function from_utc_ts_local( $ts, DateTimeZone $tz ) {
			try { return ( new DateTimeImmutable( '@' . intval( $ts ) ) )->setTimezone( $tz ); } catch ( Exception $e ) { return null; }
		}

		private static function week_anchor( DateTimeImmutable $dt ) {
			// Monday of the week of $dt (local)
			$w = intval( $dt->format( 'N' ) ); // 1 (Mon) .. 7 (Sun)
			return $dt->modify( '-' . ( $w - 1 ) . ' days' )->setTime( 0, 0, 0 );
		}

		private static function weekday_to_name( $wd ) {
			$wd = strtoupper( trim( (string) $wd ) );
			$map = array(
				'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday',
				'MON' => 'Monday', 'TUE' => 'Tuesday', 'WED' => 'Wednesday', 'THU' => 'Thursday', 'FRI' => 'Friday', 'SAT' => 'Saturday', 'SUN' => 'Sunday',
				'MONDAY' => 'Monday', 'TUESDAY' => 'Tuesday', 'WEDNESDAY' => 'Wednesday', 'THURSDAY' => 'Thursday', 'FRIDAY' => 'Friday', 'SATURDAY' => 'Saturday', 'SUNDAY' => 'Sunday',
			);
			return isset( $map[ $wd ] ) ? $map[ $wd ] : null;
		}

		private static function set_weekday_time( DateTimeImmutable $week_start, $day_name, $hour, $minute, $second ) {
			$target = $week_start;
			if ( 'Monday' !== $day_name ) {
				$target = $target->modify( $day_name . ' this week' );
			}
			return $target->setTime( intval( $hour ), intval( $minute ), intval( $second ) );
		}
	}
}
