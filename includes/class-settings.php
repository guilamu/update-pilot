<?php
/**
 * Settings and state storage.
 *
 * Two options, no tables. Companion Auto Update stored roughly twenty-five
 * booleans in a key/value table and read them one SQL query at a time, which is
 * where its fifty-queries-per-page came from. A single autoloaded option is
 * fetched once per request and cached by the object cache for free.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and writes the plugin configuration.
 */
class Update_Pilot_Settings {

	/**
	 * Autoloaded option holding the configuration.
	 */
	public const OPTION = 'update_pilot_settings';

	/**
	 * Non-autoloaded option holding runtime state (first_seen dates, last run).
	 */
	public const STATE_OPTION = 'update_pilot_state';

	/**
	 * Capability required to see and change anything in this plugin.
	 */
	public const CAPABILITY = 'manage_update_pilot';

	/**
	 * Schema version. Bump when the option shape or the log table changes.
	 */
	public const SCHEMA = 1;

	/**
	 * Roles that may be granted the capability, beyond administrator.
	 */
	public const GRANTABLE_ROLES = array( 'editor', 'author' );

	/**
	 * Memoised settings for the current request.
	 *
	 * @var array|null
	 */
	private static ?array $cache = null;

	/**
	 * Guard against the native option and our own option updating each other in
	 * a loop.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		/*
		 * Keep our exclusion lists and the native per-item auto-update option in
		 * agreement, in both directions. Core toggles these through
		 * update_site_option(); other tools sometimes call update_option()
		 * directly, so both hook families are covered. The handlers re-read the
		 * option rather than trusting the arguments, which keeps them immune to
		 * the differing hook signatures.
		 */
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$option = 'auto_update_' . $type . 's';

			add_action( "update_site_option_{$option}", array( __CLASS__, 'on_native_option_changed' ), 10, 0 );
			add_action( "add_site_option_{$option}", array( __CLASS__, 'on_native_option_changed' ), 10, 0 );
			add_action( "update_option_{$option}", array( __CLASS__, 'on_native_option_changed' ), 10, 0 );
			add_action( "add_option_{$option}", array( __CLASS__, 'on_native_option_changed' ), 10, 0 );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading and writing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Get the full configuration, with defaults filled in.
	 *
	 * @return array
	 */
	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = self::merge_defaults( $stored, self::defaults() );

		return self::$cache;
	}

	/**
	 * Persist a full configuration array.
	 *
	 * The caller is expected to have run it through sanitize() first.
	 *
	 * @param array $settings Configuration.
	 * @return void
	 */
	public static function save( array $settings ): void {
		$settings['schema'] = self::SCHEMA;

		update_option( self::OPTION, $settings, true );

		self::$cache = null;
	}

	/**
	 * Read the runtime state.
	 *
	 * @return array
	 */
	public static function get_state(): array {
		$state = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array_merge(
			array(
				'first_seen' => array(),
				'last_run'   => 0,
				'last_error' => null,
			),
			$state
		);
	}

	/**
	 * Persist the runtime state.
	 *
	 * Never autoloaded: first_seen grows with the number of installed extensions
	 * and has no business being on every front-end request.
	 *
	 * @param array $state State.
	 * @return void
	 */
	public static function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Defaults
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The shape of the configuration, with neutral values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'schema'             => self::SCHEMA,

			// What may update.
			'core'               => array(
				'minor' => true,
				'major' => false,
				'dev'   => false,
			),
			'plugins'            => array(
				'enabled'  => false,
				'excluded' => array(),
			),
			'themes'             => array(
				'enabled'  => false,
				'excluded' => array(),
			),
			'translations'       => true,

			/*
			 * When our own event fires and asks WordPress to run its updates.
			 * Disabled by default: switching it on changes when a site updates,
			 * and that is the administrator's decision to make, not ours.
			 */
			'schedule'           => array(
				'enabled'  => false,
				'hour'     => 3,
				'minute'   => 0,
				'interval' => 'daily',
			),

			/*
			 * The maintenance window. Outside it, every eligibility filter says
			 * no — including for the update events WordPress runs on its own
			 * schedule. This is what actually confines updates to a time range;
			 * the schedule above only decides when we ask for a run.
			 */
			'window'             => array(
				'enabled'    => false,
				'start_hour' => 2,
				'end_hour'   => 5,
				'weekdays'   => array( 0, 1, 2, 3, 4, 5, 6 ),
			),

			// Hold a release back until it has been out in the wild for a while.
			'delay'              => array(
				'enabled'    => false,
				'days'       => 2,
				'applies_to' => array( 'plugins', 'themes' ),
			),

			/*
			 * Failure alerts are on, success mails are off: WordPress already
			 * sends its own "your site has been updated" message, and two mails
			 * per update is how people learn to filter them out. Failure is the
			 * blind spot core never covers, so that one is on.
			 */
			'notify'             => array(
				'on_success'   => false,
				'on_failure'   => true,
				'on_available' => false,
				'on_untested'  => false,
			),
			'recipients'         => array(),
			'mail_format'        => 'html',

			/*
			 * Named for what it does, not for what it allows. False means
			 * WordPress keeps sending its own update mails and ours are added
			 * on top. Companion Auto Update silenced the native mails without
			 * saying so, which left users with nothing at all whenever the
			 * plugin's own mail path broke — and it had broken.
			 */
			'suppress_native_mail' => false,

			// Log retention, in days. 0 keeps everything.
			'retention_days'     => 180,

			// Roles granted manage_update_pilot on top of administrator.
			'access_roles'       => array(),

			'purge_on_uninstall' => false,
		);
	}

	/**
	 * Defaults adjusted to whatever the site is already doing.
	 *
	 * Used once, at install time. The point is that activating Update Pilot must
	 * not change a single update decision: it adopts the site's current native
	 * configuration, then lets the administrator change it deliberately.
	 *
	 * @return array
	 */
	public static function inherited_defaults(): array {
		$defaults = self::defaults();

		$auto_plugins = self::native_list( 'plugin' );
		$auto_themes  = self::native_list( 'theme' );

		$defaults['plugins']['enabled']  = ! empty( $auto_plugins );
		$defaults['plugins']['excluded'] = array_values( array_diff( self::installed_plugins(), $auto_plugins ) );

		$defaults['themes']['enabled']  = ! empty( $auto_themes );
		$defaults['themes']['excluded'] = array_values( array_diff( self::installed_themes(), $auto_themes ) );

		$defaults['core'] = self::native_core_policy();

		return $defaults;
	}

	/**
	 * The core auto-update policy the site currently applies.
	 *
	 * Mirrors what Core_Upgrader::should_update_to_version() reads, including the
	 * WP_AUTO_UPDATE_CORE constant, which overrides the stored options.
	 *
	 * @return array{minor: bool, major: bool, dev: bool}
	 */
	public static function native_core_policy(): array {
		$policy = array(
			'minor' => 'enabled' === get_site_option( 'auto_update_core_minor', 'enabled' ),
			'major' => 'enabled' === get_site_option( 'auto_update_core_major', 'unset' ),
			'dev'   => 'enabled' === get_site_option( 'auto_update_core_dev', 'enabled' ),
		);

		if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			return $policy;
		}

		$constant = WP_AUTO_UPDATE_CORE;

		if ( true === $constant ) {
			return array(
				'minor' => true,
				'major' => true,
				'dev'   => true,
			);
		}

		if ( false === $constant ) {
			return array(
				'minor' => false,
				'major' => false,
				'dev'   => false,
			);
		}

		if ( 'minor' === $constant ) {
			return array(
				'minor' => true,
				'major' => false,
				'dev'   => false,
			);
		}

		// 'beta', 'rc', 'development' and 'branch-development' all mean dev builds.
		return array(
			'minor' => true,
			'major' => true,
			'dev'   => true,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Validation
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Validate raw form input into a storable configuration.
	 *
	 * Everything is bounded, enumerated or type-checked. Nothing from $_POST
	 * reaches the option as-is.
	 *
	 * @param array $raw     Raw input, usually $_POST.
	 * @param array $current Current settings, used for keys absent from the form.
	 * @return array
	 */
	public static function sanitize( array $raw, array $current ): array {
		$clean = $current;

		/*
		 * When WP_AUTO_UPDATE_CORE is defined the three core checkboxes are
		 * rendered disabled, so the browser does not submit them — and treating
		 * an absent checkbox as "unticked" would quietly rewrite the stored
		 * policy to all-false. Nothing would appear to change while the constant
		 * is there, and the day it was removed from wp-config.php the site would
		 * silently stop taking core updates. The stored values are kept instead.
		 */
		$clean['core'] = defined( 'WP_AUTO_UPDATE_CORE' )
			? ( $current['core'] ?? self::defaults()['core'] )
			: array(
				'minor' => ! empty( $raw['core_minor'] ),
				'major' => ! empty( $raw['core_major'] ),
				'dev'   => ! empty( $raw['core_dev'] ),
			);

		$clean['plugins']['enabled'] = ! empty( $raw['plugins_enabled'] );
		$clean['themes']['enabled']  = ! empty( $raw['themes_enabled'] );
		$clean['translations']       = ! empty( $raw['translations'] );

		// Schedule.
		$clean['schedule'] = array(
			'enabled'  => ! empty( $raw['schedule_enabled'] ),
			'hour'     => self::clamp_int( $raw['schedule_hour'] ?? 3, 0, 23, 3 ),
			'minute'   => self::clamp_int( $raw['schedule_minute'] ?? 0, 0, 59, 0 ),
			'interval' => self::valid_interval( $raw['schedule_interval'] ?? 'daily' ),
		);

		// Maintenance window.
		$weekdays = array();

		if ( isset( $raw['window_weekdays'] ) && is_array( $raw['window_weekdays'] ) ) {
			foreach ( $raw['window_weekdays'] as $day ) {
				$day = (int) $day;

				if ( $day >= 0 && $day <= 6 ) {
					$weekdays[] = $day;
				}
			}
		}

		$weekdays = array_values( array_unique( $weekdays ) );
		sort( $weekdays );

		if ( empty( $weekdays ) ) {
			// An empty set would silently block every update. Treat it as "any day".
			$weekdays = array( 0, 1, 2, 3, 4, 5, 6 );
		}

		$clean['window'] = array(
			'enabled'    => ! empty( $raw['window_enabled'] ),
			'start_hour' => self::clamp_int( $raw['window_start_hour'] ?? 2, 0, 23, 2 ),
			'end_hour'   => self::clamp_int( $raw['window_end_hour'] ?? 5, 0, 23, 5 ),
			'weekdays'   => $weekdays,
		);

		// Delay.
		$applies_to = array();

		foreach ( array( 'plugins', 'themes', 'core' ) as $target ) {
			if ( ! empty( $raw[ 'delay_applies_' . $target ] ) ) {
				$applies_to[] = $target;
			}
		}

		$clean['delay'] = array(
			'enabled'    => ! empty( $raw['delay_enabled'] ),
			'days'       => self::clamp_int( $raw['delay_days'] ?? 2, 1, 90, 2 ),
			'applies_to' => $applies_to,
		);

		// Notifications.
		$clean['notify'] = array(
			'on_success'   => ! empty( $raw['notify_on_success'] ),
			'on_failure'   => ! empty( $raw['notify_on_failure'] ),
			'on_available' => ! empty( $raw['notify_on_available'] ),
			'on_untested'  => ! empty( $raw['notify_on_untested'] ),
		);

		$clean['recipients']           = self::valid_recipients( $raw['recipients'] ?? '' );
		$clean['mail_format']          = 'text' === ( $raw['mail_format'] ?? 'html' ) ? 'text' : 'html';
		$clean['suppress_native_mail'] = ! empty( $raw['suppress_native_mail'] );

		$clean['retention_days'] = self::clamp_int( $raw['retention_days'] ?? 180, 0, 3650, 180 );

		// Roles.
		$roles = array();

		if ( isset( $raw['access_roles'] ) && is_array( $raw['access_roles'] ) ) {
			foreach ( $raw['access_roles'] as $role ) {
				$role = sanitize_key( $role );

				if ( in_array( $role, self::GRANTABLE_ROLES, true ) && get_role( $role ) ) {
					$roles[] = $role;
				}
			}
		}

		$clean['access_roles'] = array_values( array_unique( $roles ) );

		$clean['purge_on_uninstall'] = ! empty( $raw['purge_on_uninstall'] );

		$clean['schema'] = self::SCHEMA;

		return $clean;
	}

	/**
	 * Validate an exclusion list of plugin basenames.
	 *
	 * @param mixed $raw Raw list.
	 * @return string[]
	 */
	public static function sanitize_plugin_list( $raw ): array {
		$clean = array();

		foreach ( (array) $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$item = trim( wp_unslash( $item ) );

			// folder/file.php, or file.php for single-file plugins.
			if ( '' !== $item && strlen( $item ) <= 191 && preg_match( '#^[A-Za-z0-9 ._\-]+(/[A-Za-z0-9 ._\-]+)*\.php$#', $item ) ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Validate an exclusion list of theme stylesheets.
	 *
	 * @param mixed $raw Raw list.
	 * @return string[]
	 */
	public static function sanitize_theme_list( $raw ): array {
		$clean = array();

		foreach ( (array) $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$item = trim( wp_unslash( $item ) );

			if ( '' !== $item && strlen( $item ) <= 191 && preg_match( '#^[A-Za-z0-9 ._\-]+$#', $item ) ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Bound an integer, falling back to a default for anything unusable.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Value used when the input is not numeric.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Check a cron recurrence against the ones WordPress actually knows.
	 *
	 * Companion Auto Update passed the posted value straight to
	 * wp_schedule_event() after clearing the core events, so an unknown
	 * recurrence left the site with no update check at all.
	 *
	 * @param mixed $value Raw recurrence.
	 * @return string
	 */
	public static function valid_interval( $value ): string {
		$value     = is_string( $value ) ? sanitize_key( $value ) : '';
		$schedules = wp_get_schedules();

		return isset( $schedules[ $value ] ) ? $value : 'daily';
	}

	/**
	 * Validate a list of recipients.
	 *
	 * Accepts either an array or a comma/newline separated string.
	 *
	 * @param mixed $value Raw recipients.
	 * @return string[]
	 */
	public static function valid_recipients( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', $value );
		}

		$clean = array();

		foreach ( (array) $value as $email ) {
			if ( ! is_string( $email ) ) {
				continue;
			}

			$email = sanitize_email( trim( wp_unslash( $email ) ) );

			if ( '' !== $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		// A hard ceiling: this is a notification list, not a mailing list.
		return array_slice( $clean, 0, 20 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Install and capabilities
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Create the options if they do not exist yet.
	 *
	 * @return void
	 */
	public static function install(): void {
		$existing = get_option( self::OPTION );

		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::inherited_defaults(), '', true );
		} else {
			// Fill in any key introduced by a newer schema, keep everything else.
			update_option( self::OPTION, self::merge_defaults( $existing, self::defaults() ), true );
		}

		if ( ! is_array( get_option( self::STATE_OPTION ) ) ) {
			add_option(
				self::STATE_OPTION,
				array(
					'first_seen' => array(),
					'last_run'   => 0,
					'last_error' => null,
				),
				'',
				false
			);
		}

		self::$cache = null;
	}

	/**
	 * Give manage_update_pilot to administrators and to the configured roles,
	 * and take it away from every other role.
	 *
	 * Companion Auto Update offered an "allow editors/authors" setting that had
	 * no effect at all, because its menu page still required manage_options.
	 * Here the capability is real and it is the one the pages check.
	 *
	 * @return void
	 */
	public static function grant_capability(): void {
		$settings = self::get();
		$allowed  = array_merge( array( 'administrator' ), (array) $settings['access_roles'] );

		$roles = wp_roles();

		foreach ( $roles->role_objects as $slug => $role ) {
			$should_have = in_array( $slug, $allowed, true );
			$has         = $role->has_cap( self::CAPABILITY );

			if ( $should_have && ! $has ) {
				$role->add_cap( self::CAPABILITY );
			} elseif ( ! $should_have && $has ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Synchronisation with the native auto-update option
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Mirror our exclusion lists into the native options.
	 *
	 * One state of truth: what the administrator ticks here is what the Plugins
	 * and Themes screens show, and what WP-CLI or a monitoring tool reads.
	 * Companion Auto Update instead hid the native column with
	 * `plugins_auto_update_enabled => __return_false`, so its own list and the
	 * option core actually consults drifted apart with no way to tell.
	 *
	 * @return void
	 */
	public static function push_to_native(): void {
		if ( self::$syncing || ! self::may_touch_native() ) {
			return;
		}

		self::$syncing = true;

		$settings = self::get();

		if ( $settings['plugins']['enabled'] ) {
			update_site_option(
				'auto_update_plugins',
				array_values( array_diff( self::installed_plugins(), $settings['plugins']['excluded'] ) )
			);
		}

		if ( $settings['themes']['enabled'] ) {
			update_site_option(
				'auto_update_themes',
				array_values( array_diff( self::installed_themes(), $settings['themes']['excluded'] ) )
			);
		}

		self::$syncing = false;
	}

	/**
	 * Mirror a native option change back into our exclusion lists.
	 *
	 * Fires when the administrator uses the "Enable auto-updates" link on the
	 * Plugins or Themes screen, or when any other tool writes the option.
	 *
	 * @return void
	 */
	public static function on_native_option_changed(): void {
		if ( self::$syncing || ! self::may_touch_native() ) {
			return;
		}

		self::$syncing = true;

		$settings = self::get();

		if ( $settings['plugins']['enabled'] ) {
			$settings['plugins']['excluded'] = array_values(
				array_diff( self::installed_plugins(), self::native_list( 'plugin' ) )
			);
		}

		if ( $settings['themes']['enabled'] ) {
			$settings['themes']['excluded'] = array_values(
				array_diff( self::installed_themes(), self::native_list( 'theme' ) )
			);
		}

		self::save( $settings );

		self::$syncing = false;
	}

	/**
	 * Whether this site may write the native auto-update options.
	 *
	 * `auto_update_plugins` and `auto_update_themes` are network options: on
	 * multisite a single sub-site would be rewriting the auto-update policy of
	 * every other site in the network, while its own settings are stored per
	 * site and its filters never even run — core executes the automatic updater
	 * on the main site only.
	 *
	 * Version 1.0 states that it manages a single site, so on a network it keeps
	 * its hands off the shared options entirely rather than half-supporting
	 * multisite. That was Companion Auto Update's mistake, in the other
	 * direction.
	 *
	 * @return bool
	 */
	public static function may_touch_native(): bool {
		return ! is_multisite();
	}

	/**
	 * The native list of items set to auto-update.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @return string[]
	 */
	public static function native_list( string $type ): array {
		$list = get_site_option( 'auto_update_' . $type . 's', array() );

		if ( ! is_array( $list ) ) {
			return array();
		}

		return array_values( array_filter( $list, 'is_string' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Inventory helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Every installed plugin, as `folder/file.php`.
	 *
	 * @return string[]
	 */
	public static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_keys( get_plugins() );
	}

	/**
	 * Every installed theme, as a stylesheet directory name.
	 *
	 * @return string[]
	 */
	public static function installed_themes(): array {
		return array_keys( wp_get_themes() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Internals
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Recursively fill missing keys from the defaults.
	 *
	 * Only descends into arrays that are associative in the defaults, so that
	 * list values (exclusions, weekdays, recipients) are taken as-is instead of
	 * being merged key by key.
	 *
	 * @param array $stored   Stored values.
	 * @param array $defaults Default values.
	 * @return array
	 */
	private static function merge_defaults( array $stored, array $defaults ): array {
		$merged = $defaults;

		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			if ( is_array( $defaults[ $key ] ) && is_array( $value ) && ! self::is_list( $defaults[ $key ] ) ) {
				$merged[ $key ] = self::merge_defaults( $value, $defaults[ $key ] );
				continue;
			}

			$merged[ $key ] = $value;
		}

		return $merged;
	}

	/**
	 * Whether an array is a plain list.
	 *
	 * array_is_list() is PHP 8.1; this plugin supports 8.0.
	 *
	 * @param array $value Array to inspect.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
