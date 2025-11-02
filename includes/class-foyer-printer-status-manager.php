<?php

/**
 * Central manager coordinating printer providers, caching and data retrieval.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Printer_Status_Manager {

	const CACHE_PREFIX = 'foyer_printer_status_';

	/**
	 * Registered providers indexed by slug.
	 *
	 * @var Foyer_Printer_Provider[]
	 */
	protected static $providers = array();

	/**
	 * Bootstraps provider registration hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_default_providers' ), 20 );
		add_action( 'plugins_loaded', array( __CLASS__, 'register_external_providers' ), 30 );
	}

	/**
	 * Registers the built-in providers.
	 *
	 * @return void
	 */
	public static function register_default_providers() {
		// No default providers registered.
	}

	/**
	 * Fires a hook so add-ons can attach their providers.
	 *
	 * @return void
	 */
	public static function register_external_providers() {
		/**
		 * Allows third parties to register printer providers.
		 *
		 * @param callable $register Callback expecting an instance of Foyer_Printer_Provider.
		 */
		do_action( 'foyer/printer/providers/register', array( __CLASS__, 'register_provider' ) );
	}

	/**
	 * Registers a provider instance.
	 *
	 * @param Foyer_Printer_Provider $provider Provider instance.
	 * @return void
	 */
	public static function register_provider( Foyer_Printer_Provider $provider ) {
		self::$providers[ $provider->get_slug() ] = $provider;
	}

	/**
	 * Returns a provider by slug.
	 *
	 * @param string $slug Provider slug.
	 * @return Foyer_Printer_Provider|null
	 */
	public static function get_provider( $slug ) {
		if ( isset( self::$providers[ $slug ] ) ) {
			return self::$providers[ $slug ];
		}
		return null;
	}

	/**
	 * Returns all registered providers.
	 *
	 * @return Foyer_Printer_Provider[]
	 */
	public static function get_providers() {
		return self::$providers;
	}

	/**
	 * Returns choices for select fields.
	 *
	 * @return array slug => label map.
	 */
	public static function get_provider_choices() {
		$choices = array();
		foreach ( self::$providers as $slug => $provider ) {
			$choices[ $slug ] = $provider->get_label();
		}
		return $choices;
	}

	/**
	 * Fetches the status data for a slide utilising caching.
	 *
	 * @param int    $slide_id       Slide post ID.
	 * @param string $provider_slug  Provider slug.
	 * @param array  $printers       Raw printer configuration.
	 * @param int    $cache_ttl      Cache lifetime in seconds.
	 * @param bool   $force_refresh  Force fetching regardless of cache.
	 * @return array|\WP_Error
	 */
	public static function get_status_for_slide( $slide_id, $provider_slug, array $printers, $cache_ttl = 45, $force_refresh = false ) {
		$provider_slug = sanitize_key( $provider_slug );
		$provider = self::get_provider( $provider_slug );
		if ( ! $provider ) {
			return new WP_Error( 'foyer_printer_provider_missing', __( 'Kein passender Provider konfiguriert.', 'foyer' ) );
		}

		$normalised = array();
		foreach ( $printers as $printer ) {
			if ( ! is_array( $printer ) ) {
				continue;
			}
			$normalised[] = $provider->normalize_printer( $printer );
		}

		if ( empty( $normalised ) ) {
			return new WP_Error( 'foyer_printer_no_devices', __( 'Keine Drucker konfiguriert.', 'foyer' ) );
		}

		$config_hash = self::fingerprint_configuration( $provider_slug, $normalised );
		$cache_key   = self::build_cache_key( $slide_id, $config_hash );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$data = $provider->fetch_status( $normalised );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = array(
			'slide_id'     => $slide_id,
			'provider'     => $provider_slug,
			'printers'     => $data,
			'fetched_at'   => time(),
			'cache_ttl'    => absint( $cache_ttl ),
			'config_hash'  => $config_hash,
		);

		set_transient( $cache_key, $result, max( 10, absint( $cache_ttl ) ) );

		return $result;
	}

	/**
	 * Deletes cached status for a slide.
	 *
	 * @param int $slide_id Slide post ID.
	 * @return void
	 */
	public static function clear_cache_for_slide( $slide_id ) {
		global $wpdb;
		$transient_like = '_transient_' . self::CACHE_PREFIX . intval( $slide_id ) . '_%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", str_replace( '_transient_', '_transient_timeout_', $transient_like ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Computes a hash representing the configuration to break cache when it changes.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param array  $printers      Normalised printers.
	 * @return string
	 */
	protected static function fingerprint_configuration( $provider_slug, array $printers ) {
		$parts = array( $provider_slug );
		foreach ( $printers as $printer ) {
			$parts[] = $printer['host'] . ':' . $printer['port'] . '|' . $printer['name'] . '|' . hash( 'sha256', $printer['access_code'] );
		}
		return hash( 'sha256', implode( ';', $parts ) );
	}

	/**
	 * Builds a cache key from slide ID and config hash.
	 *
	 * @param int    $slide_id    Slide post ID.
	 * @param string $config_hash Configuration fingerprint.
	 * @return string
	 */
	protected static function build_cache_key( $slide_id, $config_hash ) {
		return self::CACHE_PREFIX . intval( $slide_id ) . '_' . substr( $config_hash, 0, 24 );
	}
}
