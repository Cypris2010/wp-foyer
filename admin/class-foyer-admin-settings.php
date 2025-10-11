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
	 * Option name used to enable redirect to Displays after login.
	 */
	const OPTION_LOGIN_REDIRECT = 'foyer_login_redirect_to_displays';

	/**
	 * Options to hide core admin menu items for non-admin users.
	 */
	const OPTION_HIDE_MENU_DASHBOARD  = 'foyer_hide_menu_dashboard';
	const OPTION_HIDE_MENU_POSTS      = 'foyer_hide_menu_posts';
	const OPTION_HIDE_MENU_MEDIA      = 'foyer_hide_menu_media';
	const OPTION_HIDE_MENU_PAGES      = 'foyer_hide_menu_pages';
	const OPTION_HIDE_MENU_COMMENTS   = 'foyer_hide_menu_comments';
	const OPTION_HIDE_MENU_APPEARANCE = 'foyer_hide_menu_appearance';
	const OPTION_HIDE_MENU_PLUGINS    = 'foyer_hide_menu_plugins';
	const OPTION_HIDE_MENU_USERS      = 'foyer_hide_menu_users';
	const OPTION_HIDE_MENU_TOOLS      = 'foyer_hide_menu_tools';
	const OPTION_HIDE_MENU_SETTINGS   = 'foyer_hide_menu_settings';

	/**
	 * Option to place the Foyer menu at the top of the admin menu.
	 */
	const OPTION_MENU_TOP = 'foyer_menu_top';

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

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_LOGIN_REDIRECT,
			array(
				'type' => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				'default' => 0,
			)
		);

		// Register options to hide core admin menu items for non-admin users.
		$bool_opts = array(
			self::OPTION_HIDE_MENU_DASHBOARD,
			self::OPTION_HIDE_MENU_POSTS,
			self::OPTION_HIDE_MENU_MEDIA,
			self::OPTION_HIDE_MENU_PAGES,
			self::OPTION_HIDE_MENU_COMMENTS,
			self::OPTION_HIDE_MENU_APPEARANCE,
			self::OPTION_HIDE_MENU_PLUGINS,
			self::OPTION_HIDE_MENU_USERS,
			self::OPTION_HIDE_MENU_TOOLS,
			self::OPTION_HIDE_MENU_SETTINGS,
		);

		foreach ( $bool_opts as $opt ) {
			register_setting(
				self::OPTION_GROUP,
				$opt,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
					'default'           => 0,
				)
			);
		}

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_MENU_TOP,
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

		add_settings_field(
			self::OPTION_LOGIN_REDIRECT,
			__( 'Redirect after login to Displays', 'foyer' ),
			array( __CLASS__, 'render_login_redirect_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_general'
		);

		add_settings_section(
			'foyer_settings_admin_menu',
			__( 'Admin menu visibility (non-admins)', 'foyer' ),
			'__return_false',
			self::SETTINGS_PAGE
		);

		add_settings_field(
			self::OPTION_MENU_TOP,
			__( 'Place Foyer menu at top', 'foyer' ),
			array( __CLASS__, 'render_menu_top_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu'
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_DASHBOARD,
			__( 'Hide Dashboard', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_DASHBOARD )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_POSTS,
			__( 'Hide Posts', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_POSTS )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_MEDIA,
			__( 'Hide Media', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_MEDIA )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_PAGES,
			__( 'Hide Pages', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_PAGES )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_COMMENTS,
			__( 'Hide Comments', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_COMMENTS )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_APPEARANCE,
			__( 'Hide Appearance', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_APPEARANCE )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_PLUGINS,
			__( 'Hide Plugins', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_PLUGINS )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_USERS,
			__( 'Hide Users', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_USERS )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_TOOLS,
			__( 'Hide Tools', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_TOOLS )
		);

		add_settings_field(
			self::OPTION_HIDE_MENU_SETTINGS,
			__( 'Hide Settings', 'foyer' ),
			array( __CLASS__, 'render_menu_visibility_field' ),
			self::SETTINGS_PAGE,
			'foyer_settings_admin_menu',
			array( 'option' => self::OPTION_HIDE_MENU_SETTINGS )
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
	 * Renders the login redirect checkbox field.
	 *
	 * @return void
	 */
	public static function render_login_redirect_field() {
		$enabled = (bool) get_option( self::OPTION_LOGIN_REDIRECT, 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_LOGIN_REDIRECT ); ?>" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'After successful login, redirect users to the Foyer Displays admin screen instead of the Dashboard.', 'foyer' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Respects redirect_to when it points to a non-admin URL. Users without sufficient permissions will be redirected to their profile page.', 'foyer' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders a generic checkbox for menu visibility options.
	 *
	 * @param array $args {
	 *   @type string $option Option name to read/write.
	 * }
	 * @return void
	 */
	public static function render_menu_top_field() {
		$enabled = (bool) get_option( self::OPTION_MENU_TOP, 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_MENU_TOP ); ?>" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Move Foyer to the top of the admin menu', 'foyer' ); ?>
		</label>
		<?php
	}

	public static function render_menu_visibility_field( $args ) {
		$option = isset( $args['option'] ) ? (string) $args['option'] : '';
		if ( '' === $option ) {
			return;
		}
		$enabled = (bool) get_option( $option, 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $option ); ?>" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Hide for non-admin users', 'foyer' ); ?>
		</label>
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
