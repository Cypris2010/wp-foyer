<?php

/**
 * Base provider definition for printer data sources.
 *
 * Each manufacturer specific implementation should extend this class
 * and implement the abstract methods for slug, label and status fetching.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
abstract class Foyer_Printer_Provider {

	/**
	 * Returns a unique slug for the provider (eg. bambu).
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Returns a human readable label for the provider.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Normalises a printer configuration array.
	 *
	 * Implementations can override this to inject provider specific defaults or validation.
	 *
	 * Expected shape:
	 * [
	 *   'name'        => (string) Friendly name shown on the slide,
	 *   'host'        => (string) Hostname or IP,
	 *   'port'        => (int)    TCP port,
	 *   'access_code' => (string) Local access credential,
	 *   'camera_url'  => (string) Optional snapshot/stream URL,
	 *   'meta'        => (array)  Provider specific configuration (optional).
	 * ]
	 *
	 * @param array $printer Raw printer configuration.
	 * @return array Normalised configuration.
	 */
	public function normalize_printer( array $printer ) {
		$name  = isset( $printer['name'] ) ? sanitize_text_field( $printer['name'] ) : '';
		$host  = isset( $printer['host'] ) ? sanitize_text_field( $printer['host'] ) : '';
		$port  = isset( $printer['port'] ) ? absint( $printer['port'] ) : $this->get_default_port();
		$code  = isset( $printer['access_code'] ) ? sanitize_text_field( $printer['access_code'] ) : '';
		$camera = isset( $printer['camera_url'] ) ? esc_url_raw( $printer['camera_url'] ) : '';
		$meta   = isset( $printer['meta'] ) && is_array( $printer['meta'] ) ? $printer['meta'] : array();

		if ( empty( $name ) ) {
			$name = $host;
		}

		return array(
			'name'        => $name,
			'host'        => $host,
			'port'        => $port,
			'access_code' => $code,
			'camera_url'  => $camera,
			'meta'        => $meta,
		);
	}

	/**
	 * Returns the default port for the provider.
	 *
	 * @return int
	 */
	public function get_default_port() {
		return 80;
	}

	/**
	 * Fetches the status for the provided printers.
	 *
	 * @param array $printers Array of normalised printer configs.
	 * @return array|\WP_Error Array of printer status payloads or WP_Error on failure.
	 */
	abstract public function fetch_status( array $printers );
}

