<?php

/**
 * Provides a simple settings screen for global plugin options.
 *
 * @since	1.9.1
 *
 * @package	Foyer
 * @subpackage	Foyer/admin
 */
class Foyer_Admin_Settings {

	/**
	 * Menu slug used when registering the settings page.
	 */
	const MENU_SLUG = 'foyer_settings';

	/**
	 * Settings API page identifier used for sections/fields.
	 */
	const SETTINGS_PAGE = 'foyer-settings';

	/**
	 * Option name used to toggle background zoom globally.
	 */
	const OPTION_BACKGROUND_ZOOM = 'foyer_enable_background_zoom';

	/**
	 * Settings API option group identifier.
	 */
	const OPTION_GROUP = 'foyer_settings';

	/**
	 * Adds the settings submenu below the Foyer menu entry.
	 *
	 * @since	1.9.1
	 * @return	void
	 */
	public static function add_menu() {
		add_submenu_page(
			'foyer',
			__( 'Foyer settings', 'foyer' ),
			__( 'Settings', 'foyer' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registers the available plugin settings.
	 *
	 * @since	1.9.1
	 * @return	void
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_BACKGROUND_ZOOM,
			array(
				'type' => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				'default' => 0,
			)
		);

		add_settings_section(
			'foyer_settings_general',
			__( 'General', 'foyer' ),
			'__return_false',
			self::SETTINGS_PAGE
		);

		add_settings_field(
			self::OPTION_BACKGROUND_ZOOM,
			__( 'Zoom image backgrounds', 'foyer' ),
			array( __CLASS__, 'render_background_zoom_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_general'
		);
	}

	/**
	 * Sanitizes checkbox values.
	 *
	 * @since	1.9.1
	 * @param	mixed	$value	The raw value.
	 * @return	int	1 or 0 depending on the checkbox state.
	 */
	public static function sanitize_checkbox( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Renders the checkbox field for the background zoom option.
	 *
	 * @since	1.9.1
	 * @return	void
	 */
	public static function render_background_zoom_field() {
		$enabled = (bool) get_option( self::OPTION_BACKGROUND_ZOOM, 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_BACKGROUND_ZOOM ); ?>" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Animate image backgrounds with a gentle zoom effect, similar to the RSS slide.', 'foyer' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'You can still override this per slide when editing a slide background.', 'foyer' ); ?>
		</p>
		<?php
	}

	/**
	 * Outputs the settings page markup.
	 *
	 * @since	1.9.1
	 * @return	void
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Foyer settings', 'foyer' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::SETTINGS_PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Redirects old slug requests to the current menu slug.
	 */
	public static function maybe_redirect_legacy_slug() {
		if ( isset( $_GET['page'] ) && 'foyer-settings' === $_GET['page'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}
	}
}
