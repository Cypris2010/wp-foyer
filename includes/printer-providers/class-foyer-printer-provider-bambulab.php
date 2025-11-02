<?php

/**
 * Bambu Lab printer provider using the local MQTT interface.
 *
 * Connects to the printer's LAN MQTT broker, subscribes to the status topic
 * and maps the latest payload to the generic printer payload used by Foyer.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Printer_Provider_Bambulab extends Foyer_Printer_Provider {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'bambulab';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return __( 'Bambu Lab (LAN MQTT)', 'foyer' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function normalize_printer( array $printer ) {
		$normalised = parent::normalize_printer( $printer );

		$meta = isset( $normalised['meta'] ) && is_array( $normalised['meta'] ) ? $normalised['meta'] : array();
		$meta = wp_parse_args(
			$meta,
			array(
				'serial'        => '',
				'use_tls'       => '1',
				'mqtt_port'     => '',
				'status_topic'  => '',
				'mqtt_username' => '',
				'mqtt_password' => '',
				'scheme'        => 'http',
				'snapshot_path' => '/api/v1/cameras/snapshot',
			)
		);

		$normalised['meta'] = $meta;

		if ( empty( $normalised['port'] ) ) {
			$normalised['port'] = $this->get_default_port();
		}

		return $normalised;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_default_port() {
		return 80;
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch_status( array $printers ) {
		$results = array();

		foreach ( $printers as $printer ) {
			$results[] = $this->fetch_single_printer( $printer );
		}

		return $results;
	}

	/**
	 * Fetches and maps the status for a single printer.
	 *
	 * @param array $printer Normalised printer configuration.
	 * @return array
	 */
	protected function fetch_single_printer( array $printer ) {
		$status = array(
			'host'          => isset( $printer['host'] ) ? $printer['host'] : '',
			'name'          => isset( $printer['name'] ) ? $printer['name'] : '',
			'status'        => 'offline',
			'progress'      => 0,
			'job_name'      => '',
			'eta_human'     => '',
			'nozzle_temp'   => null,
			'bed_temp'      => null,
			'camera_stream' => isset( $printer['camera_url'] ) ? $printer['camera_url'] : '',
			'snapshot_url'  => $this->build_snapshot_url( $printer ),
			'error'         => '',
		);

		$meta = isset( $printer['meta'] ) && is_array( $printer['meta'] ) ? $printer['meta'] : array();

		if ( empty( $status['host'] ) ) {
			$status['error'] = __( 'Keine Host-Adresse konfiguriert.', 'foyer' );
			return $status;
		}

		if ( empty( $meta['serial'] ) ) {
			$status['error'] = __( 'Seriennummer fehlt.', 'foyer' );
			return $status;
		}

		$payload = $this->request_status_payload( $printer );

		if ( is_wp_error( $payload ) ) {
			$status['error'] = $payload->get_error_message();
			return $status;
		}

		$mapped = $this->map_payload_to_status( $printer, $payload );

		return array_merge( $status, $mapped );
	}

	/**
	 * Resolves the MQTT topic for a printer.
	 *
	 * @param array $printer Printer configuration.
	 * @return string
	 */
	protected function get_status_topic( array $printer ) {
		$meta = isset( $printer['meta'] ) ? $printer['meta'] : array();
		$topic = isset( $meta['status_topic'] ) ? trim( $meta['status_topic'] ) : '';
		if ( ! empty( $topic ) ) {
			return $topic;
		}

		$serial = isset( $meta['serial'] ) ? preg_replace( '/\s+/', '', $meta['serial'] ) : '';
		return sprintf( 'device/%s/report', $serial );
	}

	/**
	 * Returns the MQTT connection parameters.
	 *
	 * @param array $printer Printer configuration.
	 * @return array
	 */
	protected function build_mqtt_connection_args( array $printer ) {
		$meta     = isset( $printer['meta'] ) ? $printer['meta'] : array();
		$host     = isset( $printer['host'] ) ? $printer['host'] : '';
		$use_tls  = isset( $meta['use_tls'] ) ? ( '0' !== $meta['use_tls'] ) : true;
		$mqtt_port = isset( $meta['mqtt_port'] ) ? absint( $meta['mqtt_port'] ) : 0;
		if ( empty( $mqtt_port ) ) {
			$mqtt_port = $use_tls ? 8883 : 1883;
		}

		$username = isset( $meta['mqtt_username'] ) ? $meta['mqtt_username'] : '';
		$password = isset( $meta['mqtt_password'] ) ? $meta['mqtt_password'] : '';

		if ( empty( $username ) && ! empty( $meta['serial'] ) ) {
			$username = $meta['serial'];
		}

		if ( empty( $password ) && ! empty( $printer['access_code'] ) ) {
			$password = $printer['access_code'];
		}

		return array(
			'host'     => $host,
			'port'     => $mqtt_port,
			'use_tls'  => $use_tls,
			'username' => $username,
			'password' => $password,
		);
	}

	/**
	 * Connects to the printer's MQTT broker and retrieves the latest status payload.
	 *
	 * @param array $printer Normalised printer configuration.
	 * @return array|\WP_Error
	 */
	protected function request_status_payload( array $printer ) {
		$params = $this->build_mqtt_connection_args( $printer );
		$topic  = $this->get_status_topic( $printer );

		$transport = $params['use_tls'] ? 'tls' : 'tcp';
		$remote    = sprintf( '%s://%s:%d', $transport, $params['host'], $params['port'] );

		$context = stream_context_create();

		if ( $params['use_tls'] ) {
			stream_context_set_option( $context, 'ssl', 'verify_peer', false );
			stream_context_set_option( $context, 'ssl', 'verify_peer_name', false );
			stream_context_set_option( $context, 'ssl', 'allow_self_signed', true );
		}

		$errno  = 0;
		$errstr = '';
		$socket = @stream_socket_client( $remote, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context );

		if ( false === $socket ) {
			return new WP_Error(
				'foyer_bambu_connection_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'MQTT-Verbindung fehlgeschlagen: %s', 'foyer' ),
					$errstr ? $errstr : __( 'unbekannter Fehler', 'foyer' )
				)
			);
		}

		stream_set_timeout( $socket, 2 );
		stream_set_blocking( $socket, true );

		try {
			$client_id = preg_replace( '/[^a-z0-9]/i', '', wp_generate_password( 8, false, false ) );
			$client_id = strtolower( $client_id );
			if ( strlen( $client_id ) < 4 ) {
				$client_id = (string) wp_rand( 1000, 9999 );
			}
			$client_id = 'foyer_' . $client_id;

			$this->send_connect_packet( $socket, $client_id, $params['username'], $params['password'] );

			$connack = $this->read_packet( $socket );
			if ( false === $connack || 0x20 !== ( $connack['type'] & 0xF0 ) ) {
				throw new RuntimeException( __( 'Keine gültige MQTT-CONNACK-Antwort erhalten.', 'foyer' ) );
			}
			$return_code = isset( $connack['payload'][1] ) ? ord( $connack['payload'][1] ) : 0;
			if ( 0 !== $return_code ) {
				throw new RuntimeException( sprintf( __( 'MQTT-Anmeldung abgelehnt (Code %d).', 'foyer' ), $return_code ) );
			}

			$this->send_subscribe_packet( $socket, $topic );

			$deadline = microtime( true ) + 4;

			while ( microtime( true ) < $deadline ) {
				$packet = $this->read_packet( $socket );

				if ( false === $packet ) {
					continue;
				}

				$type = $packet['type'] & 0xF0;

				if ( 0x30 === $type ) {
					$publish = $this->decode_publish_packet( $packet );
					if ( $publish && $publish['topic'] === $topic ) {
						$message = $publish['message'];
						if ( empty( $message ) ) {
							throw new RuntimeException( __( 'Leere MQTT-Nachricht empfangen.', 'foyer' ) );
						}

						$data = json_decode( $message, true );
						if ( null === $data ) {
							throw new RuntimeException( __( 'MQTT-Payload konnte nicht als JSON gelesen werden.', 'foyer' ) );
						}

						return $data;
					}
				}
			}

			throw new RuntimeException( __( 'Keine Statusmeldung innerhalb des Zeitlimits empfangen.', 'foyer' ) );

		} catch ( Exception $exception ) {
			return new WP_Error( 'foyer_bambu_mqtt_error', $exception->getMessage() );

		} finally {
			fclose( $socket );
		}
	}

	/**
	 * Sends a MQTT CONNECT packet.
	 *
	 * @param resource $socket   MQTT socket.
	 * @param string   $client_id Client identifier.
	 * @param string   $username Username (optional).
	 * @param string   $password Password (optional).
	 * @return void
	 * @throws RuntimeException When sending fails.
	 */
	protected function send_connect_packet( $socket, $client_id, $username, $password ) {
		$protocol_name  = $this->encode_string( 'MQTT' );
		$protocol_level = chr( 0x04 ); // MQTT 3.1.1.
		$connect_flags  = chr( $this->build_connect_flags( $username, $password ) );
		$keep_alive     = pack( 'n', 15 );

		$payload = $protocol_name . $protocol_level . $connect_flags . $keep_alive . $this->encode_string( $client_id );

		if ( '' !== $username ) {
			$payload .= $this->encode_string( $username );
		}

		if ( '' !== $password ) {
			$payload .= $this->encode_string( $password );
		}

		$this->write_packet( $socket, chr( 0x10 ), $payload );
	}

	/**
	 * Sends a MQTT SUBSCRIBE packet for the status topic.
	 *
	 * @param resource $socket MQTT socket.
	 * @param string   $topic  Topic to subscribe.
	 * @return void
	 * @throws RuntimeException When sending fails.
	 */
	protected function send_subscribe_packet( $socket, $topic ) {
		$message_id = random_int( 1, 0xFFFE );
		$payload    = pack( 'n', $message_id ) . $this->encode_string( $topic ) . chr( 0x00 ); // QoS 0.
		$this->write_packet( $socket, chr( 0x82 ), $payload );
	}

	/**
	 * Builds the connect flags byte.
	 *
	 * @param string $username Username.
	 * @param string $password Password.
	 * @return int
	 */
	protected function build_connect_flags( $username, $password ) {
		$flags = 0x02; // Clean session.

		if ( '' !== $username ) {
			$flags |= 0x80;
		}

		if ( '' !== $password ) {
			$flags |= 0x40;
		}

		return $flags;
	}

	/**
	 * Writes a MQTT packet to the socket.
	 *
	 * @param resource $socket MQTT socket resource.
	 * @param string   $header Fixed header byte.
	 * @param string   $payload Payload bytes.
	 * @return void
	 * @throws RuntimeException When sending fails.
	 */
	protected function write_packet( $socket, $header, $payload ) {
		$remaining_length = $this->encode_remaining_length( strlen( $payload ) );
		$packet           = $header . $remaining_length . $payload;

		$total_length = strlen( $packet );
		$written      = 0;

		while ( $written < $total_length ) {
			$chunk = fwrite( $socket, substr( $packet, $written ) );

			if ( false === $chunk ) {
				throw new RuntimeException( __( 'MQTT-Paket konnte nicht gesendet werden.', 'foyer' ) );
			}

			if ( 0 === $chunk ) {
				$meta = stream_get_meta_data( $socket );
				if ( ! empty( $meta['timed_out'] ) ) {
					throw new RuntimeException( __( 'Timeout beim Senden eines MQTT-Pakets.', 'foyer' ) );
				}
				continue;
			}

			$written += $chunk;
		}
	}

	/**
	 * Reads a MQTT packet from the socket.
	 *
	 * @param resource $socket MQTT socket resource.
	 * @return array|false
	 */
	protected function read_packet( $socket ) {
		$header = $this->read_bytes( $socket, 1 );
		if ( false === $header ) {
			return false;
		}

		$remaining_length = $this->decode_remaining_length( $socket );
		if ( false === $remaining_length ) {
			return false;
		}

		$payload = $this->read_bytes( $socket, $remaining_length );
		if ( false === $payload ) {
			return false;
		}

		return array(
			'type'    => ord( $header ),
			'payload' => $payload,
		);
	}

	/**
	 * Decodes a MQTT PUBLISH packet to topic and payload message.
	 *
	 * @param array $packet Packet data including type and payload.
	 * @return array|false
	 */
	protected function decode_publish_packet( $packet ) {
		if ( ! isset( $packet['payload'] ) ) {
			return false;
		}

		$payload = $packet['payload'];

		if ( strlen( $payload ) < 2 ) {
			return false;
		}

		$topic_length = unpack( 'n', substr( $payload, 0, 2 ) );
		$topic_length = isset( $topic_length[1] ) ? intval( $topic_length[1] ) : 0;

		if ( $topic_length <= 0 || strlen( $payload ) < $topic_length + 2 ) {
			return false;
		}

		$topic  = substr( $payload, 2, $topic_length );
		$offset = 2 + $topic_length;

		$qos = ( $packet['type'] & 0x06 ) >> 1;

		if ( $qos > 0 ) {
			if ( strlen( $payload ) < $offset + 2 ) {
				return false;
			}
			$offset += 2;
		}

		if ( strlen( $payload ) < $offset ) {
			return false;
		}

		$message = substr( $payload, $offset );

		return array(
			'topic'   => $topic,
			'message' => $message,
		);
	}

	/**
	 * Encodes a string with MQTT's length-prefixed format.
	 *
	 * @param string $string Raw string.
	 * @return string
	 */
	protected function encode_string( $string ) {
		return pack( 'n', strlen( $string ) ) . $string;
	}

	/**
	 * Encodes the remaining length field.
	 *
	 * @param int $length Remaining bytes.
	 * @return string
	 */
	protected function encode_remaining_length( $length ) {
		$encoded = '';
		do {
			$digit = $length % 128;
			$length = intdiv( $length, 128 );
			if ( $length > 0 ) {
				$digit |= 0x80;
			}
			$encoded .= chr( $digit );
		} while ( $length > 0 );
		return $encoded;
	}

	/**
	 * Decodes the remaining length field from the socket.
	 *
	 * @param resource $socket MQTT socket resource.
	 * @return int|false
	 */
	protected function decode_remaining_length( $socket ) {
		$multiplier = 1;
		$value      = 0;
		$loops      = 0;

		do {
			$encoded = $this->read_bytes( $socket, 1 );
			if ( false === $encoded ) {
				return false;
			}

			$digit = ord( $encoded );
			$value += ( $digit & 0x7F ) * $multiplier;
			$multiplier *= 128;
			$loops ++;
		} while ( ( $digit & 0x80 ) !== 0 && $loops < 4 );

		if ( $loops >= 4 && ( $digit & 0x80 ) !== 0 ) {
			return false;
		}

		return $value;
	}

	/**
	 * Reads a precise number of bytes from the socket with timeout handling.
	 *
	 * @param resource $socket MQTT socket resource.
	 * @param int      $length Number of bytes to read.
	 * @return string|false
	 */
	protected function read_bytes( $socket, $length ) {
		$data = '';

		while ( strlen( $data ) < $length ) {
			$chunk = fread( $socket, $length - strlen( $data ) );

			if ( false === $chunk ) {
				return false;
			}

			if ( '' === $chunk ) {
				$meta = stream_get_meta_data( $socket );

				if ( ! empty( $meta['timed_out'] ) || ! empty( $meta['eof'] ) ) {
					return false;
				}

				continue;
			}

			$data .= $chunk;
		}

		return $data;
	}

	/**
	 * Maps the decoded payload to the slide's expected structure.
	 *
	 * @param array $printer Printer configuration.
	 * @param array $payload Decoded MQTT payload.
	 * @return array
	 */
	protected function map_payload_to_status( array $printer, array $payload ) {
		$print   = isset( $payload['print'] ) && is_array( $payload['print'] ) ? $payload['print'] : array();
		$overall = isset( $payload['overall'] ) && is_array( $payload['overall'] ) ? $payload['overall'] : array();

		$status = $this->extract_status( $print, $overall, $payload );
		$job    = $this->extract_job_name( $print, $payload );
		$progress = $this->extract_progress( $print, $payload );

		$eta_seconds = $this->extract_eta_seconds( $print, $overall, $payload );
		$eta_human   = $this->format_eta( $eta_seconds );

		$nozzle_temp = $this->extract_temperature( $print, array( 'nozzle_temp_current', 'nozzle_temp_act', 'nozzle_temper_current', 'nozzle_temper', 'nozzle_temp' ) );
		$bed_temp    = $this->extract_temperature( $print, array( 'bed_temp_current', 'bed_temp_act', 'bed_temper_current', 'bed_temper', 'bed_temp' ) );

		$error = $this->extract_error_message( $print, $overall, $payload );

		$result = array(
			'status'      => $status,
			'job_name'    => $job,
			'progress'    => $progress,
			'eta_human'   => $eta_human,
			'nozzle_temp' => $nozzle_temp,
			'bed_temp'    => $bed_temp,
			'error'       => $error,
		);

		if ( empty( $printer['camera_url'] ) && isset( $payload['camera'] ) && is_string( $payload['camera'] ) ) {
			$result['camera_stream'] = esc_url_raw( $payload['camera'] );
		}

		if ( isset( $payload['snapshot'] ) && is_string( $payload['snapshot'] ) ) {
			$result['snapshot_url'] = esc_url_raw( $payload['snapshot'] );
		}

		return $result;
	}

	/**
	 * Extracts the printer status string.
	 *
	 * @param array $print   Payload "print" section.
	 * @param array $overall Payload "overall" section.
	 * @param array $payload Entire payload.
	 * @return string
	 */
	protected function extract_status( array $print, array $overall, array $payload ) {
		$candidates = array();

		if ( isset( $overall['state'] ) && is_string( $overall['state'] ) ) {
			$candidates[] = $overall['state'];
		}

		if ( isset( $print['display_status'] ) && is_string( $print['display_status'] ) ) {
			$candidates[] = $print['display_status'];
		}

		if ( isset( $print['gcode_state'] ) ) {
			$candidates[] = $this->map_state_code( $print['gcode_state'] );
		}

		if ( isset( $payload['event'] ) && is_string( $payload['event'] ) ) {
			$candidates[] = $payload['event'];
		}

		foreach ( $candidates as $candidate ) {
			$candidate = strtolower( trim( $candidate ) );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return 'unknown';
	}

	/**
	 * Maps known numeric state codes to strings.
	 *
	 * @param int|string $code State code.
	 * @return string
	 */
	protected function map_state_code( $code ) {
		$code = is_numeric( $code ) ? intval( $code ) : $code;
		$map  = array(
			0 => 'idle',
			1 => 'running',
			2 => 'paused',
			3 => 'completed',
			4 => 'failed',
		);

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		return is_string( $code ) ? strtolower( $code ) : 'unknown';
	}

	/**
	 * Extracts the job or file name.
	 *
	 * @param array $print   Payload "print" section.
	 * @param array $payload Entire payload.
	 * @return string
	 */
	protected function extract_job_name( array $print, array $payload ) {
		$keys = array( 'subtask_name', 'gcode_name', 'task_name', 'job_name', 'plate_name', 'gcode_file' );

		foreach ( $keys as $key ) {
			if ( isset( $print[ $key ] ) && is_string( $print[ $key ] ) && '' !== trim( $print[ $key ] ) ) {
				return $print[ $key ];
			}
		}

		if ( isset( $payload['job'] ) && is_string( $payload['job'] ) ) {
			return $payload['job'];
		}

		return '';
	}

	/**
	 * Extracts the progress percentage.
	 *
	 * @param array $print   Payload "print" section.
	 * @param array $payload Entire payload.
	 * @return float
	 */
	protected function extract_progress( array $print, array $payload ) {
		$keys = array( 'gcode_progress', 'subtask_percent', 'percent', 'progress', 'mc_percent' );

		foreach ( $keys as $key ) {
			if ( isset( $print[ $key ] ) && is_numeric( $print[ $key ] ) ) {
				return $this->clamp_percentage( floatval( $print[ $key ] ) );
			}
		}

		if ( isset( $payload['progress'] ) && is_numeric( $payload['progress'] ) ) {
			return $this->clamp_percentage( floatval( $payload['progress'] ) );
		}

		return 0.0;
	}

	/**
	 * Clamps a numeric percentage into [0, 100].
	 *
	 * @param float $value Input value.
	 * @return float
	 */
	protected function clamp_percentage( $value ) {
		if ( $value < 0 ) {
			return 0.0;
		}
		if ( $value > 100 ) {
			return 100.0;
		}
		return $value;
	}

	/**
	 * Extracts the remaining printing time in seconds.
	 *
	 * @param array $print   Payload "print" section.
	 * @param array $overall Payload "overall" section.
	 * @param array $payload Entire payload.
	 * @return int|null
	 */
	protected function extract_eta_seconds( array $print, array $overall, array $payload ) {
		$keys = array( 'mc_remaining_time', 'remain_time', 'time_remaining', 'eta_seconds' );

		foreach ( $keys as $key ) {
			if ( isset( $print[ $key ] ) && is_numeric( $print[ $key ] ) ) {
				return absint( $print[ $key ] );
			}
		}

		if ( isset( $overall['time_remaining'] ) && is_numeric( $overall['time_remaining'] ) ) {
			return absint( $overall['time_remaining'] );
		}

		if ( isset( $payload['eta'] ) && is_numeric( $payload['eta'] ) ) {
			return absint( $payload['eta'] );
		}

		return null;
	}

	/**
	 * Formats the ETA for display.
	 *
	 * @param int|null $seconds Remaining seconds.
	 * @return string
	 */
	protected function format_eta( $seconds ) {
		if ( null === $seconds ) {
			return '';
		}

		$seconds = absint( $seconds );

		if ( $seconds <= 0 ) {
			return '';
		}

		if ( $seconds >= HOUR_IN_SECONDS ) {
			$hours   = floor( $seconds / HOUR_IN_SECONDS );
			$minutes = floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

			return sprintf(
				/* translators: 1: hours, 2: minutes */
				__( '%1$dh %2$dmin', 'foyer' ),
				$hours,
				$minutes
			);
		}

		if ( $seconds >= MINUTE_IN_SECONDS ) {
			$minutes = max( 1, floor( $seconds / MINUTE_IN_SECONDS ) );
			return sprintf(
				/* translators: %d: minutes */
				_n( '%d Minute', '%d Minuten', $minutes, 'foyer' ),
				$minutes
			);
		}

		return sprintf(
			/* translators: %d: seconds */
			_n( '%d Sekunde', '%d Sekunden', $seconds, 'foyer' ),
			$seconds
		);
	}

	/**
	 * Extracts a temperature value from the payload.
	 *
	 * @param array $section Payload section to inspect.
	 * @param array $keys    Ordered list of keys to check.
	 * @return float|null
	 */
	protected function extract_temperature( array $section, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $section[ $key ] ) && is_numeric( $section[ $key ] ) ) {
				return floatval( $section[ $key ] );
			}
		}

		return null;
	}

	/**
	 * Extracts an error message, if present.
	 *
	 * @param array $print   Payload "print" section.
	 * @param array $overall Payload "overall" section.
	 * @param array $payload Entire payload.
	 * @return string
	 */
	protected function extract_error_message( array $print, array $overall, array $payload ) {
		if ( isset( $overall['error'] ) && is_string( $overall['error'] ) && '' !== trim( $overall['error'] ) ) {
			return $overall['error'];
		}

		if ( isset( $print['print_error'] ) && $print['print_error'] ) {
			if ( is_numeric( $print['print_error'] ) ) {
				return sprintf(
					/* translators: %d: error code */
					__( 'Fehlercode %d', 'foyer' ),
					intval( $print['print_error'] )
				);
			}
			if ( is_string( $print['print_error'] ) ) {
				return $print['print_error'];
			}
		}

		if ( isset( $payload['error'] ) && is_string( $payload['error'] ) ) {
			return $payload['error'];
		}

		return '';
	}

	/**
	 * Builds the snapshot URL if the user did not configure a dedicated camera stream.
	 *
	 * @param array $printer Printer configuration.
	 * @return string
	 */
	protected function build_snapshot_url( array $printer ) {
		if ( ! empty( $printer['camera_url'] ) ) {
			return '';
		}

		$meta          = isset( $printer['meta'] ) ? $printer['meta'] : array();
		$snapshot_path = isset( $meta['snapshot_path'] ) ? trim( $meta['snapshot_path'] ) : '';
		$scheme        = isset( $meta['scheme'] ) ? strtolower( $meta['scheme'] ) : 'http';
		$host          = isset( $printer['host'] ) ? $printer['host'] : '';

		if ( empty( $snapshot_path ) || empty( $host ) ) {
			return '';
		}

		if ( '/' !== substr( $snapshot_path, 0, 1 ) ) {
			$snapshot_path = '/' . $snapshot_path;
		}

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$scheme = 'http';
		}

		$url = $scheme . '://' . $host . $snapshot_path;

		return esc_url_raw( $url );
	}
}
