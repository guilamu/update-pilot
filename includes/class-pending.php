<?php
/**
 * What is on offer, and what the policy says about it.
 *
 * Three screens ask the same question — this update is available, so why has it
 * not been installed? — and three separate answers would drift apart within a
 * release or two. They all come from here.
 *
 * This class reads. It never records a sighting, never writes an option and
 * never makes an HTTP request of its own: the update transients core keeps
 * fresh are the only source. That matters more than it looks. Rendering a
 * screen or composing an e-mail must not start a safety delay's clock, or the
 * countdown would begin when somebody happened to look rather than when the
 * update check actually saw the version. Update_Pilot_Policy::evaluate() is
 * therefore called directly; Update_Pilot_Policy_Filters::decide() — which does
 * record sightings, correctly, for the filter it serves — is never called from
 * here.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects every offered update together with the policy's verdict on it.
 */
class Update_Pilot_Pending {

	/**
	 * Every update WordPress currently has on offer, with the verdict.
	 *
	 * @return array<int, array{
	 *     type: string,
	 *     item: string,
	 *     name: string,
	 *     from_version: string|null,
	 *     to_version: string|null,
	 *     reason: string,
	 *     decision: string,
	 *     expires_at: int|null,
	 *     days_remaining: int|null
	 * }> Plugins, then themes, then core.
	 */
	public static function all(): array {
		$settings = Update_Pilot_Settings::get();
		$now      = Update_Pilot_Policy_Filters::now();

		return array_merge(
			self::plugins( $settings, $now ),
			self::themes( $settings, $now ),
			self::core( $settings, $now )
		);
	}

	/**
	 * The offered updates of one type, keyed by identifier.
	 *
	 * Callers rendering a table of installed items look each row up rather than
	 * evaluating it: one pass over the transient instead of one evaluation per
	 * line on the page.
	 *
	 * @param array  $rows Rows from all().
	 * @param string $type Item type to keep.
	 * @return array<string, array> Identifier => row.
	 */
	public static function index( array $rows, string $type ): array {
		$indexed = array();

		foreach ( $rows as $row ) {
			if ( $type === ( $row['type'] ?? '' ) ) {
				$indexed[ (string) $row['item'] ] = $row;
			}
		}

		return $indexed;
	}

	/**
	 * Whether the policy is holding an item back.
	 *
	 * A row the policy defers on — an unmanaged type — is not held by us: the
	 * update is simply WordPress's business, and it may well install it.
	 *
	 * @param array $row Row from all().
	 * @return bool
	 */
	public static function is_held( array $row ): bool {
		return Update_Pilot_Policy::DENY === ( $row['decision'] ?? '' );
	}

	/**
	 * Why an item has not been installed, as a short phrase.
	 *
	 * Returns plain text, unescaped: the Exclusions column, the Status screen and
	 * the daily e-mail all say the same thing, and the caller escapes at its own
	 * point of output. The phrase is deliberately a fragment rather than a
	 * sentence, because it is read inside a table cell or after a dash.
	 *
	 * Only the first reason applies — see row() — so this is never a list.
	 *
	 * @param array $row Row from all().
	 * @return string Empty when there is nothing to explain.
	 */
	public static function describe( array $row ): string {
		switch ( $row['reason'] ?? '' ) {
			case 'excluded':
				return __( 'excluded', 'update-pilot' );

			case 'outside_window':
				return __( 'waiting for the maintenance window', 'update-pilot' );

			case 'delayed':
				return self::describe_delay( $row );

			case 'delay_pending_first_sighting':
				return __( 'held, delay starting', 'update-pilot' );

			case 'core_branch_disabled':
				return __( 'this branch of WordPress is not updated automatically', 'update-pilot' );

			case 'withdrawn':
				return __( 'withdrawn by wordpress.org', 'update-pilot' );

			case 'unmanaged':
				return __( 'left to WordPress', 'update-pilot' );

			default:
				return '';
		}
	}

	/**
	 * The safety delay, as a date and a countdown.
	 *
	 * Both, on purpose. The countdown answers "how long do I wait?" and the date
	 * answers "will it land before I go away?" — and only one of the two is
	 * still true tomorrow.
	 *
	 * @param array $row Row from all().
	 * @return string
	 */
	private static function describe_delay( array $row ): string {
		if ( null === ( $row['expires_at'] ?? null ) ) {
			return __( 'held, delay starting', 'update-pilot' );
		}

		$held = sprintf(
			/* translators: %s: date and time. */
			__( 'held until %s', 'update-pilot' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['expires_at'] )
		);

		$days = (int) ( $row['days_remaining'] ?? 0 );

		/*
		 * A delay that has run out is reported as elapsed rather than delayed, so
		 * zero only shows up on the boundary itself. "0 days left" would still be
		 * the wrong thing to print there: the wait is over and what remains is the
		 * next eligible run.
		 */
		$countdown = $days > 0
			? sprintf(
				/* translators: %d: number of days. */
				_n( '%d day left', '%d days left', $days, 'update-pilot' ),
				$days
			)
			: __( 'due now', 'update-pilot' );

		return $held . ' — ' . $countdown;
	}

	/**
	 * A plugin's display name.
	 *
	 * @param string $file Plugin file, e.g. 'hello-dolly/hello.php'.
	 * @return string The file itself when the plugin cannot be read.
	 */
	public static function plugin_name( string $file ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$path = WP_PLUGIN_DIR . '/' . $file;

		if ( ! file_exists( $path ) ) {
			return $file;
		}

		$data = get_plugin_data( $path, false, false );

		return empty( $data['Name'] ) ? $file : (string) $data['Name'];
	}

	/**
	 * A theme's display name.
	 *
	 * @param string $stylesheet Stylesheet directory.
	 * @return string The stylesheet itself when the theme is not installed.
	 */
	public static function theme_name( string $stylesheet ): string {
		$theme = wp_get_theme( $stylesheet );

		return $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Per type
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Plugin updates on offer.
	 *
	 * @param array             $settings Plugin settings.
	 * @param DateTimeImmutable $now      Current time.
	 * @return array
	 */
	private static function plugins( array $settings, DateTimeImmutable $now ): array {
		$transient = get_site_transient( 'update_plugins' );

		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		$rows = array();

		foreach ( $transient->response as $file => $offer ) {
			$file  = (string) $file;
			$offer = is_array( $offer ) ? (object) $offer : $offer;

			$rows[] = self::row(
				'plugin',
				$file,
				self::plugin_name( $file ),
				Update_Pilot_Listeners::installed_version( 'plugin', $file ),
				isset( $offer->new_version ) ? (string) $offer->new_version : null,
				$offer,
				$settings,
				$now
			);
		}

		return $rows;
	}

	/**
	 * Theme updates on offer.
	 *
	 * @param array             $settings Plugin settings.
	 * @param DateTimeImmutable $now      Current time.
	 * @return array
	 */
	private static function themes( array $settings, DateTimeImmutable $now ): array {
		$transient = get_site_transient( 'update_themes' );

		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		$rows = array();

		foreach ( $transient->response as $stylesheet => $offer ) {
			$stylesheet = (string) $stylesheet;
			$offer      = is_array( $offer ) ? (object) $offer : $offer;

			$rows[] = self::row(
				'theme',
				$stylesheet,
				self::theme_name( $stylesheet ),
				Update_Pilot_Listeners::installed_version( 'theme', $stylesheet ),
				isset( $offer->new_version ) ? (string) $offer->new_version : null,
				$offer,
				$settings,
				$now
			);
		}

		return $rows;
	}

	/**
	 * The core update on offer, if there is one.
	 *
	 * The transient lists every offer core knows about, including the branch the
	 * site is already on; only the first one marked for upgrade is a pending
	 * update.
	 *
	 * @param array             $settings Plugin settings.
	 * @param DateTimeImmutable $now      Current time.
	 * @return array
	 */
	private static function core( array $settings, DateTimeImmutable $now ): array {
		$transient = get_site_transient( 'update_core' );

		if ( ! is_object( $transient ) || empty( $transient->updates ) || ! is_array( $transient->updates ) ) {
			return array();
		}

		foreach ( $transient->updates as $offer ) {
			$offer = is_array( $offer ) ? (object) $offer : $offer;

			if ( ! is_object( $offer ) || 'upgrade' !== ( $offer->response ?? '' ) ) {
				continue;
			}

			return array(
				self::row(
					'core',
					'core',
					'WordPress',
					(string) get_bloginfo( 'version' ),
					isset( $offer->current ) ? (string) $offer->current : null,
					$offer,
					$settings,
					$now
				),
			);
		}

		return array();
	}

	/*
	 * ---------------------------------------------------------------------
	 * One row
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether wordpress.org has withdrawn this release.
	 *
	 * The flag is set on a release that has been pulled or found harmful, and
	 * Update_Pilot_Policy_Filters::decide() refuses it before the policy is even
	 * consulted. Reporting such an item as eligible would be the one thing these
	 * screens exist to prevent: a truthful answer to "why has this not
	 * installed?".
	 *
	 * @param mixed $offer Raw offer from the transient.
	 * @return bool
	 */
	private static function withdrawn( $offer ): bool {
		return is_object( $offer ) && ! empty( $offer->disable_autoupdate );
	}

	/**
	 * Evaluate one offer and shape it into a row.
	 *
	 * The reason is the *first* rule that refuses, not every rule that would:
	 * an item outside the maintenance window and still inside its safety delay
	 * reports the window, because that is the order evaluate() applies. Callers
	 * should say "held back because…", never present it as a complete list.
	 *
	 * @param string            $type     Item type.
	 * @param string            $item     Identifier.
	 * @param string            $name     Display name, unescaped.
	 * @param string|null       $from     Installed version.
	 * @param string|null       $to       Offered version.
	 * @param mixed             $offer    Raw offer from the transient.
	 * @param array             $settings Plugin settings.
	 * @param DateTimeImmutable $now      Current time.
	 * @return array
	 */
	private static function row( string $type, string $item, string $name, ?string $from, ?string $to, $offer, array $settings, DateTimeImmutable $now ): array {
		$normalised = Update_Pilot_Policy_Filters::normalise( $type, $offer );

		$verdict = self::withdrawn( $offer )
			? array(
				'decision' => Update_Pilot_Policy::DENY,
				'reason'   => 'withdrawn',
			)
			: Update_Pilot_Policy::evaluate( $normalised, $settings, $now );

		$expires_at     = null;
		$days_remaining = null;

		if ( 'delayed' === $verdict['reason'] && ! empty( $normalised['first_seen'] ) ) {
			$first_seen     = (int) $normalised['first_seen'];
			$expires_at     = Update_Pilot_Policy::delay_expires_at( $first_seen, $settings );
			$days_remaining = Update_Pilot_Policy::days_remaining( $first_seen, $settings, $now );
		}

		return array(
			'type'           => $type,
			'item'           => $item,
			'name'           => $name,
			'from_version'   => $from,
			'to_version'     => $to,
			'reason'         => (string) $verdict['reason'],
			'decision'       => (string) $verdict['decision'],
			'expires_at'     => $expires_at,
			'days_remaining' => $days_remaining,
		);
	}
}
