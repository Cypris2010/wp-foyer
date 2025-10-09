<?php

/**
 * Lightweight iCalendar (.ics) helper used by the Calendar slide.
 *
 * @since 1.10.0
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_ICS {

	/**
	 * Fetches and parses events from a remote ICS URL with transient caching.
	 *
	 * @since 1.10.0
	 *
	 * @param string $url  ICS feed URL.
	 * @param array  $args Optional arguments (cache_ttl => seconds).
	 * @return array{events:array<int,array>, error:string}
	 */
	public static function get_events( $url, $args = array() ) {
		$url = esc_url_raw( trim( $url ) );
		if ( empty( $url ) ) {
			return array(
				'events' => array(),
				'error' => __( 'No calendar URL provided.', 'foyer' ),
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'cache_ttl' => 30 * MINUTE_IN_SECONDS,
			)
		);
		$cache_ttl = absint( $args['cache_ttl'] );
		if ( $cache_ttl < MINUTE_IN_SECONDS ) {
			$cache_ttl = MINUTE_IN_SECONDS;
		}

		$transient_key = 'foyer_ics_' . md5( $url . '|' . $cache_ttl );
		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			$result = array(
				'events' => array(),
				'error' => $response->get_error_message(),
			);
			set_transient( $transient_key, $result, MINUTE_IN_SECONDS * 5 );
			return $result;
		}

		$body = wp_remote_retrieve_body( $response );
		$body = self::ensure_utf8( $body, wp_remote_retrieve_header( $response, 'content-type' ) );

		$events = self::parse_ics( $body );
		$result = array(
			'events' => $events,
			'error' => '',
		);

		set_transient( $transient_key, $result, $cache_ttl );

		return $result;
	}

	/**
	 * Parses raw ICS data into an array of event arrays.
	 *
	 * @param string $body Raw ICS payload.
	 * @return array<int,array>
	 */
	protected static function parse_ics( $body ) {
		$lines = preg_split( '/\r\n|\n|\r/', (string) $body );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$unfolded = array();
		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue;
			}
			if ( ! empty( $unfolded ) && ( isset( $line[0] ) && ( ' ' === $line[0] || "\t" === $line[0] ) ) ) {
				$unfolded[ count( $unfolded ) - 1 ] .= substr( $line, 1 );
			} else {
				$unfolded[] = $line;
			}
		}

		$events = array();
		$current = array();

		foreach ( $unfolded as $line ) {
			if ( 'BEGIN:VEVENT' === strtoupper( $line ) ) {
				$current = array();
				continue;
			}
			if ( 'END:VEVENT' === strtoupper( $line ) ) {
				$event = self::normalize_event( $current );
				if ( $event ) {
					$events[] = $event;
				}
				$current = array();
				continue;
			}

			list( $name, $params, $value ) = self::parse_property( $line );
			if ( ! $name ) {
				continue;
			}

			$payload = array(
				'value' => $value,
				'params' => $params,
			);

			if ( isset( $current[ $name ] ) ) {
				if ( ! is_array( $current[ $name ] ) || ! array_key_exists( 0, $current[ $name ] ) ) {
					$current[ $name ] = array( $current[ $name ] );
				}
				$current[ $name ][] = $payload;
			} else {
				$current[ $name ] = $payload;
			}
		}

		usort(
			$events,
			static function ( $a, $b ) {
				if ( $a['start_timestamp'] === $b['start_timestamp'] ) {
					return strcmp( $a['summary'], $b['summary'] );
				}
				return ( $a['start_timestamp'] < $b['start_timestamp'] ) ? -1 : 1;
			}
		);

		return $events;
	}

	/**
	 * Normalises a single VEVENT payload into a structured array.
	 *
	 * @param array $payload Raw VEVENT payload.
	 * @return array|null
	 */
	protected static function normalize_event( $payload ) {
		if ( empty( $payload['DTSTART'] ) ) {
			return null;
		}

		$dtstart = self::parse_datetime_payload( $payload['DTSTART'] );
		if ( ! $dtstart ) {
			return null;
		}

		$dtend = null;
		if ( ! empty( $payload['DTEND'] ) ) {
			$dtend = self::parse_datetime_payload( $payload['DTEND'], $dtstart['is_all_day'] );
		}

		if ( ! $dtend ) {
			$dtend = array(
				'timestamp'   => $dtstart['timestamp'],
				'is_all_day'  => $dtstart['is_all_day'],
			);
		}

		if ( $dtstart['is_all_day'] && $dtend['is_all_day'] && $dtend['timestamp'] > $dtstart['timestamp'] ) {
			$dtend['timestamp'] -= 1;
		}

		$summary = self::extract_text_value( $payload, 'SUMMARY' );
		$location = self::extract_text_value( $payload, 'LOCATION' );
		$description = self::extract_text_value( $payload, 'DESCRIPTION' );

		$uid = self::extract_text_value( $payload, 'UID' );
		if ( empty( $uid ) ) {
			$uid = md5( $summary . '|' . $dtstart['timestamp'] . '|' . $dtend['timestamp'] );
		}

		return array(
			'uid' => $uid,
			'summary' => $summary,
			'location' => $location,
			'description' => $description,
			'start_timestamp' => $dtstart['timestamp'],
			'end_timestamp' => $dtend['timestamp'],
			'all_day' => $dtstart['is_all_day'],
		);
	}

	/**
	 * Extracts textual payload, handling unescaping.
	 *
	 * @param array  $payload Event payload map.
	 * @param string $key     Target property.
	 * @return string
	 */
	protected static function extract_text_value( $payload, $key ) {
		if ( empty( $payload[ $key ] ) ) {
			return '';
		}

		$value = $payload[ $key ];
		if ( is_array( $value ) && isset( $value['value'] ) ) {
			$value = $value['value'];
		} elseif ( is_array( $value ) && isset( $value[0]['value'] ) ) {
			$value = $value[0]['value'];
		}

		return self::unescape_text( (string) $value );
	}

	/**
	 * Parses a datetime payload including timezone handling.
	 *
	 * @param array $payload     Datetime payload with value/params.
	 * @param bool  $fallback_all_day When true and parsing fails, treat as all-day.
	 * @return array{timestamp:int,is_all_day:bool}|null
	 */
	protected static function parse_datetime_payload( $payload, $fallback_all_day = false ) {
		if ( isset( $payload['value'] ) ) {
			$value = (string) $payload['value'];
			$params = isset( $payload['params'] ) ? $payload['params'] : array();
		} elseif ( isset( $payload[0]['value'] ) ) {
			$value = (string) $payload[0]['value'];
			$params = isset( $payload[0]['params'] ) ? $payload[0]['params'] : array();
		} else {
			return null;
		}

		$value = trim( $value );
		$params = array_change_key_case( (array) $params, CASE_UPPER );

		$is_all_day = isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] );
		$site_tz = wp_timezone();
		$tz = $site_tz;

		if ( isset( $params['TZID'] ) ) {
			try {
				$tz = new DateTimeZone( $params['TZID'] );
			} catch ( Exception $e ) {
				$tz = $site_tz;
			}
		}

		$value_clean = $value;
		if ( '' !== $value_clean && 'Z' === substr( $value_clean, -1 ) ) {
			$value_clean = substr( $value_clean, 0, -1 );
			$tz = new DateTimeZone( 'UTC' );
		}

		if ( preg_match( '/^\d{8}$/', $value_clean ) || $is_all_day ) {
			$dt = DateTimeImmutable::createFromFormat( 'Ymd', substr( $value_clean, 0, 8 ), $site_tz );
			if ( false === $dt ) {
				return null;
			}
			return array(
				'timestamp' => $dt->setTime( 0, 0, 0 )->getTimestamp(),
				'is_all_day' => true,
			);
		}

		$formats = array( 'Ymd\THis', 'Ymd\THi', 'Ymd\TH' );
		$dt = null;
		foreach ( $formats as $format ) {
			$dt = DateTimeImmutable::createFromFormat( $format, $value_clean, $tz );
			if ( false !== $dt ) {
				break;
			}
		}

		if ( false === $dt ) {
			if ( $fallback_all_day ) {
				return array(
					'timestamp' => ( new DateTimeImmutable( 'now', $site_tz ) )->setTime( 0, 0, 0 )->getTimestamp(),
					'is_all_day' => true,
				);
			}
			return null;
		}

		$dt = $dt->setTimezone( $site_tz );
		return array(
			'timestamp' => $dt->getTimestamp(),
			'is_all_day' => $is_all_day,
		);
	}

	/**
	 * Splits a raw ICS property line into name, params and value.
	 *
	 * @param string $line Raw line.
	 * @return array{0:string,1:array,2:string}
	 */
	protected static function parse_property( $line ) {
		$parts = explode( ':', $line, 2 );
		if ( count( $parts ) < 2 ) {
			return array( '', array(), '' );
		}

		list( $property, $value ) = $parts;
		$property_segments = explode( ';', $property );
		$name = strtoupper( array_shift( $property_segments ) );
		$params = array();
		foreach ( $property_segments as $segment ) {
			if ( false === strpos( $segment, '=' ) ) {
				continue;
			}
			list( $param_name, $param_value ) = explode( '=', $segment, 2 );
			$params[ strtoupper( trim( $param_name ) ) ] = trim( $param_value );
		}

		return array( $name, $params, trim( $value ) );
	}

	/**
	 * Unescapes iCalendar text sequences.
	 *
	 * @param string $text Text to unescape.
	 * @return string
	 */
	protected static function unescape_text( $text ) {
		$search = array( '\\,', '\\;', '\\n', '\\N', '\\' );
		$replace = array( ',', ';', "\n", "\n", '\\' );
		return str_replace( $search, $replace, $text );
	}

	/**
	 * Ensures a string is valid UTF-8, using content type hints when available.
	 *
	 * @param string $string       Raw bytes.
	 * @param string $content_type Optional content type header.
	 * @return string
	 */
	protected static function ensure_utf8( $string, $content_type = '' ) {
		if ( '' === $string ) {
			return '';
		}

		$charset = '';
		if ( $content_type && false !== stripos( $content_type, 'charset=' ) ) {
			$charset = trim( substr( $content_type, stripos( $content_type, 'charset=' ) + 8 ) );
			$charset = trim( $charset, "\"'" );
		}

		if ( '' !== $charset && strcasecmp( $charset, 'utf-8' ) !== 0 && function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $string, 'UTF-8', $charset );
			if ( false !== $converted ) {
				$string = $converted;
			}
		}

		$string = wp_check_invalid_utf8( $string, true );
		return (string) $string;
	}
}
