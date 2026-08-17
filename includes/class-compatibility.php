<?php
/**
 * "Tested up to" reporting.
 *
 * What this measures is a declaration, not a fact: every plugin on
 * wordpress.org carries a `Tested up to:` line that its author writes by hand.
 * Nobody verifies it. A plugin last tested against 6.2 may work perfectly on
 * 7.0, and one tested against 7.0 may be broken. What the figure really tells
 * you is whether anyone is still looking after the plugin — which matters on
 * the day a vulnerability is found in it.
 *
 * Three things Companion Auto Update got wrong here, all avoided below:
 *
 * - It compared versions by casting them to int, so (int) "7.0" - (int) "6.9"
 *   is 1 and a plugin one release behind was reported as abandoned, while
 *   6.9 against 6.4 gave 0 and nine releases of neglect went unmentioned.
 *   Branch distance cannot be subtracted: the sequence runs 6.8, 6.9, 7.0.
 *   It is counted here against the real list of WordPress releases.
 * - It called plugins_api() once per installed plugin, in series, with no cache,
 *   on every cron run. Here it happens once a day, in the plugin's own
 *   housekeeping slot, and the result is cached.
 * - It treated "no answer from wordpress.org" as "outdated", so every plugin
 *   distributed from GitHub was reported as abandoned. That is not a defect,
 *   it is an absence of data, and it is reported as such.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects and classifies the "tested up to" figure of every installed plugin.
 */
class Update_Pilot_Compatibility {

	/**
	 * Cached report.
	 */
	public const CACHE_KEY = 'update_pilot_compatibility';

	/**
	 * Cached list of WordPress branches.
	 */
	public const BRANCHES_KEY = 'update_pilot_wp_branches';

	/**
	 * How long a report stays fresh.
	 */
	public const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * How long the branch list stays fresh. It changes a few times a year.
	 */
	public const BRANCHES_TTL = WEEK_IN_SECONDS;

	/**
	 * How long to wait before retrying after a failed lookup.
	 */
	public const BACKOFF_TTL = HOUR_IN_SECONDS;

	/**
	 * Releases behind before a plugin is called out. Three is the rule
	 * wordpress.org applies on its own plugin pages.
	 */
	public const DEFAULT_THRESHOLD = 3;

	/**
	 * The author keeps the declaration up to date.
	 */
	public const CURRENT = 'current';

	/**
	 * The declaration is old enough to be worth mentioning.
	 */
	public const BEHIND = 'behind';

	/**
	 * The plugin is on wordpress.org but declares nothing.
	 */
	public const UNDECLARED = 'undeclared';

	/**
	 * The plugin is not on wordpress.org, so the question does not apply.
	 */
	public const NOT_HOSTED = 'not_hosted';

	/**
	 * The comparison could not be made.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Early in the daily slot, so the notifier can read a fresh report.
		add_action( Update_Pilot_Scheduler::DAILY_EVENT, array( __CLASS__, 'refresh' ), 5 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Classification — pure, and therefore testable
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The branch a version belongs to: 7.0.4 -> 7.0.
	 *
	 * @param string $version Version string.
	 * @return string
	 */
	public static function branch( string $version ): string {
		// 6.9-RC1 and 7.0-beta2 belong to branches 6.9 and 7.0. Without stripping
		// the suffix the branch never matches the release list and the whole
		// report collapses to "could not be compared".
		$version = preg_replace( '/[-+].*$/', '', trim( $version ) );

		$parts = explode( '.', (string) $version );

		return ( $parts[0] ?? '0' ) . '.' . ( $parts[1] ?? '0' );
	}

	/**
	 * Classify one declaration.
	 *
	 * @param string|null $tested         The "tested up to" value, or null.
	 * @param string      $current_branch The branch this site runs.
	 * @param string[]    $branches       Every WordPress branch, oldest first.
	 * @param int         $threshold      Releases behind before flagging.
	 * @return array{status: string, tested: string|null, behind: int|null}
	 */
	public static function classify( ?string $tested, string $current_branch, array $branches, int $threshold ): array {
		if ( null === $tested || '' === trim( (string) $tested ) ) {
			return self::verdict( self::UNDECLARED, null, null );
		}

		$tested_branch = self::branch( (string) $tested );

		if ( array() === $branches ) {
			// Without the release history there is no honest way to count.
			return self::verdict( self::UNKNOWN, $tested_branch, null );
		}

		$tested_index  = array_search( $tested_branch, $branches, true );
		$current_index = array_search( $current_branch, $branches, true );

		if ( false === $tested_index ) {
			/*
			 * A branch we have never heard of. If it sorts above the one this
			 * site runs, the author is simply ahead of us — some plugins declare
			 * the next release during its beta.
			 */
			return version_compare( $tested_branch, $current_branch, '>=' )
				? self::verdict( self::CURRENT, $tested_branch, 0 )
				: self::verdict( self::UNKNOWN, $tested_branch, null );
		}

		if ( false === $current_index ) {
			return self::verdict( self::UNKNOWN, $tested_branch, null );
		}

		$behind = $current_index - $tested_index;

		if ( $behind <= 0 ) {
			return self::verdict( self::CURRENT, $tested_branch, 0 );
		}

		return self::verdict(
			$behind >= $threshold ? self::BEHIND : self::CURRENT,
			$tested_branch,
			$behind
		);
	}

	/**
	 * Assemble a verdict.
	 *
	 * @param string      $status Status constant.
	 * @param string|null $tested Tested branch.
	 * @param int|null    $behind Releases behind.
	 * @return array{status: string, tested: string|null, behind: int|null}
	 */
	private static function verdict( string $status, ?string $tested, ?int $behind ): array {
		return array(
			'status' => $status,
			'tested' => $tested,
			'behind' => $behind,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Gathering
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The current report, from cache when it is fresh.
	 *
	 * @return array
	 */
	public static function report(): array {
		$cached = get_transient( self::CACHE_KEY );

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Whether a report has ever been built.
	 *
	 * @return bool
	 */
	public static function has_report(): bool {
		return is_array( get_transient( self::CACHE_KEY ) );
	}

	/**
	 * Ask wordpress.org about every installed plugin and cache the answers.
	 *
	 * Runs from the daily housekeeping event, which WordPress fires in a
	 * detached request, so the sequence of lookups never sits in front of a
	 * visitor's page load.
	 *
	 * @return array
	 */
	public static function refresh(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$branches       = self::branches();
		$current_branch = self::branch( (string) get_bloginfo( 'version' ) );

		/**
		 * Filter how many releases a plugin may fall behind before being flagged.
		 *
		 * @param int $threshold Number of WordPress releases.
		 */
		$threshold = max( 1, (int) apply_filters( 'update_pilot_untested_threshold', self::DEFAULT_THRESHOLD ) );

		$previous = self::report();
		$declared = self::declared_versions_from_transient();

		$report = array();

		foreach ( get_plugins() as $file => $data ) {
			$file = (string) $file;

			$row = array(
				'item'      => $file,
				'name'      => (string) ( $data['Name'] ?? $file ),
				'version'   => (string) ( $data['Version'] ?? '' ),
				'threshold' => $threshold,
			);

			// Free when WordPress already knows: plugins with a pending update
			// carry their "tested up to" figure in the update transient.
			if ( isset( $declared[ $file ] ) ) {
				$report[ $file ] = array_merge( $row, self::classify( $declared[ $file ], $current_branch, $branches, $threshold ) );
				continue;
			}

			$verdict = self::look_up( $file );

			if ( $verdict instanceof WP_Error ) {
				/*
				 * Two very different things arrive here as a WP_Error: a plugin
				 * that genuinely is not on wordpress.org, and wordpress.org being
				 * unreachable. Reporting the second as the first would relabel a
				 * whole site's plugins as "not hosted" during an outage, so only
				 * an answer that positively identifies a plugin as external
				 * counts as such. Anything else keeps whatever was known before.
				 */
				if ( 'update_pilot_not_hosted' === $verdict->get_error_code() ) {
					$report[ $file ] = array_merge( $row, self::verdict( self::NOT_HOSTED, null, null ) );
					continue;
				}

				$report[ $file ] = isset( $previous[ $file ] )
					? array_merge( $previous[ $file ], $row )
					: array_merge( $row, self::verdict( self::UNKNOWN, null, null ) );

				continue;
			}

			$report[ $file ] = array_merge( $row, self::classify( $verdict, $current_branch, $branches, $threshold ) );
		}

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * The "tested up to" figures WordPress already has on hand.
	 *
	 * Measured rather than assumed: on WordPress 7.0.4 the field is present on
	 * entries in the `response` bucket of the update transient — plugins with an
	 * update pending — and absent from `no_update`. So this saves a request for
	 * some plugins, not for all of them.
	 *
	 * @return array<string, string>
	 */
	private static function declared_versions_from_transient(): array {
		$found = array();

		$transient = get_site_transient( 'update_plugins' );

		if ( ! is_object( $transient ) ) {
			return $found;
		}

		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( empty( $transient->{$bucket} ) || ! is_array( $transient->{$bucket} ) ) {
				continue;
			}

			foreach ( $transient->{$bucket} as $file => $entry ) {
				$entry = is_array( $entry ) ? (object) $entry : $entry;

				if ( is_object( $entry ) && ! empty( $entry->tested ) ) {
					$found[ (string) $file ] = (string) $entry->tested;
				}
			}
		}

		return $found;
	}

	/**
	 * Ask wordpress.org for one plugin's declaration.
	 *
	 * @param string $file Plugin file.
	 * @return string|null|WP_Error The declared version, null when absent, or an
	 *                              error when the plugin is not hosted there.
	 */
	private static function look_up( string $file ) {
		$directory = dirname( $file );
		$slug      = ( '.' === $directory ) ? basename( $file, '.php' ) : $directory;

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'     => false,
					'ratings'      => false,
					'contributors' => false,
					'banners'      => false,
					'icons'        => false,
					'tested'       => true,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		/*
		 * A plugin that ships its own GitHub updater answers plugins_api itself
		 * — this plugin does exactly that — and marks the payload `external`.
		 * The reply is its own, not wordpress.org's, so it says nothing about
		 * whether anyone is still maintaining it.
		 */
		if ( is_object( $api ) && ! empty( $api->external ) ) {
			return new WP_Error( 'update_pilot_not_hosted', 'Answered locally by the plugin itself.' );
		}

		if ( is_object( $api ) && ! empty( $api->tested ) ) {
			return (string) $api->tested;
		}

		return null;
	}

	/**
	 * Every WordPress branch ever released, oldest first.
	 *
	 * Read from the stable-check endpoint, which returns the complete version
	 * history in one request. That is the only way to count releases correctly:
	 * 6.8, 6.9 and 7.0 are consecutive, and no arithmetic on those numbers can
	 * know it.
	 *
	 * @return string[] Empty when the list could not be fetched.
	 */
	public static function branches(): array {
		$cached = get_transient( self::BRANCHES_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.wordpress.org/core/stable-check/1.0/',
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Back off rather than hammer a service that is having a bad day.
			set_transient( self::BRANCHES_KEY, array(), self::BACKOFF_TTL );

			return array();
		}

		$versions = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $versions ) || array() === $versions ) {
			set_transient( self::BRANCHES_KEY, array(), self::BACKOFF_TTL );

			return array();
		}

		$branches = array();

		foreach ( array_keys( $versions ) as $version ) {
			$branches[ self::branch( (string) $version ) ] = true;
		}

		$list = array_keys( $branches );
		usort( $list, 'version_compare' );

		set_transient( self::BRANCHES_KEY, $list, self::BRANCHES_TTL );

		return $list;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Presentation helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Count each status in a report.
	 *
	 * @param array $report Report.
	 * @return array<string, int>
	 */
	public static function summary( array $report ): array {
		$counts = array(
			self::CURRENT    => 0,
			self::BEHIND     => 0,
			self::UNDECLARED => 0,
			self::NOT_HOSTED => 0,
			self::UNKNOWN    => 0,
		);

		foreach ( $report as $row ) {
			$status = (string) ( $row['status'] ?? self::UNKNOWN );

			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * The rows worth showing: anything that is not simply up to date.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	public static function noteworthy( array $report ): array {
		$rows = array_filter(
			$report,
			static fn( $row ) => self::CURRENT !== ( $row['status'] ?? '' )
		);

		// Most neglected first, then the ones with nothing declared.
		uasort(
			$rows,
			static function ( $a, $b ) {
				$order = array(
					self::BEHIND     => 0,
					self::UNDECLARED => 1,
					self::UNKNOWN    => 2,
					self::NOT_HOSTED => 3,
				);

				$rank = ( $order[ $a['status'] ] ?? 9 ) <=> ( $order[ $b['status'] ] ?? 9 );

				if ( 0 !== $rank ) {
					return $rank;
				}

				return ( (int) ( $b['behind'] ?? 0 ) ) <=> ( (int) ( $a['behind'] ?? 0 ) );
			}
		);

		return $rows;
	}

	/**
	 * A sentence describing one row.
	 *
	 * @param array $row Report row.
	 * @return string
	 */
	public static function describe( array $row ): string {
		$status = (string) ( $row['status'] ?? self::UNKNOWN );
		$tested = $row['tested'] ?? null;
		$behind = $row['behind'] ?? null;

		switch ( $status ) {
			case self::BEHIND:
				return sprintf(
					/* translators: 1: WordPress version, 2: number of releases. */
					_n(
						'Tested up to WordPress %1$s — %2$d release behind',
						'Tested up to WordPress %1$s — %2$d releases behind',
						(int) $behind,
						'update-pilot'
					),
					$tested,
					(int) $behind
				);

			case self::UNDECLARED:
				return __( 'No "tested up to" version declared by the author', 'update-pilot' );

			case self::NOT_HOSTED:
				return __( 'Not hosted on wordpress.org, so there is no declaration to read', 'update-pilot' );

			case self::UNKNOWN:
				return null === $tested
					? __( 'Could not be checked', 'update-pilot' )
					: sprintf(
						/* translators: %s: WordPress version. */
						__( 'Tested up to WordPress %s — could not be compared', 'update-pilot' ),
						$tested
					);

			default:
				return sprintf(
					/* translators: %s: WordPress version. */
					__( 'Tested up to WordPress %s', 'update-pilot' ),
					$tested
				);
		}
	}

	/**
	 * A stable fingerprint of the flagged plugins, so a notification is only
	 * sent when the situation actually changes.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function fingerprint( array $report ): string {
		$flagged = array();

		foreach ( $report as $file => $row ) {
			if ( in_array( $row['status'] ?? '', array( self::BEHIND, self::UNDECLARED ), true ) ) {
				$flagged[] = $file . ':' . $row['status'] . ':' . (string) ( $row['tested'] ?? '' );
			}
		}

		sort( $flagged );

		return md5( implode( '|', $flagged ) );
	}
}
