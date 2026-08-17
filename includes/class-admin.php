<?php
/**
 * The administration screens.
 *
 * Rules applied throughout, each one repairing something found in the audit of
 * Companion Auto Update:
 *
 * - Every write goes through POST, a nonce and a capability check, then
 *   redirects. CAU triggered three database writes from bare GET links.
 * - The capability is manage_update_pilot, the same one the settings offer to
 *   grant. CAU asked for manage_options on the page while offering an
 *   "allow editors" setting that therefore did nothing at all.
 * - Assets load on this plugin's screens only. CAU ignored the $hook argument
 *   and enqueued its stylesheet on every admin page.
 * - Checkbox ids hash the whole identifier. CAU hashed $slug[0] — the first
 *   character of the string — so every plugin starting with the same letter got
 *   the same HTML id, and clicking a label ticked another plugin's box.
 * - Names and descriptions come from third-party headers and are escaped at the
 *   point of output, never before.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu, pages and form handling.
 */
class Update_Pilot_Admin {

	/**
	 * Top-level menu slug.
	 */
	public const MENU = 'update-pilot';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'filter_body_class' ) );

		add_action( 'admin_post_update_pilot_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_update_pilot_save_exclusions', array( __CLASS__, 'handle_save_exclusions' ) );
		add_action( 'admin_post_update_pilot_check_now', array( __CLASS__, 'handle_check_now' ) );
		add_action( 'admin_post_update_pilot_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_post_update_pilot_test_email', array( __CLASS__, 'handle_test_email' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Menu
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Build the menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$capability = Update_Pilot_Settings::CAPABILITY;

		add_menu_page(
			__( 'Update Pilot', 'update-pilot' ),
			__( 'Update Pilot', 'update-pilot' ),
			$capability,
			self::MENU,
			array( __CLASS__, 'render_settings' ),
			'dashicons-update',
			80
		);

		add_submenu_page(
			self::MENU,
			__( 'Settings', 'update-pilot' ),
			__( 'Settings', 'update-pilot' ),
			$capability,
			self::MENU,
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			self::MENU,
			__( 'Exclusions', 'update-pilot' ),
			__( 'Exclusions', 'update-pilot' ),
			$capability,
			'update-pilot-exclusions',
			array( __CLASS__, 'render_exclusions' )
		);

		add_submenu_page(
			self::MENU,
			__( 'Log', 'update-pilot' ),
			__( 'Log', 'update-pilot' ),
			$capability,
			'update-pilot-log',
			array( __CLASS__, 'render_log' )
		);

		add_submenu_page(
			self::MENU,
			__( 'Status', 'update-pilot' ),
			__( 'Status', 'update-pilot' ),
			$capability,
			'update-pilot-status',
			array( __CLASS__, 'render_status' )
		);
	}

	/**
	 * Load our stylesheet and script, on our screens only.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook ): void {
		if ( ! is_string( $hook ) ) {
			return;
		}

		/*
		 * Our own screens, plus the dashboard: the widget carries styles of its
		 * own, and the dashboard's hook is index.php, so matching only on
		 * "update-pilot" left those rules written but never applied.
		 */
		$ours = ( false !== strpos( $hook, 'update-pilot' ) )
			|| ( 'index.php' === $hook && current_user_can( Update_Pilot_Settings::CAPABILITY ) );

		if ( ! $ours ) {
			return;
		}

		wp_enqueue_style( 'update-pilot-admin', UPILOT_URL . 'admin/css/admin.css', array(), UPILOT_VERSION );
		wp_enqueue_script( 'update-pilot-admin', UPILOT_URL . 'admin/js/admin.js', array(), UPILOT_VERSION, true );
	}

	/**
	 * Mark the settings screen only, so its tabs header can bleed edge to
	 * edge across #wpcontent the way Privacy and Site Health do — by zeroing
	 * that element's own left padding instead of fighting it with more and
	 * more negative margins.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function filter_body_class( string $classes ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, identifies the current screen.
		if ( isset( $_GET['page'] ) && self::MENU === $_GET['page'] ) {
			$classes .= ' upilot-settings-screen';
		}

		return $classes;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Form handling
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Refuse anything that is not a legitimate, authorised POST.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'update-pilot' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Send the administrator back where they came from, with a message.
	 *
	 * @param string $page    Page slug.
	 * @param string $message Message code.
	 * @return void
	 */
	private static function redirect( string $page, string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => $page,
					'update-pilot-msg' => $message,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Save the settings form.
	 *
	 * @return void
	 */
	public static function handle_save_settings(): void {
		self::guard( 'update_pilot_save_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$raw = wp_unslash( $_POST );

		$settings = Update_Pilot_Settings::sanitize( (array) $raw, Update_Pilot_Settings::get() );

		Update_Pilot_Settings::save( $settings );
		Update_Pilot_Settings::grant_capability();
		Update_Pilot_Settings::push_to_native();

		$scheduled = Update_Pilot_Scheduler::reschedule();

		self::redirect( self::MENU, is_wp_error( $scheduled ) ? 'saved-schedule-failed' : 'saved' );
	}

	/**
	 * Save the exclusions form.
	 *
	 * The form submits what should update, not what should not: that is the way
	 * the native Plugins screen puts the question, and the exclusion list is
	 * derived from it. One state of truth, expressed the same way in both places.
	 *
	 * @return void
	 */
	public static function handle_save_exclusions(): void {
		self::guard( 'update_pilot_save_exclusions' );

		$settings = Update_Pilot_Settings::get();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$raw = wp_unslash( $_POST );

		$enabled_plugins = Update_Pilot_Settings::sanitize_plugin_list( $raw['auto_plugins'] ?? array() );
		$enabled_themes  = Update_Pilot_Settings::sanitize_theme_list( $raw['auto_themes'] ?? array() );

		$settings['plugins']['excluded'] = array_values(
			array_diff( Update_Pilot_Settings::installed_plugins(), $enabled_plugins )
		);

		$settings['themes']['excluded'] = array_values(
			array_diff( Update_Pilot_Settings::installed_themes(), $enabled_themes )
		);

		Update_Pilot_Settings::save( $settings );
		Update_Pilot_Settings::push_to_native();

		self::redirect( 'update-pilot-exclusions', 'saved' );
	}

	/**
	 * Ask WordPress to look for updates now.
	 *
	 * @return void
	 */
	public static function handle_check_now(): void {
		self::guard( 'update_pilot_check_now' );

		Update_Pilot_Scheduler::force_update_checks();

		Update_Pilot_Compatibility::refresh();

		self::redirect( 'update-pilot-status', 'checked' );
	}

	/**
	 * Send a test message, so mail delivery can be proved before it matters.
	 *
	 * @return void
	 */
	public static function handle_test_email(): void {
		self::guard( 'update_pilot_test_email' );

		$sent = Update_Pilot_Notifier::send_test();

		self::redirect( 'update-pilot-status', $sent ? 'mail-sent' : 'mail-failed' );
	}

	/**
	 * Run the update pass now.
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		self::guard( 'update_pilot_run_now' );

		/*
		 * Reading and changing the policy is one thing; installing code on the
		 * server is another. An administrator may grant manage_update_pilot to an
		 * editor or an author so they can see the log and the schedule — that
		 * should not hand them a button which installs new plugin, theme and core
		 * versions. The unattended updater runs from cron and checks no such
		 * capability itself, so this is the only place to require it.
		 */
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_core' ) ) {
			wp_die(
				esc_html__( 'Running updates requires permission to install updates on this site.', 'update-pilot' ),
				'',
				array( 'response' => 403 )
			);
		}

		Update_Pilot_Scheduler::run();

		self::redirect( 'update-pilot-status', 'ran' );
	}

	/**
	 * Show the message left by a redirect.
	 *
	 * @return void
	 */
	private static function render_message(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker.
		$code = isset( $_GET['update-pilot-msg'] ) ? sanitize_key( wp_unslash( $_GET['update-pilot-msg'] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'saved'                 => array( 'success', __( 'Settings saved.', 'update-pilot' ) ),
			'saved-schedule-failed' => array( 'warning', __( 'Settings saved, but the schedule could not be changed. The previous schedule is still in place — see the Status page.', 'update-pilot' ) ),
			'checked'               => array( 'success', __( 'WordPress has re-checked for updates.', 'update-pilot' ) ),
			'ran'                   => array( 'success', __( 'The update pass has run. Anything it did is in the log.', 'update-pilot' ) ),
			'migrated'              => array( 'success', __( 'Companion Auto Update settings were imported, and its own data was left untouched. Its schedule was copied into the Scheduled run fields but not switched on — check it before enabling it.', 'update-pilot' ) ),
			'mail-sent'             => array( 'success', __( 'A test message was handed to WordPress. If it does not arrive, the problem is in how this site sends mail, not in Update Pilot.', 'update-pilot' ) ),
			'mail-failed'           => array( 'error', __( 'WordPress refused to send the test message. Check the recipients, and whether an SMTP plugin is configured on this site.', 'update-pilot' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * The shared page header.
	 *
	 * @param string $title Page title.
	 * @return void
	 */
	private static function open_page( string $title ): void {
		echo '<div class="wrap update-pilot">';
		echo '<h1>' . esc_html( $title ) . '</h1>';

		self::render_message();

		if ( is_multisite() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html(
					is_main_site()
						? __( 'This is a multisite network. Update Pilot 1.0 manages the current site only, has no network settings screen, and does not write the network-wide auto-update options.', 'update-pilot' )
						: __( 'This is a sub-site of a network. WordPress runs automatic updates on the main site only, so nothing set here will take effect. Configure Update Pilot on the main site.', 'update-pilot' )
				)
				. '</p></div>';
		}
	}

	/**
	 * Close the page wrapper.
	 *
	 * @return void
	 */
	private static function close_page(): void {
		echo '</div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings screen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'update-pilot' ), '', array( 'response' => 403 ) );
		}

		$settings    = Update_Pilot_Settings::get();
		$core_frozen = defined( 'WP_AUTO_UPDATE_CORE' );

		$tabs = array(
			'automatic'     => __( 'What updates automatically', 'update-pilot' ),
			'timing'        => __( 'When updates may run', 'update-pilot' ),
			'delay'         => __( 'Safety delay', 'update-pilot' ),
			'notifications' => __( 'Notifications', 'update-pilot' ),
			'access'        => __( 'Access and data', 'update-pilot' ),
		);

		/*
		 * No .wrap here, deliberately: it's what puts a 10px gap above Privacy
		 * and Site Health too, and both skip it for the same reason. Its
		 * margins are also what the header used to cancel with negative
		 * margins of its own — dropping it means the header can just be
		 * margin: 0 and still bleed edge to edge.
		 */
		echo '<div class="update-pilot upilot-tabbed">';

		self::render_tabs_header( __( 'Update Pilot', 'update-pilot' ), $tabs );

		echo '<hr class="wp-header-end">';
		echo '<div class="upilot-tabs-body">';

		self::render_message();

		if ( is_multisite() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html(
					is_main_site()
						? __( 'This is a multisite network. Update Pilot 1.0 manages the current site only, has no network settings screen, and does not write the network-wide auto-update options.', 'update-pilot' )
						: __( 'This is a sub-site of a network. WordPress runs automatic updates on the main site only, so nothing set here will take effect. Configure Update Pilot on the main site.', 'update-pilot' )
				)
				. '</p></div>';
		}

		echo '<p class="description">'
			. esc_html__( 'WordPress installs the updates. Update Pilot decides which ones are eligible, when they may run, how long a release must wait, and keeps a record of what happened.', 'update-pilot' )
			. '</p>';

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="update_pilot_save_settings">
			<?php wp_nonce_field( 'update_pilot_save_settings' ); ?>

			<div class="upilot-tab-panel" id="upilot-panel-automatic" role="tabpanel" aria-labelledby="upilot-tab-automatic" tabindex="0">

				<?php if ( $core_frozen ) : ?>
					<p class="notice notice-info upilot-inline-notice">
						<?php esc_html_e( 'WP_AUTO_UPDATE_CORE is defined in wp-config.php. It overrides the three core options below, which are shown for reference but have no effect.', 'update-pilot' ); ?>
					</p>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress core', 'update-pilot' ); ?></th>
						<td>
							<fieldset <?php disabled( $core_frozen ); ?>>
								<?php
								self::checkbox( 'core_minor', $settings['core']['minor'], __( 'Minor releases (6.9.1 → 6.9.2). These are almost always security and maintenance fixes.', 'update-pilot' ), $core_frozen );
								self::checkbox( 'core_major', $settings['core']['major'], __( 'Major releases (6.9 → 7.0).', 'update-pilot' ), $core_frozen );
								self::checkbox( 'core_dev', $settings['core']['dev'], __( 'Development builds. For test sites only.', 'update-pilot' ), $core_frozen );
								?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugins and themes', 'update-pilot' ); ?></th>
						<td>
							<?php
							self::checkbox( 'plugins_enabled', $settings['plugins']['enabled'], __( 'Manage plugin auto-updates', 'update-pilot' ) );
							self::checkbox( 'themes_enabled', $settings['themes']['enabled'], __( 'Manage theme auto-updates', 'update-pilot' ) );
							self::checkbox( 'translations', $settings['translations'], __( 'Translation files', 'update-pilot' ) );
							?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the Exclusions screen. */
									esc_html__( 'Choose which ones on the %s screen. When these are switched off, Update Pilot leaves plugin and theme auto-updates exactly as WordPress found them rather than forcing them off.', 'update-pilot' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=update-pilot-exclusions' ) ) . '">' . esc_html__( 'Exclusions', 'update-pilot' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="upilot-tab-panel" id="upilot-panel-timing" role="tabpanel" aria-labelledby="upilot-tab-timing" tabindex="0">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Maintenance window', 'update-pilot' ); ?></th>
						<td>
							<?php self::checkbox( 'window_enabled', $settings['window']['enabled'], __( 'Only allow updates during this window', 'update-pilot' ) ); ?>

							<p class="upilot-subfields">
								<label>
									<?php esc_html_e( 'From', 'update-pilot' ); ?>
									<?php self::hour_select( 'window_start_hour', (int) $settings['window']['start_hour'] ); ?>
								</label>
								<label>
									<?php esc_html_e( 'to', 'update-pilot' ); ?>
									<?php self::hour_select( 'window_end_hour', (int) $settings['window']['end_hour'] ); ?>
								</label>
							</p>

							<fieldset class="upilot-weekdays">
								<legend class="screen-reader-text"><?php esc_html_e( 'Days of the week', 'update-pilot' ); ?></legend>
								<?php self::weekday_checkboxes( (array) $settings['window']['weekdays'] ); ?>
							</fieldset>

							<p class="description">
								<?php esc_html_e( 'This is what actually confines updates to a time range: outside the window, every eligibility check says no, including during the update runs WordPress starts on its own. It applies to the types Update Pilot manages — anything left unmanaged keeps WordPress\'s own behaviour. A window may cross midnight: 23:00 to 02:00 works, and belongs to the day it opened.', 'update-pilot' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scheduled run', 'update-pilot' ); ?></th>
						<td>
							<?php self::checkbox( 'schedule_enabled', $settings['schedule']['enabled'], __( 'Ask WordPress to install updates at a chosen time', 'update-pilot' ) ); ?>

							<p class="upilot-subfields">
								<label>
									<?php esc_html_e( 'At', 'update-pilot' ); ?>
									<?php self::hour_select( 'schedule_hour', (int) $settings['schedule']['hour'] ); ?>
									:
									<?php self::minute_select( 'schedule_minute', (int) $settings['schedule']['minute'] ); ?>
								</label>
								<label>
									<?php esc_html_e( 'Repeating', 'update-pilot' ); ?>
									<select name="schedule_interval">
										<?php foreach ( Update_Pilot_Scheduler::offered_intervals() as $slug ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['schedule']['interval'], $slug ); ?>>
												<?php echo esc_html( Update_Pilot_Scheduler::interval_label( $slug ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>

							<p class="description">
								<?php
								printf(
									/* translators: %s: the site's timezone string. */
									esc_html__( 'Times are in the site timezone (%s). WP-Cron only fires when someone visits the site, so without a real system cron this is an intention rather than a guarantee — the Status page shows how late things actually are.', 'update-pilot' ),
									esc_html( wp_timezone_string() )
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="upilot-tab-panel" id="upilot-panel-delay" role="tabpanel" aria-labelledby="upilot-tab-delay" tabindex="0">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Hold new releases', 'update-pilot' ); ?></th>
						<td>
							<?php self::checkbox( 'delay_enabled', $settings['delay']['enabled'], __( 'Wait before installing a newly released version', 'update-pilot' ) ); ?>

							<p class="upilot-subfields">
								<label>
									<input type="number" name="delay_days" min="1" max="90" step="1"
										value="<?php echo esc_attr( (string) $settings['delay']['days'] ); ?>" class="small-text">
									<?php esc_html_e( 'days after the version first appears', 'update-pilot' ); ?>
								</label>
							</p>

							<fieldset>
								<?php
								$applies = (array) $settings['delay']['applies_to'];

								self::checkbox( 'delay_applies_plugins', in_array( 'plugins', $applies, true ), __( 'Plugins', 'update-pilot' ) );
								self::checkbox( 'delay_applies_themes', in_array( 'themes', $applies, true ), __( 'Themes', 'update-pilot' ) );
								self::checkbox( 'delay_applies_core', in_array( 'core', $applies, true ), __( 'WordPress core — not recommended: minor releases are usually security fixes', 'update-pilot' ) );
								?>
							</fieldset>

							<p class="description">
								<?php esc_html_e( 'The clock starts the first time this site is offered a given version, and it applies to plugins, themes and core alike.', 'update-pilot' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="upilot-tab-panel" id="upilot-panel-notifications" role="tabpanel" aria-labelledby="upilot-tab-notifications" tabindex="0">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send an e-mail when', 'update-pilot' ); ?></th>
						<td>
							<?php
							self::checkbox( 'notify_on_failure', $settings['notify']['on_failure'], __( 'An update fails — the one thing WordPress does not really tell you', 'update-pilot' ) );
							self::checkbox( 'notify_on_success', $settings['notify']['on_success'], __( 'An update is installed', 'update-pilot' ) );
							self::checkbox( 'notify_on_available', $settings['notify']['on_available'], __( 'An update is available but not installed (checked once a day)', 'update-pilot' ) );
							self::checkbox( 'notify_on_untested', $settings['notify']['on_untested'], __( 'A plugin\'s author stops declaring compatibility with recent WordPress releases (sent only when the list changes)', 'update-pilot' ) );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="upilot-recipients"><?php esc_html_e( 'Recipients', 'update-pilot' ); ?></label></th>
						<td>
							<textarea name="recipients" id="upilot-recipients" rows="3" class="large-text code"><?php
								echo esc_textarea( implode( "\n", (array) $settings['recipients'] ) );
							?></textarea>
							<p class="description">
								<?php
								printf(
									/* translators: %s: the site administration e-mail address. */
									esc_html__( 'One address per line. Leave empty to use the site administration address (%s).', 'update-pilot' ),
									esc_html( (string) get_option( 'admin_email' ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Format', 'update-pilot' ); ?></th>
						<td>
							<label><input type="radio" name="mail_format" value="html" <?php checked( $settings['mail_format'], 'html' ); ?>> <?php esc_html_e( 'HTML', 'update-pilot' ); ?></label>
							<label><input type="radio" name="mail_format" value="text" <?php checked( $settings['mail_format'], 'text' ); ?>> <?php esc_html_e( 'Plain text', 'update-pilot' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress e-mails', 'update-pilot' ); ?></th>
						<td>
							<?php self::checkbox( 'suppress_native_mail', $settings['suppress_native_mail'], __( 'Stop WordPress from sending its own update e-mails', 'update-pilot' ) ); ?>
							<p class="description">
								<?php esc_html_e( 'Off by default, so our messages are added to the native ones rather than replacing them. If this plugin ever stops working, the site still gets WordPress\'s own notifications.', 'update-pilot' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="upilot-tab-panel" id="upilot-panel-access" role="tabpanel" aria-labelledby="upilot-tab-access" tabindex="0">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Who may use Update Pilot', 'update-pilot' ); ?></th>
						<td>
							<p><label><input type="checkbox" checked disabled> <?php esc_html_e( 'Administrator (always)', 'update-pilot' ); ?></label></p>
							<?php
							$roles = (array) $settings['access_roles'];

							foreach ( Update_Pilot_Settings::GRANTABLE_ROLES as $role_slug ) {
								$role = get_role( $role_slug );

								if ( ! $role ) {
									continue;
								}

								$names = wp_roles()->get_names();

								printf(
									'<p><label><input type="checkbox" name="access_roles[]" value="%s" %s> %s</label></p>',
									esc_attr( $role_slug ),
									checked( in_array( $role_slug, $roles, true ), true, false ),
									esc_html( translate_user_role( $names[ $role_slug ] ?? $role_slug ) )
								);
							}
							?>
							<p class="description">
								<?php esc_html_e( 'This grants a real capability, and it is the same capability these pages require.', 'update-pilot' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="upilot-retention"><?php esc_html_e( 'Keep log entries for', 'update-pilot' ); ?></label></th>
						<td>
							<input type="number" name="retention_days" id="upilot-retention" min="0" max="3650" step="1"
								value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" class="small-text">
							<?php esc_html_e( 'days (0 keeps everything)', 'update-pilot' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'update-pilot' ); ?></th>
						<td>
							<?php self::checkbox( 'purge_on_uninstall', $settings['purge_on_uninstall'], __( 'Delete all Update Pilot data when the plugin is uninstalled', 'update-pilot' ) ); ?>
							<p class="description">
								<?php esc_html_e( 'Off by default. Deactivating the plugin never deletes anything.', 'update-pilot' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php

		echo '</div>'; // .upilot-tabs-body
		echo '</div>'; // .upilot-tabbed
	}

	/**
	 * The centred title-and-tabs header used on the settings screen, in the
	 * style of Settings > Privacy: one h1, tabs below it, the active one
	 * underlined. Switching tabs is a client-side show/hide of panels within
	 * the same form — every field posts on save regardless of which tab is
	 * showing when the button is pressed.
	 *
	 * @param string $title Page title.
	 * @param array  $tabs  Slug => label.
	 * @return void
	 */
	private static function render_tabs_header( string $title, array $tabs ): void {
		echo '<div class="upilot-tabs-header">';
		echo '<div class="upilot-tabs-title-section"><h1>' . esc_html( $title ) . '</h1></div>';

		echo '<div class="upilot-tabs-nav" role="tablist" aria-label="' . esc_attr__( 'Settings sections', 'update-pilot' ) . '">';

		$first = true;

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<button type="button" class="upilot-tab" id="upilot-tab-%1$s" role="tab" aria-controls="upilot-panel-%1$s" aria-selected="%2$s" tabindex="%3$s">%4$s</button>',
				esc_attr( $slug ),
				$first ? 'true' : 'false',
				$first ? '0' : '-1',
				esc_html( $label )
			);

			$first = false;
		}

		echo '</div>';
		echo '</div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Exclusions screen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the exclusions screen.
	 *
	 * @return void
	 */
	public static function render_exclusions(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'update-pilot' ), '', array( 'response' => 403 ) );
		}

		$settings = Update_Pilot_Settings::get();

		self::open_page( __( 'Exclusions', 'update-pilot' ) );

		echo '<p class="description">'
			. esc_html__( 'Tick what may update on its own. These boxes are the same information as the Auto-updates column on the Plugins and Themes screens, and changing them in either place changes both.', 'update-pilot' )
			. '</p>';

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="update_pilot_save_exclusions">
			<?php wp_nonce_field( 'update_pilot_save_exclusions' ); ?>

			<h2><?php esc_html_e( 'Plugins', 'update-pilot' ); ?></h2>

			<?php if ( ! $settings['plugins']['enabled'] ) : ?>
				<p class="notice notice-info upilot-inline-notice">
					<?php esc_html_e( 'Plugin auto-updates are not managed by Update Pilot at the moment, so these boxes have no effect. Switch it on in Settings.', 'update-pilot' ); ?>
				</p>
			<?php endif; ?>

			<?php self::render_plugin_table( $settings ); ?>

			<h2><?php esc_html_e( 'Themes', 'update-pilot' ); ?></h2>

			<?php if ( ! $settings['themes']['enabled'] ) : ?>
				<p class="notice notice-info upilot-inline-notice">
					<?php esc_html_e( 'Theme auto-updates are not managed by Update Pilot at the moment, so these boxes have no effect. Switch it on in Settings.', 'update-pilot' ); ?>
				</p>
			<?php endif; ?>

			<?php self::render_theme_table( $settings ); ?>

			<?php submit_button(); ?>
		</form>
		<?php

		self::close_page();
	}

	/**
	 * The plugin list.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private static function render_plugin_table( array $settings ): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins  = get_plugins();
		$excluded = (array) $settings['plugins']['excluded'];

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<td class="manage-column check-column"></td>';
		echo '<th scope="col">' . esc_html__( 'Plugin', 'update-pilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Installed', 'update-pilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Update', 'update-pilot' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $plugins as $file => $data ) {
			// The whole identifier is hashed, not its first character.
			$id = 'upilot-plugin-' . md5( (string) $file );

			echo '<tr>';
			printf(
				'<th scope="row" class="check-column"><input type="checkbox" id="%1$s" name="auto_plugins[]" value="%2$s" %3$s></th>',
				esc_attr( $id ),
				esc_attr( (string) $file ),
				checked( ! in_array( $file, $excluded, true ), true, false )
			);
			printf(
				'<td><label for="%1$s"><strong>%2$s</strong></label><br><span class="upilot-muted">%3$s</span></td>',
				esc_attr( $id ),
				esc_html( (string) ( $data['Name'] ?? $file ) ),
				esc_html( (string) $file )
			);
			printf( '<td>%s</td>', esc_html( (string) ( $data['Version'] ?? '' ) ) );
			printf( '<td>%s</td>', wp_kses_post( self::pending_note( 'plugin', (string) $file ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The theme list.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private static function render_theme_table( array $settings ): void {
		$themes   = wp_get_themes();
		$excluded = (array) $settings['themes']['excluded'];

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<td class="manage-column check-column"></td>';
		echo '<th scope="col">' . esc_html__( 'Theme', 'update-pilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Installed', 'update-pilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Update', 'update-pilot' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $themes as $stylesheet => $theme ) {
			$id = 'upilot-theme-' . md5( (string) $stylesheet );

			echo '<tr>';
			printf(
				'<th scope="row" class="check-column"><input type="checkbox" id="%1$s" name="auto_themes[]" value="%2$s" %3$s></th>',
				esc_attr( $id ),
				esc_attr( (string) $stylesheet ),
				checked( ! in_array( $stylesheet, $excluded, true ), true, false )
			);
			printf(
				'<td><label for="%1$s"><strong>%2$s</strong></label><br><span class="upilot-muted">%3$s</span></td>',
				esc_attr( $id ),
				esc_html( (string) $theme->get( 'Name' ) ),
				esc_html( (string) $stylesheet )
			);
			printf( '<td>%s</td>', esc_html( (string) $theme->get( 'Version' ) ) );
			printf( '<td>%s</td>', wp_kses_post( self::pending_note( 'theme', (string) $stylesheet ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * What the policy would say about an item right now.
	 *
	 * This is the engine explaining itself: the same evaluation that runs during
	 * an update, rendered as a sentence.
	 *
	 * @param string $type Item type.
	 * @param string $id   Identifier.
	 * @return string
	 */
	private static function pending_note( string $type, string $id ): string {
		$transient = get_site_transient( 'plugin' === $type ? 'update_plugins' : 'update_themes' );

		if ( ! is_object( $transient ) || empty( $transient->response[ $id ] ) ) {
			return '<span class="upilot-muted">' . esc_html__( 'Up to date', 'update-pilot' ) . '</span>';
		}

		$offer   = $transient->response[ $id ];
		$offer   = is_array( $offer ) ? (object) $offer : $offer;
		$version = isset( $offer->new_version ) ? (string) $offer->new_version : '';

		$item = Update_Pilot_Policy_Filters::normalise( $type, $offer );

		$verdict = Update_Pilot_Policy::evaluate( $item, Update_Pilot_Settings::get(), Update_Pilot_Policy_Filters::now() );

		$label = sprintf(
			/* translators: %s: version number. */
			esc_html__( '%s available', 'update-pilot' ),
			esc_html( $version )
		);

		switch ( $verdict['reason'] ) {
			case 'excluded':
				return $label . '<br><span class="upilot-muted">' . esc_html__( 'excluded', 'update-pilot' ) . '</span>';

			case 'outside_window':
				return $label . '<br><span class="upilot-muted">' . esc_html__( 'waiting for the maintenance window', 'update-pilot' ) . '</span>';

			case 'delayed':
				$expiry = Update_Pilot_Policy::delay_expires_at( (int) $item['first_seen'], Update_Pilot_Settings::get() );

				return $label . '<br><span class="upilot-muted">' . sprintf(
					/* translators: %s: date and time. */
					esc_html__( 'held until %s', 'update-pilot' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiry ) )
				) . '</span>';

			case 'delay_pending_first_sighting':
				return $label . '<br><span class="upilot-muted">' . esc_html__( 'held, delay starting', 'update-pilot' ) . '</span>';

			case 'unmanaged':
				return $label . '<br><span class="upilot-muted">' . esc_html__( 'left to WordPress', 'update-pilot' ) . '</span>';

			default:
				return $label . '<br><span class="upilot-muted">' . esc_html__( 'eligible', 'update-pilot' ) . '</span>';
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Log screen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the log.
	 *
	 * @return void
	 */
	public static function render_log(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'update-pilot' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		$result = Update_Pilot_Log_Repository::query(
			array(
				'type'     => $type,
				'status'   => $status,
				'search'   => $search,
				'paged'    => $paged,
				'per_page' => 25,
			)
		);

		self::open_page( __( 'Update log', 'update-pilot' ) );

		echo '<p class="description">'
			. esc_html__( 'Every entry comes from WordPress reporting an update it actually performed, with the version before and after. Nothing here is reconstructed from file dates.', 'update-pilot' )
			. '</p>';

		?>
		<form method="get" class="upilot-filters">
			<input type="hidden" name="page" value="update-pilot-log">

			<select name="type">
				<option value=""><?php esc_html_e( 'All types', 'update-pilot' ); ?></option>
				<?php foreach ( Update_Pilot_Log_Repository::TYPES as $slug ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>>
						<?php echo esc_html( Update_Pilot_Log_Repository::type_label( $slug ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="status">
				<option value=""><?php esc_html_e( 'All outcomes', 'update-pilot' ); ?></option>
				<?php foreach ( Update_Pilot_Log_Repository::STATUSES as $slug ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>>
						<?php echo esc_html( Update_Pilot_Log_Repository::status_label( $slug ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search', 'update-pilot' ); ?>">

			<?php submit_button( __( 'Filter', 'update-pilot' ), 'secondary', '', false ); ?>
		</form>

		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'update-pilot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'update-pilot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Versions', 'update-pilot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Trigger', 'update-pilot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outcome', 'update-pilot' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( array() === $result['items'] ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet.', 'update-pilot' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $result['items'] as $row ) : ?>
					<?php
					// Stored in UTC, rendered in the site's timezone. Always sortable.
					$timestamp = strtotime( (string) $row['occurred_at'] . ' UTC' );
					?>
					<tr>
						<td>
							<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp ) ); ?>
						</td>
						<td>
							<strong><?php echo esc_html( (string) $row['name'] ); ?></strong><br>
							<span class="upilot-muted">
								<?php echo esc_html( Update_Pilot_Log_Repository::type_label( (string) $row['type'] ) ); ?>
								· <?php echo esc_html( (string) $row['item'] ); ?>
							</span>
						</td>
						<td>
							<?php
							$versions = self::version_range( $row );

							echo '' === $versions ? '—' : esc_html( $versions );
							?>
						</td>
						<td><?php echo esc_html( Update_Pilot_Log_Repository::source_label( (string) $row['trigger_source'] ) ); ?></td>
						<td>
							<span class="upilot-status upilot-status-<?php echo esc_attr( (string) $row['status'] ); ?>">
								<?php echo esc_html( Update_Pilot_Log_Repository::status_label( (string) $row['status'] ) ); ?>
							</span>
							<?php if ( ! empty( $row['message'] ) ) : ?>
								<br><span class="upilot-muted"><?php echo esc_html( (string) $row['message'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $result['pages'] > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'total'     => $result['pages'],
							'current'   => $paged,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
		<?php

		self::close_page();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Status screen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the status screen.
	 *
	 * @return void
	 */
	public static function render_status(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'update-pilot' ), '', array( 'response' => 403 ) );
		}

		self::open_page( __( 'Status', 'update-pilot' ) );

		$next     = Update_Pilot_Scheduler::next_run();
		$last     = Update_Pilot_Scheduler::last_run();
		$lateness = Update_Pilot_Scheduler::cron_lateness();
		$counts   = Update_Pilot_Log_Repository::status_counts( 30 );
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<h2>' . esc_html__( 'Schedule', 'update-pilot' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		self::status_row(
			__( 'Next scheduled run', 'update-pilot' ),
			$next ? wp_date( $format, $next ) : __( 'No scheduled run — Update Pilot is not asking for one, so WordPress updates on its own timing.', 'update-pilot' )
		);

		self::status_row(
			__( 'Last run', 'update-pilot' ),
			$last ? wp_date( $format, $last ) : __( 'Never', 'update-pilot' )
		);

		self::status_row(
			__( 'WP-Cron', 'update-pilot' ),
			$lateness > 0
				? sprintf(
					/* translators: %s: human readable duration. */
					__( 'Running %s behind schedule', 'update-pilot' ),
					human_time_diff( time() - $lateness, time() )
				)
				: __( 'On time', 'update-pilot' )
		);

		self::status_row( __( 'Site timezone', 'update-pilot' ), wp_timezone_string() );

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Environment', 'update-pilot' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( Update_Pilot_Diagnostics::checks() as $check ) {
			echo '<tr>';
			printf(
				'<th scope="row" style="width:220px;"><span class="upilot-dot upilot-dot-%1$s"></span> %2$s</th>',
				esc_attr( $check['status'] ),
				esc_html( $check['label'] )
			);
			echo '<td>' . esc_html( $check['description'] );

			if ( '' !== $check['snippet'] ) {
				echo '<br><code class="upilot-snippet">' . esc_html( $check['snippet'] ) . '</code>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		self::render_compatibility_section();

		echo '<h2>' . esc_html__( 'Last 30 days', 'update-pilot' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		self::status_row( __( 'Updates installed', 'update-pilot' ), (string) $counts['success'] );
		self::status_row( __( 'Updates that failed', 'update-pilot' ), (string) $counts['failed'] );
		self::status_row( __( 'Rolled back', 'update-pilot' ), (string) $counts['rolled_back'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Actions', 'update-pilot' ) . '</h2>';
		?>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="update_pilot_check_now">
				<?php wp_nonce_field( 'update_pilot_check_now' ); ?>
				<?php submit_button( __( 'Check for updates now', 'update-pilot' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="update_pilot_run_now">
				<?php wp_nonce_field( 'update_pilot_run_now' ); ?>
				<?php submit_button( __( 'Run an update pass now', 'update-pilot' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="update_pilot_test_email">
				<?php wp_nonce_field( 'update_pilot_test_email' ); ?>
				<?php submit_button( __( 'Send a test e-mail', 'update-pilot' ), 'secondary', '', false ); ?>
			</form>
		</p>
		<p class="description">
			<?php esc_html_e( 'An update pass runs in this request and can take a while on a slow connection. It obeys the same rules as a scheduled run, including the maintenance window.', 'update-pilot' ); ?>
		</p>
		<?php

		self::close_page();
	}

	/**
	 * Plugins whose author has stopped declaring WordPress compatibility.
	 *
	 * Only the noteworthy rows are listed. A table of forty plugins that are all
	 * fine is not information.
	 *
	 * @return void
	 */
	private static function render_compatibility_section(): void {
		echo '<h2>' . esc_html__( 'Plugin compatibility', 'update-pilot' ) . '</h2>';

		if ( ! Update_Pilot_Compatibility::has_report() ) {
			echo '<p class="description">'
				. esc_html__( 'No check has run yet. It happens once a day, or immediately with the button below.', 'update-pilot' )
				. '</p>';

			return;
		}

		$report  = Update_Pilot_Compatibility::report();
		$counts  = Update_Pilot_Compatibility::summary( $report );
		$notable = Update_Pilot_Compatibility::noteworthy( $report );

		echo '<p class="description">';
		echo esc_html(
			sprintf(
				/* translators: 1: number of plugins, 2: number of plugins, 3: number of plugins, 4: number of plugins. */
				__( '%1$d up to date, %2$d behind, %3$d with nothing declared, %4$d not hosted on wordpress.org.', 'update-pilot' ),
				(int) $counts[ Update_Pilot_Compatibility::CURRENT ],
				(int) $counts[ Update_Pilot_Compatibility::BEHIND ],
				(int) $counts[ Update_Pilot_Compatibility::UNDECLARED ],
				(int) $counts[ Update_Pilot_Compatibility::NOT_HOSTED ]
			)
		);
		echo '<br>';
		echo esc_html__( 'This reads what each author declares, not what actually works. A stale declaration means nobody may be maintaining the plugin — which is what matters the day a vulnerability is found in it.', 'update-pilot' );
		echo '</p>';

		if ( array() === $notable ) {
			echo '<p>' . esc_html__( 'Every plugin is declared compatible with a recent release.', 'update-pilot' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Plugin', 'update-pilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Installed', 'update-pilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Declared compatibility', 'update-pilot' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $notable as $row ) {
			$dot = ( Update_Pilot_Compatibility::BEHIND === $row['status'] )
				? Update_Pilot_Diagnostics::WARNING
				: Update_Pilot_Diagnostics::GOOD;

			echo '<tr>';
			printf(
				'<td><span class="upilot-dot upilot-dot-%1$s"></span> <strong>%2$s</strong><br><span class="upilot-muted">%3$s</span></td>',
				esc_attr( $dot ),
				esc_html( (string) $row['name'] ),
				esc_html( (string) $row['item'] )
			);
			printf( '<td>%s</td>', esc_html( (string) $row['version'] ) );
			printf( '<td>%s</td>', esc_html( Update_Pilot_Compatibility::describe( $row ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Describe what a log row's versions did, as plain text.
	 *
	 * Shared by the log table and the dashboard widget so the two cannot drift
	 * apart. Returns unescaped text; callers escape at the point of output.
	 *
	 * @param array $row Log row.
	 * @return string Empty when nothing is known.
	 */
	private static function version_range( array $row ): string {
		$from = $row['from_version'] ?? null;
		$to   = $row['to_version'] ?? null;

		if ( $from && $to ) {
			return $from . ' → ' . $to;
		}

		if ( $to ) {
			return (string) $to;
		}

		if ( $from ) {
			return sprintf(
				/* translators: %s: version number. */
				__( 'still on %s', 'update-pilot' ),
				$from
			);
		}

		return '';
	}

	/**
	 * One row of a status table.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function status_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:220px;">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Dashboard widget
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Add the dashboard widget.
	 *
	 * @return void
	 */
	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'update_pilot_widget',
			__( 'Update Pilot', 'update-pilot' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * The widget body.
	 *
	 * @return void
	 */
	public static function render_dashboard_widget(): void {
		$events = Update_Pilot_Log_Repository::recent( 7 );
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No updates recorded yet.', 'update-pilot' ) . '</p>';
		} else {
			echo '<ul class="upilot-widget-list">';

			foreach ( $events as $row ) {
				$timestamp = strtotime( (string) $row['occurred_at'] . ' UTC' );

				// "Hello Dolly · Plugin · 1.6 → 1.7.2" — the type tells a theme
				// from an extension at a glance, and the pair of versions says
				// what actually changed rather than only where it landed.
				$details = array_filter(
					array(
						Update_Pilot_Log_Repository::type_label( (string) $row['type'] ),
						self::version_range( $row ),
					)
				);

				printf(
					'<li><span class="upilot-status upilot-status-%1$s"></span> <strong>%2$s</strong>%3$s<br><span class="upilot-muted">%4$s</span></li>',
					esc_attr( (string) $row['status'] ),
					esc_html( (string) $row['name'] ),
					$details ? ' · ' . esc_html( implode( ' · ', $details ) ) : '',
					esc_html( wp_date( $format, (int) $timestamp ) )
				);
			}

			echo '</ul>';
		}

		$next = Update_Pilot_Scheduler::next_run();

		if ( $next ) {
			echo '<p class="upilot-muted">' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Next scheduled run: %s', 'update-pilot' ),
					wp_date( $format, $next )
				)
			) . '</p>';
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=update-pilot-log' ) ),
			esc_html__( 'See the full log', 'update-pilot' )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Small form helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A labelled checkbox.
	 *
	 * @param string $name     Field name.
	 * @param bool   $checked  Whether it is ticked.
	 * @param string $label    Label text.
	 * @param bool   $disabled Whether it is inert.
	 * @return void
	 */
	private static function checkbox( string $name, bool $checked, string $label, bool $disabled = false ): void {
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s %3$s> %4$s</label></p>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			disabled( $disabled, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * An hour picker.
	 *
	 * @param string $name  Field name.
	 * @param int    $value Selected hour.
	 * @return void
	 */
	private static function hour_select( string $name, int $value ): void {
		echo '<select name="' . esc_attr( $name ) . '">';

		for ( $hour = 0; $hour <= 23; $hour++ ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $hour,
				selected( $value, $hour, false ),
				esc_html( sprintf( '%02d:00', $hour ) )
			);
		}

		echo '</select>';
	}

	/**
	 * A minute picker, in five-minute steps.
	 *
	 * @param string $name  Field name.
	 * @param int    $value Selected minute.
	 * @return void
	 */
	private static function minute_select( string $name, int $value ): void {
		echo '<select name="' . esc_attr( $name ) . '">';

		for ( $minute = 0; $minute <= 55; $minute += 5 ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $minute,
				selected( $value, $minute, false ),
				esc_html( sprintf( '%02d', $minute ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Weekday checkboxes, in the locale's own order and wording.
	 *
	 * @param array $selected Selected weekday numbers.
	 * @return void
	 */
	private static function weekday_checkboxes( array $selected ): void {
		global $wp_locale;

		$selected   = array_map( 'intval', $selected );
		$start      = (int) get_option( 'start_of_week', 1 );

		for ( $offset = 0; $offset < 7; $offset++ ) {
			$day = ( $start + $offset ) % 7;

			$name = ( $wp_locale instanceof WP_Locale )
				? $wp_locale->get_weekday( $day )
				: (string) $day;

			printf(
				'<label class="upilot-weekday"><input type="checkbox" name="window_weekdays[]" value="%1$d" %2$s> %3$s</label>',
				(int) $day,
				checked( in_array( $day, $selected, true ), true, false ),
				esc_html( $name )
			);
		}
	}
}
