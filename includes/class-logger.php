<?php
/**
 * Writing to the update log.
 *
 * A table is the right tool here, unlike for the settings: this is an
 * append-only event stream that will reach thousands of rows, and it has to be
 * filtered, paginated and purged.
 *
 * Two decisions that came straight out of the audit of Companion Auto Update:
 *
 * - occurred_at is stored in UTC, always, and converted only for display. CAU
 *   stored locally formatted strings that could not be sorted, and derived them
 *   from a date format that strtotime() failed to parse in 100% of cases.
 * - the column is trigger_source, not trigger: TRIGGER is a reserved word.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the log table and appends events to it.
 */
class Update_Pilot_Logger {

	/**
	 * Table name without the prefix.
	 */
	public const TABLE = 'update_pilot_log';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( Update_Pilot_Scheduler::DAILY_EVENT, array( __CLASS__, 'purge' ) );
	}

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or update the table.
	 *
	 * Called from the install routine, which itself only runs when the stored
	 * schema number differs from the code. CAU hooked its equivalent to
	 * upgrader_process_complete, so dbDelta() ran after every single update of
	 * every single plugin on the site.
	 *
	 * @return void
	 */
	public static function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * varchar(191) rather than 255: on utf8mb4 with the older MySQL row
		 * formats, an indexed column cannot exceed 191 characters.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			occurred_at datetime NOT NULL,
			type varchar(20) NOT NULL,
			item varchar(191) NOT NULL,
			name varchar(191) NOT NULL,
			from_version varchar(32) NULL,
			to_version varchar(32) NULL,
			trigger_source varchar(20) NOT NULL,
			status varchar(20) NOT NULL,
			message text NULL,
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at),
			KEY type_item (type,item(100))
		) {$charset_collate};";

		/*
		 * The item column is indexed on its first 100 characters. Indexing all
		 * 191 would make the composite key (20 + 191) x 4 = 844 bytes on utf8mb4,
		 * over the 767-byte limit that applies to InnoDB before MySQL 5.7.7 —
		 * where dbDelta() would fail to create the index without saying so.
		 * A hundred characters is more than enough to separate plugin files.
		 */

		dbDelta( $sql );
	}

	/**
	 * Whether the table is there.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;

		// Memoised: this is consulted by every log query, including the one
		// behind the dashboard widget, and the answer cannot change mid-request.
		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists;
	}

	/**
	 * Append one event.
	 *
	 * @param array $event {
	 *     Event data.
	 *
	 *     @type string      $type           'plugin', 'theme', 'core' or 'translation'.
	 *     @type string      $item           Identifier.
	 *     @type string      $name           Display name.
	 *     @type string|null $from_version   Version before the update.
	 *     @type string|null $to_version     Version after the update.
	 *     @type string      $trigger_source 'auto', 'manual' or 'cli'.
	 *     @type string      $status         'success', 'failed' or 'rolled_back'.
	 *     @type string|null $message        Free text, usually an error.
	 *     @type int|null    $timestamp      Unix time; defaults to now.
	 * }
	 * @return int|false Insert id, or false on failure.
	 */
	public static function record( array $event ) {
		global $wpdb;

		$timestamp = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : time();

		$row = array(
			'occurred_at'    => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'type'           => self::truncate( (string) ( $event['type'] ?? '' ), 20 ),
			'item'           => self::truncate( (string) ( $event['item'] ?? '' ), 191 ),
			'name'           => self::truncate( (string) ( $event['name'] ?? '' ), 191 ),
			'from_version'   => self::truncate_or_null( $event['from_version'] ?? null, 32 ),
			'to_version'     => self::truncate_or_null( $event['to_version'] ?? null, 32 ),
			'trigger_source' => self::truncate( (string) ( $event['trigger_source'] ?? 'auto' ), 20 ),
			'status'         => self::truncate( (string) ( $event['status'] ?? 'success' ), 20 ),
			'message'        => isset( $event['message'] ) && '' !== $event['message'] ? (string) $event['message'] : null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			self::table_name(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete events older than the retention period.
	 *
	 * @return int Rows removed.
	 */
	public static function purge(): int {
		global $wpdb;

		$settings = Update_Pilot_Settings::get();
		$days     = (int) $settings['retention_days'];

		// Zero means keep everything.
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = self::table_name();

		/*
		 * The placeholder is not wrapped in quotes. Quoting a placeholder has
		 * triggered a _doing_it_wrong() notice since WordPress 6.2, and Companion
		 * Auto Update did it in twelve different places.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE occurred_at < %s", $cutoff ) );

		return (int) $deleted;
	}

	/**
	 * Cut a string to a column's length.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function truncate( string $value, int $length ): string {
		return mb_substr( $value, 0, $length );
	}

	/**
	 * Cut a string to length, preserving null.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Maximum length.
	 * @return string|null
	 */
	private static function truncate_or_null( $value, int $length ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return mb_substr( (string) $value, 0, $length );
	}
}
