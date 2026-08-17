<?php
/**
 * Reading the update log.
 *
 * Every value goes through $wpdb->prepare(), and every identifier — column
 * names, sort direction — comes from a hard-coded allow list. Nothing from the
 * query string is ever interpolated into SQL.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queries the update log.
 */
class Update_Pilot_Log_Repository {

	/**
	 * Columns a caller may sort on.
	 */
	private const SORTABLE = array( 'occurred_at', 'name', 'type', 'status' );

	/**
	 * Item types a caller may filter on.
	 */
	public const TYPES = array( 'plugin', 'theme', 'core', 'translation' );

	/**
	 * Statuses a caller may filter on.
	 */
	public const STATUSES = array( 'success', 'failed', 'rolled_back' );

	/**
	 * Fetch a page of events.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string $type     Filter by type.
	 *     @type string $status   Filter by status.
	 *     @type string $search   Match against name or item.
	 *     @type int    $paged    1-based page number.
	 *     @type int    $per_page Rows per page.
	 *     @type string $orderby  Column to sort on.
	 *     @type string $order    ASC or DESC.
	 * }
	 * @return array{items: array, total: int, pages: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$empty = array(
			'items' => array(),
			'total' => 0,
			'pages' => 0,
		);

		if ( ! Update_Pilot_Logger::table_exists() ) {
			return $empty;
		}

		$table = Update_Pilot_Logger::table_name();

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 25;
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		// Allow-listed identifiers only.
		$orderby = isset( $args['orderby'] ) && in_array( $args['orderby'], self::SORTABLE, true )
			? $args['orderby']
			: 'occurred_at';

		$order = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['type'] ) && in_array( $args['type'], self::TYPES, true ) ) {
			$where[]  = 'type = %s';
			$values[] = $args['type'];
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR item LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $values
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) )
			: $wpdb->get_var( $count_sql ) );

		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $rows_sql, array_merge( $values, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * The most recent events, for the dashboard widget.
	 *
	 * @param int $limit How many.
	 * @return array
	 */
	public static function recent( int $limit = 7 ): array {
		$result = self::query(
			array(
				'per_page' => $limit,
				'paged'    => 1,
			)
		);

		return $result['items'];
	}

	/**
	 * Counts by status over a recent period.
	 *
	 * @param int $days Window in days.
	 * @return array{success: int, failed: int, rolled_back: int}
	 */
	public static function status_counts( int $days = 30 ): array {
		global $wpdb;

		$counts = array(
			'success'     => 0,
			'failed'      => 0,
			'rolled_back' => 0,
		);

		if ( ! Update_Pilot_Logger::table_exists() ) {
			return $counts;
		}

		$table  = Update_Pilot_Logger::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE occurred_at >= %s GROUP BY status", $cutoff ),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );

			if ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Total number of recorded events.
	 *
	 * @return int
	 */
	public static function total(): int {
		$result = self::query( array( 'per_page' => 1 ) );

		return $result['total'];
	}

	/**
	 * A translated label for a type.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public static function type_label( string $type ): string {
		switch ( $type ) {
			case 'plugin':
				return __( 'Plugin', 'update-pilot' );

			case 'theme':
				return __( 'Theme', 'update-pilot' );

			case 'core':
				return __( 'WordPress', 'update-pilot' );

			case 'translation':
				return __( 'Translation', 'update-pilot' );

			default:
				return $type;
		}
	}

	/**
	 * A translated label for a status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'success':
				return __( 'Succeeded', 'update-pilot' );

			case 'failed':
				return __( 'Failed', 'update-pilot' );

			case 'rolled_back':
				return __( 'Rolled back', 'update-pilot' );

			default:
				return $status;
		}
	}

	/**
	 * A translated label for a trigger source.
	 *
	 * @param string $source Source slug.
	 * @return string
	 */
	public static function source_label( string $source ): string {
		switch ( $source ) {
			case 'auto':
				return __( 'Automatic', 'update-pilot' );

			case 'manual':
				return __( 'Manual', 'update-pilot' );

			case 'cli':
				return __( 'WP-CLI', 'update-pilot' );

			default:
				return $source;
		}
	}
}
