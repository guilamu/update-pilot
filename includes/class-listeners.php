<?php
/**
 * Listening to what WordPress actually did.
 *
 * This file is the reason the plugin exists in this form. Companion Auto Update
 * reconstructed its history by walking every plugin and theme directory, reading
 * filemtime(), and comparing it against a ±30 minute window around the cron hour
 * to guess whether an update had happened and whether it was automatic. About
 * three hundred lines, and wrong in every case a git deploy, a WP-CLI run, a
 * restore from backup or a timezone shift was involved.
 *
 * WordPress reports the same information exactly, through two hooks:
 *
 *   automatic_updates_complete  every automatic update, with its result —
 *                               including the failures, which is the one thing
 *                               core itself never surfaces well
 *   upgrader_process_complete   every manual or WP-CLI update
 *
 * Versions before the update are captured from the pre-update hooks, so the
 * before/after pair is observed, never inferred.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records real update events.
 */
class Update_Pilot_Listeners {

	/**
	 * Installed versions captured just before an update runs.
	 *
	 * Keyed "type:identifier".
	 *
	 * @var array<string, string>
	 */
	private static array $versions_before = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Capture the "before" version while it is still true.
		add_action( 'pre_auto_update', array( __CLASS__, 'snapshot_automatic' ), 10, 2 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'snapshot_manual' ), 10, 2 );

		// Record the outcome.
		add_action( 'automatic_updates_complete', array( __CLASS__, 'on_automatic_complete' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_manual_complete' ), 10, 2 );
	}

	/**
	 * Whether the current request is an automatic update run.
	 *
	 * This originally tested a DOING_AUTOUPDATE constant. That constant does not
	 * exist in WordPress — verified by searching the whole core tree on 7.0.4,
	 * not by recollection. The consequence was real: the "Run an update pass
	 * now" button calls wp_maybe_auto_update() from an ordinary admin POST, so
	 * neither DOING_AUTOUPDATE nor wp_doing_cron() was true, and every update
	 * was written to the log twice — once as manual from
	 * upgrader_process_complete, once as automatic from
	 * automatic_updates_complete. A log that says two contradictory things about
	 * one event is exactly what this plugin exists to stop.
	 *
	 * UPDATE_PILOT_AUTOUPDATE is our own marker, set by Scheduler::run() around
	 * the call, and it covers any future programmatic caller as well.
	 *
	 * @return bool
	 */
	public static function is_automatic(): bool {
		return ( defined( 'UPDATE_PILOT_AUTOUPDATE' ) && UPDATE_PILOT_AUTOUPDATE ) || wp_doing_cron();
	}

	/**
	 * Where an update came from.
	 *
	 * 'forced' is a run somebody asked for from the Status screen, on one item,
	 * knowing the policy was holding it back. It reads as automatic to
	 * WordPress — the same code installs it — but recording it as automatic
	 * would credit the schedule with a decision a person made.
	 *
	 * @return string 'auto', 'forced', 'cli' or 'manual'.
	 */
	public static function trigger_source(): string {
		if ( defined( 'UPDATE_PILOT_FORCED' ) && UPDATE_PILOT_FORCED ) {
			return 'forced';
		}

		if ( self::is_automatic() ) {
			return 'auto';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		return 'manual';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Before
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Capture the installed version before an automatic update.
	 *
	 * @param string $type Item type.
	 * @param object $item Update offer.
	 * @return void
	 */
	public static function snapshot_automatic( $type, $item ): void {
		$identity = Update_Pilot_Policy_Filters::normalise( (string) $type, $item );

		self::remember_version( (string) $type, (string) $identity['id'] );
	}

	/**
	 * Capture the installed version before a manual update.
	 *
	 * Hooked to a filter, so it must return what it was given.
	 *
	 * @param mixed $response   Value passed along untouched.
	 * @param array $hook_extra Upgrade context.
	 * @return mixed
	 */
	public static function snapshot_manual( $response, $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return $response;
		}

		if ( ! empty( $hook_extra['plugin'] ) ) {
			self::remember_version( 'plugin', (string) $hook_extra['plugin'] );
		}

		if ( ! empty( $hook_extra['theme'] ) ) {
			self::remember_version( 'theme', (string) $hook_extra['theme'] );
		}

		return $response;
	}

	/**
	 * Store the currently installed version of an item.
	 *
	 * @param string $type Item type.
	 * @param string $id   Identifier.
	 * @return void
	 */
	private static function remember_version( string $type, string $id ): void {
		if ( '' === $id ) {
			return;
		}

		$version = self::installed_version( $type, $id );

		if ( null !== $version ) {
			self::$versions_before[ $type . ':' . $id ] = $version;
		}
	}

	/**
	 * The version currently installed on disk.
	 *
	 * @param string $type Item type.
	 * @param string $id   Identifier.
	 * @return string|null
	 */
	public static function installed_version( string $type, string $id ): ?string {
		switch ( $type ) {
			case 'plugin':
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$file = WP_PLUGIN_DIR . '/' . $id;

				if ( ! file_exists( $file ) ) {
					return null;
				}

				$data = get_plugin_data( $file, false, false );

				return empty( $data['Version'] ) ? null : (string) $data['Version'];

			case 'theme':
				$theme = wp_get_theme( $id );

				if ( ! $theme->exists() ) {
					return null;
				}

				$version = $theme->get( 'Version' );

				return $version ? (string) $version : null;

			case 'core':
				return (string) get_bloginfo( 'version' );

			default:
				return null;
		}
	}

	/**
	 * Read back a captured version.
	 *
	 * @param string $type Item type.
	 * @param string $id   Identifier.
	 * @return string|null
	 */
	private static function version_before( string $type, string $id ): ?string {
		return self::$versions_before[ $type . ':' . $id ] ?? null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * After — automatic updates
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Record the outcome of an automatic update run.
	 *
	 * @param array $results Results, keyed by type.
	 * @return void
	 */
	public static function on_automatic_complete( $results ): void {
		if ( ! is_array( $results ) || array() === $results ) {
			return;
		}

		$events = array();

		foreach ( $results as $type => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				$event = self::event_from_result( (string) $type, $entry );

				if ( null !== $event ) {
					Update_Pilot_Logger::record( $event );
					$events[] = $event;
				}
			}
		}

		if ( array() === $events ) {
			return;
		}

		Update_Pilot_Notifier::report_run( $events );
	}

	/**
	 * Turn one entry of $update_results into a log event.
	 *
	 * @param string $type  Item type.
	 * @param mixed  $entry Result entry.
	 * @return array|null
	 */
	private static function event_from_result( string $type, $entry ): ?array {
		if ( ! is_object( $entry ) ) {
			return null;
		}

		$item = $entry->item ?? null;

		if ( null === $item ) {
			return null;
		}

		$identity = Update_Pilot_Policy_Filters::normalise( $type, $item );
		$id       = (string) $identity['id'];

		if ( '' === $id ) {
			return null;
		}

		$result  = $entry->result ?? null;
		$status  = self::status_from_result( $result );
		$message = self::message_from_result( $entry );

		$from = self::version_before( $type, $id );
		$to   = $identity['version'];

		// A failed update leaves the old version in place.
		if ( 'success' !== $status ) {
			$to = null;
		}

		return array(
			'type'           => $type,
			'item'           => $id,
			'name'           => self::display_name( $type, $id, $entry ),
			'from_version'   => $from,
			'to_version'     => $to,
			'trigger_source' => 'auto',
			'status'         => $status,
			'message'        => $message,
			'timestamp'      => time(),
		);
	}

	/**
	 * Map an upgrade result to a status.
	 *
	 * @param mixed $result Result value.
	 * @return string
	 */
	private static function status_from_result( $result ): string {
		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();

			/*
			 * Core's real codes, read from WP_Automatic_Updater on 7.0.4:
			 *
			 *   plugin_update_fatal_error_rollback_successful
			 *   plugin_update_fatal_error_rollback_failed
			 *   rollback_was_required
			 *
			 * Matching "rollback" anywhere in the code lumps the second one in
			 * with the first. That case means the new version broke the site AND
			 * the old one could not be put back — the worst outcome there is —
			 * and calling it "rolled back" reads like a safe landing.
			 */
			if ( false !== strpos( $code, 'rollback_failed' ) || false !== strpos( $code, 'restore_failed' ) ) {
				return 'failed';
			}

			if ( false !== strpos( $code, 'rollback' ) || false !== strpos( $code, 'restore' ) ) {
				return 'rolled_back';
			}

			return 'failed';
		}

		if ( true === $result || ( is_array( $result ) && ! empty( $result ) ) ) {
			return 'success';
		}

		if ( is_string( $result ) && '' !== $result ) {
			return 'success';
		}

		return 'failed';
	}

	/**
	 * Build a readable message from a result entry.
	 *
	 * @param object $entry Result entry.
	 * @return string|null
	 */
	private static function message_from_result( $entry ): ?string {
		$parts = array();

		$result = $entry->result ?? null;

		if ( is_wp_error( $result ) ) {
			foreach ( $result->get_error_messages() as $message ) {
				$parts[] = (string) $message;
			}

			$data = $result->get_error_data();

			if ( is_string( $data ) && '' !== $data ) {
				$parts[] = $data;
			}
		}

		if ( ! empty( $entry->messages ) && is_array( $entry->messages ) ) {
			foreach ( $entry->messages as $message ) {
				if ( is_string( $message ) ) {
					$parts[] = $message;
				}
			}
		}

		$parts = array_filter( array_unique( $parts ) );

		if ( array() === $parts ) {
			return null;
		}

		return mb_substr( wp_strip_all_tags( implode( ' | ', $parts ) ), 0, 5000 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * After — manual and WP-CLI updates
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Record the outcome of a manual update.
	 *
	 * Skipped entirely during an automatic run: automatic_updates_complete
	 * reports the same work with a real success/failure result, and one update
	 * must produce exactly one log entry.
	 *
	 * @param WP_Upgrader|mixed $upgrader   Upgrader instance.
	 * @param array             $hook_extra Upgrade context.
	 * @return void
	 */
	public static function on_manual_complete( $upgrader, $hook_extra ): void {
		if ( self::is_automatic() ) {
			return;
		}

		if ( ! is_array( $hook_extra ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
			return;
		}

		$type   = (string) ( $hook_extra['type'] ?? '' );
		$source = self::trigger_source();

		switch ( $type ) {
			case 'plugin':
				$items = self::extract_items( $hook_extra, 'plugin', 'plugins' );

				foreach ( $items as $id ) {
					self::record_manual( 'plugin', $id, $upgrader, $source );
				}
				break;

			case 'theme':
				// The theme registry still holds pre-update data at this point.
				wp_clean_themes_cache();

				$items = self::extract_items( $hook_extra, 'theme', 'themes' );

				foreach ( $items as $id ) {
					self::record_manual( 'theme', $id, $upgrader, $source );
				}
				break;

			case 'core':
				self::record_manual_core( $upgrader, $source );
				break;

			case 'translation':
				self::record_manual_translations( $hook_extra, $upgrader, $source );
				break;
		}
	}

	/**
	 * Pull the item list out of the upgrade context.
	 *
	 * @param array  $hook_extra Upgrade context.
	 * @param string $single_key Key holding a single item.
	 * @param string $plural_key Key holding a list.
	 * @return string[]
	 */
	private static function extract_items( array $hook_extra, string $single_key, string $plural_key ): array {
		if ( ! empty( $hook_extra[ $plural_key ] ) && is_array( $hook_extra[ $plural_key ] ) ) {
			return array_values( array_filter( array_map( 'strval', $hook_extra[ $plural_key ] ) ) );
		}

		if ( ! empty( $hook_extra[ $single_key ] ) ) {
			return array( (string) $hook_extra[ $single_key ] );
		}

		return array();
	}

	/**
	 * Log one manually updated plugin or theme.
	 *
	 * Success is established by comparing the version on disk with the one
	 * captured before the upgrade started. Either the number changed or it did
	 * not; there is nothing to infer.
	 *
	 * @param string $type     Item type.
	 * @param string $id       Identifier.
	 * @param mixed  $upgrader Upgrader instance.
	 * @param string $source   Trigger source.
	 * @return void
	 */
	private static function record_manual( string $type, string $id, $upgrader, string $source ): void {
		$before = self::version_before( $type, $id );
		$after  = self::installed_version( $type, $id );

		$changed = ( null !== $after ) && ( null === $before || $before !== $after );
		$status  = $changed ? 'success' : 'failed';
		$message = $changed ? null : self::upgrader_errors( $upgrader );

		Update_Pilot_Logger::record(
			array(
				'type'           => $type,
				'item'           => $id,
				'name'           => self::display_name( $type, $id, null ),
				'from_version'   => $before,
				'to_version'     => $changed ? $after : null,
				'trigger_source' => $source,
				'status'         => $status,
				'message'        => $message,
				'timestamp'      => time(),
			)
		);
	}

	/**
	 * Log a manual core update.
	 *
	 * The running process still has the old version loaded, so the new number is
	 * read from the offer rather than from get_bloginfo().
	 *
	 * @param mixed  $upgrader Upgrader instance.
	 * @param string $source   Trigger source.
	 * @return void
	 */
	private static function record_manual_core( $upgrader, string $source ): void {
		$before = (string) get_bloginfo( 'version' );
		$after  = null;

		$offer = get_site_transient( 'update_core' );

		if ( is_object( $offer ) && ! empty( $offer->updates ) && is_array( $offer->updates ) ) {
			foreach ( $offer->updates as $update ) {
				$update = is_array( $update ) ? (object) $update : $update;

				if ( is_object( $update ) && ! empty( $update->current ) ) {
					$after = (string) $update->current;
					break;
				}
			}
		}

		$errors = self::upgrader_errors( $upgrader );

		// If the offer was refreshed before we read it, the "after" value can be
		// the version already running. Reporting 7.0.4 -> 7.0.4 is noise, so the
		// unknown is left unknown.
		if ( null !== $after && $after === $before ) {
			$after = null;
		}

		Update_Pilot_Logger::record(
			array(
				'type'           => 'core',
				'item'           => 'core',
				'name'           => 'WordPress',
				'from_version'   => $before,
				'to_version'     => null === $errors ? $after : null,
				'trigger_source' => $source,
				'status'         => null === $errors ? 'success' : 'failed',
				'message'        => $errors,
				'timestamp'      => time(),
			)
		);
	}

	/**
	 * Log manually updated translations, one row per language pack.
	 *
	 * @param array  $hook_extra Upgrade context.
	 * @param string $source     Trigger source.
	 * @return void
	 */
	private static function record_manual_translations( array $hook_extra, $upgrader, string $source ): void {
		if ( empty( $hook_extra['translations'] ) || ! is_array( $hook_extra['translations'] ) ) {
			return;
		}

		/*
		 * This used to write 'success' for every language pack without ever
		 * looking at the outcome, which made the plugin's central promise —
		 * that failures are recorded — untrue for this one path. WordPress does
		 * not report per-pack results here, so the honest thing is to report
		 * failure when the upgrader failed and to claim success only when it
		 * did not.
		 */
		$errors = self::upgrader_errors( $upgrader );
		$status = null === $errors ? 'success' : 'failed';

		foreach ( $hook_extra['translations'] as $translation ) {
			$translation = (array) $translation;

			$id = sprintf(
				'%s:%s:%s',
				(string) ( $translation['type'] ?? 'core' ),
				(string) ( $translation['slug'] ?? '' ),
				(string) ( $translation['language'] ?? '' )
			);

			Update_Pilot_Logger::record(
				array(
					'type'           => 'translation',
					'item'           => $id,
					'name'           => (string) ( $translation['language'] ?? $id ),
					'from_version'   => null,
					'to_version'     => 'success' === $status && isset( $translation['version'] ) ? (string) $translation['version'] : null,
					'trigger_source' => $source,
					'status'         => $status,
					'message'        => $errors,
					'timestamp'      => time(),
				)
			);
		}
	}

	/**
	 * Collect whatever the upgrader's skin has to say about a failure.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @return string|null
	 */
	private static function upgrader_errors( $upgrader ): ?string {
		$parts = array();

		if ( is_object( $upgrader ) && isset( $upgrader->result ) && is_wp_error( $upgrader->result ) ) {
			$parts = array_merge( $parts, $upgrader->result->get_error_messages() );
		}

		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin )
			&& method_exists( $upgrader->skin, 'get_errors' ) ) {
			$errors = $upgrader->skin->get_errors();

			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				$parts = array_merge( $parts, $errors->get_error_messages() );
			}
		}

		$parts = array_filter( array_unique( array_map( 'strval', $parts ) ) );

		if ( array() === $parts ) {
			return null;
		}

		return mb_substr( wp_strip_all_tags( implode( ' | ', $parts ) ), 0, 5000 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Naming
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A human-readable name for an item.
	 *
	 * These come from third-party plugin and theme headers, so they are treated
	 * as untrusted text and escaped at render time, never here.
	 *
	 * @param string      $type  Item type.
	 * @param string      $id    Identifier.
	 * @param object|null $entry Result entry, when there is one.
	 * @return string
	 */
	private static function display_name( string $type, string $id, $entry ): string {
		if ( is_object( $entry ) && ! empty( $entry->name ) && is_string( $entry->name ) ) {
			return $entry->name;
		}

		switch ( $type ) {
			case 'plugin':
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$file = WP_PLUGIN_DIR . '/' . $id;

				if ( file_exists( $file ) ) {
					$data = get_plugin_data( $file, false, false );

					if ( ! empty( $data['Name'] ) ) {
						return (string) $data['Name'];
					}
				}

				return $id;

			case 'theme':
				$theme = wp_get_theme( $id );

				return $theme->exists() ? (string) $theme->get( 'Name' ) : $id;

			case 'core':
				return 'WordPress';

			default:
				return $id;
		}
	}
}
