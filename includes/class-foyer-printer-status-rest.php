<?php

/**
 * Registers REST endpoints for printer status slides.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Printer_Status_REST {

	const ROUTE_NAMESPACE = 'foyer/v1';

	/**
	 * Hooks REST registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the printer status route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/printer-status/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'validate_callback' => 'is_numeric',
					),
					'force' => array(
						'validate_callback' => function( $value ) {
							return is_null( $value ) || in_array( strtolower( $value ), array( '1', 'true', 'yes', '0', 'false', 'no' ), true );
						},
					),
				),
			)
		);
	}

	/**
	 * Delivers cached printer status for the requested slide.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get_status( WP_REST_Request $request ) {
		$slide_id = absint( $request->get_param( 'id' ) );

		$slide = get_post( $slide_id );
		if ( ! $slide || Foyer_Slide::post_type_name !== $slide->post_type ) {
			return new WP_Error( 'foyer_printer_invalid_slide', __( 'Folie wurde nicht gefunden.', 'foyer' ), array( 'status' => 404 ) );
		}

		$config = Foyer_Printer_Status_Slide::get_config( $slide_id );
		if ( empty( $config['devices'] ) ) {
			return new WP_Error( 'foyer_printer_no_devices', __( 'Keine Drucker konfiguriert.', 'foyer' ), array( 'status' => 400 ) );
		}

		$force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );

		$data = Foyer_Printer_Status_Manager::get_status_for_slide(
			$slide_id,
			$config['provider'],
			$config['devices'],
			$config['refresh'],
			$force
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return new WP_REST_Response(
			array(
				'meta' => array(
					'slide_id'   => $slide_id,
					'provider'   => $config['provider'],
					'refreshed'  => $data['fetched_at'],
					'expires_in' => $config['refresh'],
				),
				'printers' => $data['printers'],
			),
			200
		);
	}
}
