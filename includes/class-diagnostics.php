<?php
/**
 * Environment diagnostics.
 *
 * What this file does not contain is as deliberate as what it does: there is no
 * "Fix it" button. Companion Auto Update rewrote wp-config.php with
 * file_put_contents(), no backup, no WP_Filesystem, no permission check, against
 * a hard-coded list of eight text variants — and the button that offered it
 * crashed with a fatal error anyway, because a function name had been typed as
 * a variable. A corrupted wp-config.php is a site that is offline.
 *
 * So Update Pilot detects, explains, and hands over the exact line to change.
 * The administrator, or their host, applies it.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inspects the environment for anything that stops updates from happening.
 */
class Update_Pilot_Diagnostics {

	/**
	 * Everything is fine.
	 */
	public const GOOD = 'good';

	/**
	 * Worth knowing, not blocking.
	 */
	public const WARNING = 'warning';

	/**
	 * Updates cannot happen.
	 */
	public const CRITICAL = 'critical';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
	}

	/**
	 * Run every check.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function checks(): array {
		return array(
			self::check_automatic_updater_disabled(),
			self::check_disallow_file_mods(),
			self::check_core_constant(),
			self::check_filter_override(),
			self::check_filesystem(),
			self::check_vcs(),
			self::check_wp_cron(),
			self::check_core_update_schedules(),
			self::check_schedule_error(),
			self::check_multisite(),
		);
	}

	/**
	 * The checks that currently block updates entirely.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function blocking(): array {
		return array_values(
			array_filter( self::checks(), static fn( $check ) => self::CRITICAL === $check['status'] )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Individual checks
	 * ---------------------------------------------------------------------
	 */

	/**
	 * AUTOMATIC_UPDATER_DISABLED.
	 *
	 * @return array<string, string>
	 */
	private static function check_automatic_updater_disabled(): array {
		if ( ! defined( 'AUTOMATIC_UPDATER_DISABLED' ) ) {
			return self::result(
				'automatic_updater_disabled',
				__( 'Automatic updater', 'update-pilot' ),
				self::GOOD,
				__( 'Not disabled by a constant.', 'update-pilot' ),
				''
			);
		}

		if ( ! AUTOMATIC_UPDATER_DISABLED ) {
			return self::result(
				'automatic_updater_disabled',
				__( 'Automatic updater', 'update-pilot' ),
				self::GOOD,
				__( 'AUTOMATIC_UPDATER_DISABLED is defined as false, which allows updates.', 'update-pilot' ),
				''
			);
		}

		return self::result(
			'automatic_updater_disabled',
			__( 'Automatic updater', 'update-pilot' ),
			self::CRITICAL,
			__( 'AUTOMATIC_UPDATER_DISABLED is set in wp-config.php. WordPress will not install any automatic update, whatever this plugin decides.', 'update-pilot' ),
			"define( 'AUTOMATIC_UPDATER_DISABLED', false );"
		);
	}

	/**
	 * DISALLOW_FILE_MODS — the most common blocker on managed hosting.
	 *
	 * @return array<string, string>
	 */
	private static function check_disallow_file_mods(): array {
		if ( ! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS ) {
			return self::result(
				'disallow_file_mods',
				__( 'File modifications', 'update-pilot' ),
				self::GOOD,
				__( 'WordPress is allowed to modify files.', 'update-pilot' ),
				''
			);
		}

		return self::result(
			'disallow_file_mods',
			__( 'File modifications', 'update-pilot' ),
			self::CRITICAL,
			__( 'DISALLOW_FILE_MODS is set in wp-config.php. Nothing can be installed or updated — plugins, themes or core. On managed hosting this is often set by the host, which may run its own update process instead.', 'update-pilot' ),
			"define( 'DISALLOW_FILE_MODS', false );"
		);
	}

	/**
	 * WP_AUTO_UPDATE_CORE, which overrules the core options on this screen.
	 *
	 * @return array<string, string>
	 */
	private static function check_core_constant(): array {
		if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			return self::result(
				'wp_auto_update_core',
				__( 'WordPress core updates', 'update-pilot' ),
				self::GOOD,
				__( 'Controlled by this plugin.', 'update-pilot' ),
				''
			);
		}

		$value = WP_AUTO_UPDATE_CORE;

		if ( is_bool( $value ) ) {
			$readable = $value ? 'true' : 'false';
		} else {
			$readable = "'" . (string) $value . "'";
		}

		return self::result(
			'wp_auto_update_core',
			__( 'WordPress core updates', 'update-pilot' ),
			false === $value ? self::WARNING : self::GOOD,
			sprintf(
				/* translators: %s: the constant's value. */
				__( 'WP_AUTO_UPDATE_CORE is defined as %s in wp-config.php. It takes precedence, so the core options on the Settings tab are shown but have no effect.', 'update-pilot' ),
				$readable
			),
			''
		);
	}

	/**
	 * Another plugin filtering automatic updates off.
	 *
	 * This is the same question core asks itself in
	 * WP_Automatic_Updater::is_disabled(). Companion Auto Update tried to detect
	 * it with doing_filter( 'AUTOMATIC_UPDATER_DISABLED' ), which is meaningless:
	 * that is a constant, not a filter.
	 *
	 * @return array<string, string>
	 */
	private static function check_filter_override(): array {
		/** This filter is documented in wp-admin/includes/class-wp-automatic-updater.php */
		$disabled = apply_filters( 'automatic_updater_disabled', false );

		if ( ! $disabled ) {
			return self::result(
				'automatic_updater_filter',
				__( 'Other plugins', 'update-pilot' ),
				self::GOOD,
				__( 'No other plugin is switching automatic updates off.', 'update-pilot' ),
				''
			);
		}

		return self::result(
			'automatic_updater_filter',
			__( 'Other plugins', 'update-pilot' ),
			self::CRITICAL,
			__( 'Something on this site returns true for the automatic_updater_disabled filter, which switches off all automatic updates. Look for a maintenance or security plugin, or a snippet in the theme.', 'update-pilot' ),
			''
		);
	}

	/**
	 * Whether WordPress can write files without asking for FTP credentials.
	 *
	 * @return array<string, string>
	 */
	private static function check_filesystem(): array {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$method = get_filesystem_method();

		if ( 'direct' === $method ) {
			return self::result(
				'filesystem',
				__( 'File permissions', 'update-pilot' ),
				self::GOOD,
				__( 'WordPress can write to the filesystem directly.', 'update-pilot' ),
				''
			);
		}

		return self::result(
			'filesystem',
			__( 'File permissions', 'update-pilot' ),
			self::CRITICAL,
			sprintf(
				/* translators: %s: filesystem method name, e.g. ftpext. */
				__( 'WordPress would need credentials to write files (method: %s). Unattended updates cannot ask for them, so they will not run. This is usually an ownership problem on the wp-content directory.', 'update-pilot' ),
				$method ? $method : __( 'unavailable', 'update-pilot' )
			),
			''
		);
	}

	/**
	 * A version-controlled install, which core refuses to update.
	 *
	 * @return array<string, string>
	 */
	private static function check_vcs(): array {
		$directories = array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR );
		$markers     = array( '.git', '.svn', '.hg', '.bzr' );

		foreach ( $directories as $directory ) {
			foreach ( $markers as $marker ) {
				if ( is_dir( rtrim( $directory, '/\\' ) . '/' . $marker ) ) {
					return self::result(
						'vcs',
						__( 'Version control', 'update-pilot' ),
						self::WARNING,
						sprintf(
							/* translators: 1: version control directory name, 2: containing directory. */
							__( 'A %1$s directory was found in %2$s. WordPress refuses to update anything inside a checkout, so updates are expected to arrive through your deployment instead.', 'update-pilot' ),
							$marker,
							$directory
						),
						''
					);
				}
			}
		}

		return self::result(
			'vcs',
			__( 'Version control', 'update-pilot' ),
			self::GOOD,
			__( 'No version control checkout detected.', 'update-pilot' ),
			''
		);
	}

	/**
	 * WP-Cron: switched off, or simply running late.
	 *
	 * @return array<string, string>
	 */
	private static function check_wp_cron(): array {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return self::result(
				'wp_cron',
				__( 'Scheduled tasks', 'update-pilot' ),
				self::WARNING,
				__( 'DISABLE_WP_CRON is set, so WordPress does not run scheduled tasks on page views. That is the right setup, provided a real system cron calls wp-cron.php. If nothing calls it, nothing will ever update.', 'update-pilot' ),
				Update_Pilot_Scheduler::system_cron_command()
			);
		}

		$lateness = Update_Pilot_Scheduler::cron_lateness();

		if ( $lateness > HOUR_IN_SECONDS ) {
			return self::result(
				'wp_cron',
				__( 'Scheduled tasks', 'update-pilot' ),
				self::WARNING,
				sprintf(
					/* translators: %s: human readable duration. */
					__( 'The earliest pending task is %s late. WP-Cron only fires when somebody visits the site, so on a quiet site a chosen hour is an intention rather than a guarantee. A system cron makes it exact.', 'update-pilot' ),
					human_time_diff( time() - $lateness, time() )
				),
				Update_Pilot_Scheduler::system_cron_command()
			);
		}

		return self::result(
			'wp_cron',
			__( 'Scheduled tasks', 'update-pilot' ),
			self::GOOD,
			__( 'Scheduled tasks are running on time.', 'update-pilot' ),
			''
		);
	}

	/**
	 * The recurrence of the three core update-check events.
	 *
	 * Update Pilot never touches these — but a plugin that did may be long gone
	 * and still be dictating how often this site looks for new versions.
	 * Companion Auto Update reprogrammed all three, and its deactivation routine
	 * clears only its own hooks: whatever recurrence it imposed outlives it.
	 * A site left on a weekly check will not see a security release for a week,
	 * no matter what any of this plugin's settings say.
	 *
	 * @return array<string, string>
	 */
	private static function check_core_update_schedules(): array {
		$hooks = array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' );

		$schedules = wp_get_schedules();
		$expected  = isset( $schedules['twicedaily']['interval'] )
			? (int) $schedules['twicedaily']['interval']
			: 12 * HOUR_IN_SECONDS;

		$missing   = array();
		$unknown   = array();
		$slower    = array();
		$different = array();
		$worst     = 0;

		foreach ( $hooks as $hook ) {
			$recurrence = wp_get_schedule( $hook );

			if ( false === $recurrence ) {
				$missing[] = $hook;
				continue;
			}

			if ( 'twicedaily' === $recurrence ) {
				continue;
			}

			/*
			 * A recurrence WordPress no longer knows about — a plugin removed it
			 * from cron_schedules after scheduling with it. The event stays in the
			 * cron array and never reschedules itself properly. It has no usable
			 * interval, so it must not be mistaken for a harmless one.
			 */
			if ( ! isset( $schedules[ $recurrence ]['interval'] ) ) {
				$unknown[] = sprintf( '%s (%s)', $hook, $recurrence );
				continue;
			}

			$interval = (int) $schedules[ $recurrence ]['interval'];

			if ( $interval > $expected ) {
				$slower[] = sprintf( '%s (%s)', $hook, $recurrence );
				$worst    = max( $worst, $interval );
			} else {
				$different[] = sprintf( '%s (%s)', $hook, $recurrence );
			}
		}

		// Deleting them is the whole fix: core recreates them at the default
		// recurrence from wp_schedule_update_checks(), on the next page load.
		$remedy = 'wp cron event delete wp_version_check && wp cron event delete wp_update_plugins && wp cron event delete wp_update_themes';

		if ( array() !== $slower ) {
			return self::result(
				'core_update_schedules',
				__( 'Core update checks', 'update-pilot' ),
				self::WARNING,
				sprintf(
					/* translators: 1: comma-separated list of cron hooks and their recurrence, 2: human readable duration. */
					__( 'These update checks run less often than the WordPress default of twice a day: %1$s. A new version can go unnoticed for up to %2$s, whatever this plugin is set to. A plugin that reprogrammed them — Companion Auto Update did — does not restore them when it is removed. Deleting the events lets WordPress recreate them at the default.', 'update-pilot' ),
					implode( ', ', $slower ),
					human_time_diff( time(), time() + $worst )
				),
				$remedy
			);
		}

		if ( array() !== $unknown ) {
			return self::result(
				'core_update_schedules',
				__( 'Core update checks', 'update-pilot' ),
				self::WARNING,
				sprintf(
					/* translators: %s: comma-separated list of cron hooks and their recurrence. */
					__( 'These update checks are scheduled on a recurrence WordPress no longer knows about: %s. The plugin that defined it has probably been removed, and the events cannot reschedule themselves. Deleting them lets WordPress recreate them at the default.', 'update-pilot' ),
					implode( ', ', $unknown )
				),
				$remedy
			);
		}

		if ( array() !== $missing ) {
			return self::result(
				'core_update_schedules',
				__( 'Core update checks', 'update-pilot' ),
				self::WARNING,
				sprintf(
					/* translators: %s: comma-separated list of cron hook names. */
					__( 'These update checks are not scheduled at all: %s. WordPress normally recreates them on the next page load; if this message persists, something is removing them.', 'update-pilot' ),
					implode( ', ', $missing )
				),
				''
			);
		}

		if ( array() !== $different ) {
			return self::result(
				'core_update_schedules',
				__( 'Core update checks', 'update-pilot' ),
				self::GOOD,
				sprintf(
					/* translators: %s: comma-separated list of cron hooks and their recurrence. */
					__( 'Update checks run on a non-default but more frequent recurrence: %s. Nothing is delayed by this.', 'update-pilot' ),
					implode( ', ', $different )
				),
				''
			);
		}

		return self::result(
			'core_update_schedules',
			__( 'Core update checks', 'update-pilot' ),
			self::GOOD,
			__( 'WordPress looks for new versions twice a day, as it does by default.', 'update-pilot' ),
			''
		);
	}

	/**
	 * A scheduling failure we recorded rather than swallowed.
	 *
	 * @return array<string, string>
	 */
	private static function check_schedule_error(): array {
		$error = Update_Pilot_Scheduler::last_error();

		if ( null === $error ) {
			return self::result(
				'schedule',
				__( 'Update Pilot schedule', 'update-pilot' ),
				self::GOOD,
				__( 'The schedule is in place.', 'update-pilot' ),
				''
			);
		}

		return self::result(
			'schedule',
			__( 'Update Pilot schedule', 'update-pilot' ),
			self::WARNING,
			sprintf(
				/* translators: %s: error message. */
				__( 'The last attempt to change the schedule failed: %s The previous schedule is still in place.', 'update-pilot' ),
				(string) ( $error['message'] ?? '' )
			),
			''
		);
	}

	/**
	 * Multisite, which version 1.0 does not manage across a network.
	 *
	 * @return array<string, string>
	 */
	private static function check_multisite(): array {
		if ( ! is_multisite() ) {
			return self::result(
				'multisite',
				__( 'Installation type', 'update-pilot' ),
				self::GOOD,
				__( 'Single site.', 'update-pilot' ),
				''
			);
		}

		return self::result(
			'multisite',
			__( 'Installation type', 'update-pilot' ),
			self::WARNING,
			is_main_site()
				? __( 'This is a multisite network. Update Pilot 1.0 configures the current site only and has no network settings screen. It does not write the network-wide auto-update options, so the Exclusions screen records your choices without changing what the rest of the network does.', 'update-pilot' )
				: __( 'This is a sub-site of a multisite network. WordPress runs automatic updates on the main site only, so the settings on this screen decide nothing for this site. Configure Update Pilot on the main site instead. It deliberately does not write the network-wide auto-update options from here.', 'update-pilot' ),
			''
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Site Health
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Add our test to Site Health.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public static function register_site_health_test( $tests ): array {
		if ( ! is_array( $tests ) ) {
			$tests = array();
		}

		$tests['direct']['update_pilot'] = array(
			'label' => __( 'Automatic updates can run', 'update-pilot' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * The Site Health test itself.
	 *
	 * @return array
	 */
	public static function site_health_test(): array {
		$blocking = self::blocking();

		if ( array() === $blocking ) {
			return array(
				'label'       => __( 'Automatic updates can run on this site', 'update-pilot' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Security', 'update-pilot' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'Update Pilot found nothing in the environment preventing WordPress from installing updates.', 'update-pilot' ) . '</p>',
				'actions'     => sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=update-pilot-status' ) ),
					esc_html__( 'Open Update Pilot status', 'update-pilot' )
				),
				'test'        => 'update_pilot',
			);
		}

		$description = '<p>' . esc_html__( 'Something in this site\'s configuration stops WordPress from installing updates:', 'update-pilot' ) . '</p><ul>';

		foreach ( $blocking as $check ) {
			$description .= '<li><strong>' . esc_html( $check['label'] ) . '</strong> — ' . esc_html( $check['description'] ) . '</li>';
		}

		$description .= '</ul>';

		return array(
			'label'       => __( 'Automatic updates cannot run on this site', 'update-pilot' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'Security', 'update-pilot' ),
				'color' => 'red',
			),
			'description' => $description,
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=update-pilot-status' ) ),
				esc_html__( 'Open Update Pilot status', 'update-pilot' )
			),
			'test'        => 'update_pilot',
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Internals
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Assemble one check result.
	 *
	 * @param string $id          Identifier.
	 * @param string $label       Short label.
	 * @param string $status      One of GOOD, WARNING, CRITICAL.
	 * @param string $description Explanation.
	 * @param string $snippet     A line to copy, when there is one.
	 * @return array<string, string>
	 */
	private static function result( string $id, string $label, string $status, string $description, string $snippet ): array {
		return array(
			'id'          => $id,
			'label'       => $label,
			'status'      => $status,
			'description' => $description,
			'snippet'     => $snippet,
		);
	}
}
