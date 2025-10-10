<?php

/**
 * Handles login redirect behavior based on Foyer plugin settings.
 *
 * This class registers the login_redirect filter and, when enabled via the
 * settings option, redirects users to the Foyer Displays admin screen after
 * a successful login. If the user lacks sufficient permissions, they are
 * redirected to their profile page instead. Existing redirect_to parameters
 * pointing to non-admin URLs are respected.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Login {

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 10, 3 );
	}

	/**
	 * Filter the login redirect URL when the feature is enabled.
	 *
	 * @param string           $redirect_to            Final redirect URL WordPress is about to use.
	 * @param string           $requested_redirect_to  Original redirect_to request parameter.
	 * @param WP_User|WP_Error $user                   Authenticated user or error.
	 * @return string
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		// Feature toggle via settings.
		if ( ! get_option( 'foyer_login_redirect_to_displays', 0 ) ) {
			return $redirect_to;
		}

		// Only handle successful logins.
		if ( empty( $user ) || is_wp_error( $user ) ) {
			return $redirect_to;
		}

		// Respect redirect_to if it doesn't point to the admin area.
		if ( ! empty( $requested_redirect_to ) ) {
			$points_to_admin = ( false !== strpos( $requested_redirect_to, 'wp-admin' ) );
			if ( ! $points_to_admin ) {
				return $redirect_to;
			}
		}

		// Determine target based on capabilities.
		if ( user_can( $user, 'edit_posts' ) ) {
			return admin_url( 'edit.php?post_type=foyer_display' );
		}

		return admin_url( 'profile.php' );
	}
}
