<?php
/**
 * Scheduling.
 *
 * The single most important thing in this file is what it does *not* do:
 * it never touches wp_update_plugins, wp_update_themes, wp_version_check or
 * wp_maybe_auto_update. Companion Auto Update cleared those core events and
 * re-created them on its own recurrence, which is why deactivating it left a
 * site with no update checks at all, and why saving its settings with an
 * unknown recurrence silently removed every one of them.
 *
 * Update Pilot adds one event of its own, on its own hook, and calls the public
 * wp_maybe_auto_update() from it. Core's own events keep running untouched; the
 * maintenance window is what stops them from doing anything at the wrong hour.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's cron events.
 */
class Update_Pilot_Scheduler {

	/**
	 * Our update trigger.
	 */
	public const EVENT = 'update_pilot_run';

	/**
	 * Our once-a-day housekeeping slot: log retention, and the check for
	 * updates that are available but not installed.
	 */
	public const DAILY_EVENT = 'update_pilot_daily';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::EVENT, array( __CLASS__, 'run' ) );
	}

	/**
	 * Add the recurrences WordPress does not ship.
	 *
	 * The labels are plain, untranslated strings, deliberately.
	 * `cron_schedules` is evaluated by anything that calls wp_get_schedules(),
	 * including plugins that do so before `init`. A __() call here produces the
	 * "Translation loading was triggered too early" notice on WordPress 6.7 and
	 * later — the exact trap documented in the repository's own auto-update
	 * reference. Labels are translated at render time instead, by
	 * interval_label().
	 *
	 * @param array $schedules Existing recurrences.
	 * @return array
	 */
	public static function add_schedules( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		if ( ! isset( $schedules['update_pilot_biweekly'] ) ) {
			$schedules['update_pilot_biweekly'] = array(
				'interval' => 2 * WEEK_IN_SECONDS,
				'display'  => 'Every two weeks',
			);
		}

		if ( ! isset( $schedules['update_pilot_monthly'] ) ) {
			$schedules['update_pilot_monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => 'Once a month',
			);
		}

		return $schedules;
	}

	/**
	 * The recurrences offered in the settings screen, in a sensible order.
	 *
	 * @return string[]
	 */
	public static function offered_intervals(): array {
		$available = wp_get_schedules();

		$preferred = array( 'hourly', 'twicedaily', 'daily', 'weekly', 'update_pilot_biweekly', 'update_pilot_monthly' );

		return array_values( array_filter( $preferred, static fn( $slug ) => isset( $available[ $slug ] ) ) );
	}

	/**
	 * A translated label for a recurrence.
	 *
	 * Translation happens here, at render time, and never inside the
	 * cron_schedules filter.
	 *
	 * @param string $slug Recurrence slug.
	 * @return string
	 */
	public static function interval_label( string $slug ): string {
		switch ( $slug ) {
			case 'hourly':
				return __( 'Every hour', 'update-pilot' );

			case 'twicedaily':
				return __( 'Twice a day', 'update-pilot' );

			case 'daily':
				return __( 'Once a day', 'update-pilot' );

			case 'weekly':
				return __( 'Once a week', 'update-pilot' );

			case 'update_pilot_biweekly':
				return __( 'Every two weeks', 'update-pilot' );

			case 'update_pilot_monthly':
				return __( 'Once a month', 'update-pilot' );

			default:
				$schedules = wp_get_schedules();

				return isset( $schedules[ $slug ]['display'] ) ? (string) $schedules[ $slug ]['display'] : $slug;
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scheduling
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Bring the scheduled events in line with the settings.
	 *
	 * The order of operations is the point. The new event is created first, and
	 * the old occurrences are only removed once that has demonstrably succeeded.
	 * Companion Auto Update did the opposite — clear first, schedule after,
	 * never check the return value — so a rejected recurrence left the site with
	 * nothing scheduled and no error anywhere.
	 *
	 * @return true|WP_Error
	 */
	public static function reschedule() {
		self::schedule_daily();

		$settings = Update_Pilot_Settings::get();

		if ( empty( $settings['schedule']['enabled'] ) ) {
			self::clear_event( self::EVENT );
			self::clear_error( self::EVENT );

			return true;
		}

		$recurrence = (string) $settings['schedule']['interval'];
		$schedules  = wp_get_schedules();

		if ( ! isset( $schedules[ $recurrence ] ) ) {
			$error = new WP_Error(
				'update_pilot_unknown_recurrence',
				sprintf(
					/* translators: %s: cron recurrence identifier. */
					__( 'The recurrence "%s" is not one WordPress knows about, so the schedule was left untouched.', 'update-pilot' ),
					$recurrence
				)
			);

			self::record_error( self::EVENT, $error );

			return $error;
		}

		$target   = self::next_timestamp( (int) $settings['schedule']['hour'], (int) $settings['schedule']['minute'] );
		$existing = self::existing_timestamps( self::EVENT );

		// Already scheduled at the right time with the right recurrence?
		$current = wp_get_scheduled_event( self::EVENT );

		if ( $current && $current->schedule === $recurrence && in_array( $target, $existing, true ) ) {
			self::clear_error( self::EVENT );

			return true;
		}

		$scheduled = wp_schedule_event( $target, $recurrence, self::EVENT );

		if ( is_wp_error( $scheduled ) ) {
			self::record_error( self::EVENT, $scheduled );

			return $scheduled;
		}

		if ( false === $scheduled ) {
			$error = new WP_Error(
				'update_pilot_schedule_failed',
				__( 'WordPress refused to create the scheduled event. The previous schedule has been left in place.', 'update-pilot' )
			);

			self::record_error( self::EVENT, $error );

			return $error;
		}

		// The new event exists. Only now is it safe to drop the old ones.
		foreach ( $existing as $timestamp ) {
			if ( $timestamp !== $target ) {
				wp_unschedule_event( $timestamp, self::EVENT );
			}
		}

		self::clear_error( self::EVENT );

		return true;
	}

	/**
	 * Make sure the daily housekeeping event exists.
	 *
	 * @return void
	 */
	public static function schedule_daily(): void {
		if ( wp_next_scheduled( self::DAILY_EVENT ) ) {
			return;
		}

		$scheduled = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_EVENT );

		// Nothing here can destroy an existing schedule, but a silent failure
		// would mean the log is never purged, so it gets recorded like any other.
		if ( is_wp_error( $scheduled ) ) {
			self::record_error( self::DAILY_EVENT, $scheduled );
		} elseif ( false === $scheduled ) {
			self::record_error(
				self::DAILY_EVENT,
				new WP_Error(
					'update_pilot_daily_schedule_failed',
					__( 'WordPress refused to create the daily housekeeping event, so the log will not be trimmed automatically.', 'update-pilot' )
				)
			);
		}
	}

	/**
	 * Remove every event this plugin owns.
	 *
	 * Called on deactivation. Core's own update events are not ours to remove,
	 * and are left exactly as they were.
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		self::clear_event( self::EVENT );
		self::clear_event( self::DAILY_EVENT );
	}

	/**
	 * Remove all occurrences of one of our hooks.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	private static function clear_event( string $hook ): void {
		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Every timestamp at which a hook is currently scheduled.
	 *
	 * @param string $hook Hook name.
	 * @return int[]
	 */
	private static function existing_timestamps( string $hook ): array {
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) ) {
			return array();
		}

		$timestamps = array();

		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ] ) ) {
				$timestamps[] = (int) $timestamp;
			}
		}

		return $timestamps;
	}

	/**
	 * The next time the clock shows a given hour and minute, as a UTC timestamp.
	 *
	 * @param int $hour   Hour, 0-23, in the site's timezone.
	 * @param int $minute Minute, 0-59.
	 * @return int
	 */
	public static function next_timestamp( int $hour, int $minute ): int {
		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$target   = $now->setTime( max( 0, min( 23, $hour ) ), max( 0, min( 59, $minute ) ), 0 );

		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}

		return $target->getTimestamp();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Running
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Ask WordPress to run its automatic updates.
	 *
	 * That is the whole body of our scheduled event. wp_maybe_auto_update() is
	 * the same public function core calls from its own event; it refreshes the
	 * update transients and hands over to WP_Automatic_Updater. Everything this
	 * plugin decides has already been said through the eligibility filters.
	 *
	 * @return void
	 */
	public static function run(): void {
		/*
		 * Mark the request as an automatic run before anything happens.
		 * WordPress has no constant of its own for this, so without a marker the
		 * listeners cannot tell an update triggered from the Status screen from
		 * one an administrator performed by hand, and they log both.
		 */
		if ( ! defined( 'UPDATE_PILOT_AUTOUPDATE' ) ) {
			define( 'UPDATE_PILOT_AUTOUPDATE', true );
		}

		$state             = Update_Pilot_Settings::get_state();
		$state['last_run'] = time();

		Update_Pilot_Settings::save_state( $state );

		/*
		 * WP_Automatic_Updater::run() refreshes the transients itself, but
		 * wp_update_plugins() and wp_update_themes() return early when the cached
		 * data is under twelve hours old unless they are given extra stats. A run
		 * asked for explicitly should look at current offers, not at yesterday's.
		 */
		self::force_update_checks();

		wp_maybe_auto_update();
	}

	/**
	 * Ask WordPress to re-check for updates, ignoring the twelve-hour cache.
	 *
	 * `wp_version_check()` takes a $force_check argument; the plugin and theme
	 * equivalents do not, and skip the request entirely when
	 * `$time_not_changed && ! $extra_stats`. Passing a non-empty $extra_stats
	 * array is the documented way to make them go out to the network.
	 *
	 * @return void
	 */
	public static function force_update_checks(): void {
		$stats = array( 'update_pilot' => UPILOT_VERSION );

		wp_version_check( $stats, true );
		wp_update_plugins( $stats );
		wp_update_themes( $stats );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Status reporting
	 * ---------------------------------------------------------------------
	 */

	/**
	 * When our event is next due.
	 *
	 * @return int|null
	 */
	public static function next_run(): ?int {
		$next = wp_next_scheduled( self::EVENT );

		return $next ? (int) $next : null;
	}

	/**
	 * When our event last ran.
	 *
	 * @return int|null
	 */
	public static function last_run(): ?int {
		$state = Update_Pilot_Settings::get_state();

		return empty( $state['last_run'] ) ? null : (int) $state['last_run'];
	}

	/**
	 * How far behind WP-Cron is, in seconds.
	 *
	 * WP-Cron only fires when somebody visits the site. On a quiet site, "03:00"
	 * is an intention, not a guarantee — so the honest thing to do is measure the
	 * lateness and show it. Companion Auto Update advertised an exact hour and
	 * never mentioned the limitation.
	 *
	 * @return int Zero when nothing is overdue.
	 */
	public static function cron_lateness(): int {
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) || array() === $crons ) {
			return 0;
		}

		$earliest = min( array_map( 'intval', array_keys( $crons ) ) );
		$lateness = time() - $earliest;

		return $lateness > 0 ? $lateness : 0;
	}

	/**
	 * The command line for a real system cron, ready to copy.
	 *
	 * @return string
	 */
	public static function system_cron_command(): string {
		return sprintf(
			'*/5 * * * * curl -s %s > /dev/null 2>&1',
			esc_url_raw( site_url( 'wp-cron.php?doing_wp_cron' ) )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Errors
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Remember a scheduling failure so the Status page can show it.
	 *
	 * Errors are kept per event. The two cron events fail for different reasons
	 * and are repaired at different moments; a single shared slot meant that
	 * succeeding at one of them erased the record of the other still being
	 * broken, and the Status page then reported that all was well while the log
	 * was never trimmed and the daily report never ran.
	 *
	 * @param string   $hook  Event the error belongs to.
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private static function record_error( string $hook, WP_Error $error ): void {
		$state = Update_Pilot_Settings::get_state();

		if ( ! isset( $state['last_error'] ) || ! is_array( $state['last_error'] ) ) {
			$state['last_error'] = array();
		}

		$state['last_error'][ $hook ] = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'time'    => time(),
		);

		Update_Pilot_Settings::save_state( $state );
	}

	/**
	 * Forget the recorded failure of one event.
	 *
	 * @param string $hook Event.
	 * @return void
	 */
	private static function clear_error( string $hook ): void {
		$state = Update_Pilot_Settings::get_state();

		if ( empty( $state['last_error'] ) || ! is_array( $state['last_error'] ) || ! isset( $state['last_error'][ $hook ] ) ) {
			return;
		}

		unset( $state['last_error'][ $hook ] );

		Update_Pilot_Settings::save_state( $state );
	}

	/**
	 * The recorded scheduling failures, if any.
	 *
	 * @return array<string, array> Keyed by event hook.
	 */
	public static function errors(): array {
		$state = Update_Pilot_Settings::get_state();

		if ( empty( $state['last_error'] ) ) {
			return array();
		}

		// Tolerate the single-slot shape written by earlier versions.
		if ( isset( $state['last_error']['message'] ) ) {
			return array( self::EVENT => (array) $state['last_error'] );
		}

		return array_filter( (array) $state['last_error'] );
	}

	/**
	 * The most recent failure, whichever event it belongs to.
	 *
	 * @return array|null
	 */
	public static function last_error(): ?array {
		$errors = self::errors();

		if ( array() === $errors ) {
			return null;
		}

		uasort( $errors, static fn( $a, $b ) => ( (int) ( $b['time'] ?? 0 ) ) <=> ( (int) ( $a['time'] ?? 0 ) ) );

		return reset( $errors );
	}
}

/*
 * Registered as the file loads, not from init().
 *
 * Activation runs after `plugins_loaded` has already fired for the request, so
 * a filter added inside init() is not yet in place when the activation hook
 * calls reschedule(). A site configured on a fortnightly or monthly recurrence
 * would then be told its own recurrence does not exist, and end up with no
 * scheduled run at all until the settings were saved again by hand.
 *
 * The labels are plain strings, so registering this early cannot trigger the
 * "translation loading was triggered too early" notice.
 */
add_filter( 'cron_schedules', array( 'Update_Pilot_Scheduler', 'add_schedules' ) );
