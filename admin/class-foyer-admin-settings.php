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
	 * Option name for the numeric datetime format used in admin datepickers.
	 */
	const OPTION_PICKER_FORMAT = 'foyer_picker_datetime_format';

	/**
	 * Option name used to enable scheduler debugging output.
	 */
	const OPTION_SCHEDULER_DEBUG = 'foyer_scheduler_enable_debug';

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

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_PICKER_FORMAT,
			array(
				'type' => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_picker_format' ),
				'default' => 'Y-m-d H:i',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_SCHEDULER_DEBUG,
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

		add_settings_field(
			self::OPTION_PICKER_FORMAT,
			__( 'Admin datetime picker format', 'foyer' ),
			array( __CLASS__, 'render_picker_format_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_general'
		);

		add_settings_field(
			self::OPTION_SCHEDULER_DEBUG,
			__( 'Enable scheduler debug logs', 'foyer' ),
			array( __CLASS__, 'render_scheduler_debug_field' ),
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
	 * Sanitizes the picker format option.
	 *
	 * Ensures a non-empty PHP datetime format string, defaulting to Y-m-d H:i.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_picker_format( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			$value = 'Y-m-d H:i';
		}
		return $value;
	}

	/**
	 * Renders the picker format text field.
	 *
	 * @return void
	 */
	public static function render_picker_format_field() {
		$format = get_option( self::OPTION_PICKER_FORMAT, 'Y-m-d H:i' );
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_PICKER_FORMAT ); ?>" value="<?php echo esc_attr( $format ); ?>" class="regular-text" />
		<p class="description">
			<?php esc_html_e( 'Used for admin date/time pickers. Keep this numeric (e.g. Y-m-d H:i) so values can be parsed reliably.', 'foyer' ); ?>
		</p>
		<?php
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
	 * Renders the scheduler debug checkbox field.
	 *
	 * @return void
	 */
	public static function render_scheduler_debug_field() {
		$enabled = (bool) get_option( self::OPTION_SCHEDULER_DEBUG, 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_SCHEDULER_DEBUG ); ?>" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Output additional scheduler debug information to the browser console.', 'foyer' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Enable only while troubleshooting the scheduler overlay.', 'foyer' ); ?>
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
