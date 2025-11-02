<?php

/**
 * Helper utilities for accessing printer status slide configuration.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Printer_Status_Slide {

	const META_PROVIDER = '_foyer_printer_status_provider';
	const META_DEVICES  = '_foyer_printer_status_devices';
	const META_REFRESH  = '_foyer_printer_status_refresh';

	const DEFAULT_PROVIDER = 'bambulab';
	const DEFAULT_REFRESH  = 45; // seconds

	/**
	 * Returns the configured provider slug for a slide.
	 *
	 * @param int $slide_id Slide post ID.
	 * @return string
	 */
	public static function get_provider( $slide_id ) {
		$provider = get_post_meta( $slide_id, self::META_PROVIDER, true );
		if ( 'bambu' === $provider ) {
			$provider = 'bambulab';
		}
		if ( empty( $provider ) ) {
			$provider = self::DEFAULT_PROVIDER;
		}
		return sanitize_key( $provider );
	}

	/**
	 * Returns printer definitions stored on the slide.
	 *
	 * @param int $slide_id Slide post ID.
	 * @return array
	 */
	public static function get_devices( $slide_id ) {
		$devices = get_post_meta( $slide_id, self::META_DEVICES, true );
		if ( ! is_array( $devices ) ) {
			$devices = array();
		}

		$sanitised = array();
		foreach ( $devices as $device ) {
			if ( ! is_array( $device ) ) {
				continue;
			}
			$meta = isset( $device['meta'] ) && is_array( $device['meta'] ) ? array_map( 'sanitize_text_field', $device['meta'] ) : array();
            $meta = wp_parse_args(
                $meta,
                array(
                    'serial'       => '',
                    'use_tls'      => '1',
                    'mqtt_port'    => '',
                    'status_topic' => '',
                    'mqtt_username'=> '',
                    'mqtt_password'=> '',
                    'scheme'       => 'http',
                    'snapshot_path'=> '/api/v1/cameras/snapshot',
                )
            );

			$sanitised[] = array(
				'name'        => isset( $device['name'] ) ? sanitize_text_field( $device['name'] ) : '',
				'host'        => isset( $device['host'] ) ? sanitize_text_field( $device['host'] ) : '',
				'port'        => isset( $device['port'] ) ? absint( $device['port'] ) : 0,
				'access_code' => isset( $device['access_code'] ) ? sanitize_text_field( $device['access_code'] ) : '',
				'camera_url'  => isset( $device['camera_url'] ) ? esc_url_raw( $device['camera_url'] ) : '',
				'meta'        => $meta,
			);
		}

		return $sanitised;
	}

	/**
	 * Returns the refresh interval in seconds.
	 *
	 * @param int $slide_id Slide post ID.
	 * @return int
	 */
	public static function get_refresh_interval( $slide_id ) {
		$refresh = get_post_meta( $slide_id, self::META_REFRESH, true );
		if ( empty( $refresh ) ) {
			$refresh = self::DEFAULT_REFRESH;
		}
		return max( 15, absint( $refresh ) );
	}

	/**
	 * Bundles configuration for convenience.
	 *
	 * @param int $slide_id Slide post ID.
	 * @return array
	 */
	public static function get_config( $slide_id ) {
		return array(
			'provider' => self::get_provider( $slide_id ),
			'devices'  => self::get_devices( $slide_id ),
			'refresh'  => self::get_refresh_interval( $slide_id ),
		);
	}
}
