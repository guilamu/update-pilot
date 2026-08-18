<?php
/**
 * The eligibility engine.
 *
 * This file holds two classes with deliberately different natures:
 *
 * - Update_Pilot_Policy is pure. It calls no WordPress function, reads no
 *   option, and never asks the system what time it is: it is handed the
 *   settings, a normalised item and a DateTimeImmutable, and it answers. That
 *   is what makes it testable with plain PHP, and it is exactly where Companion
 *   Auto Update went wrong — its comparable logic could only be exercised by
 *   running a real site and waiting for a real update.
 *
 * - Update_Pilot_Policy_Filters is the WordPress glue: it hooks the six core
 *   filters, normalises whatever core hands it, and delegates every decision to
 *   the pure class.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an update may be installed right now, and says why not.
 */
class Update_Pilot_Policy {

	/**
	 * Seconds in a day. Deliberately not DAY_IN_SECONDS: this class must run
	 * without WordPress loaded.
	 */
	public const DAY = 86400;

	/**
	 * Update Pilot says yes.
	 */
	public const ALLOW = 'allow';

	/**
	 * Update Pilot says no.
	 */
	public const DENY = 'deny';

	/**
	 * Update Pilot has no opinion; whatever WordPress decided stands.
	 */
	public const DEFER = 'defer';

	/**
	 * Evaluate one item.
	 *
	 * @param array             $item     {
	 *     Normalised item.
	 *
	 *     @type string      $type       'plugin', 'theme', 'core' or 'translation'.
	 *     @type string      $id         'akismet/akismet.php', 'twentytwentyone', 'core'.
	 *     @type string|null $version    Offered version, when known.
	 *     @type int|null    $first_seen Timestamp this version was first offered.
	 *     @type string|null $branch     'minor', 'major' or 'dev' — core only.
	 * }
	 * @param array             $settings Plugin settings.
	 * @param DateTimeImmutable $now      Current time, in the site's timezone.
	 * @return array{decision: string, reason: string} Reason is a stable slug, for the UI to translate.
	 */
	public static function evaluate( array $item, array $settings, DateTimeImmutable $now ): array {
		$type = $item['type'] ?? '';
		$id   = (string) ( $item['id'] ?? '' );

		// 1. Is this type managed at all?
		if ( ! self::type_is_managed( $type, $settings ) ) {
			return self::result( self::DEFER, 'unmanaged' );
		}

		/*
		 * 2. Translations have no exclusion list, just a single switch. Unticking
		 * it has to mean "no" rather than "no opinion", otherwise the setting is
		 * written to the database and never consulted — which is precisely how
		 * Companion Auto Update's theme list came to do nothing for six years.
		 */
		if ( 'translation' === $type && empty( $settings['translations'] ) ) {
			return self::result( self::DENY, 'type_disabled' );
		}

		// 3. Explicit exclusion always wins.
		if ( self::is_excluded( $type, $id, $settings ) ) {
			return self::result( self::DENY, 'excluded' );
		}

		// 4. Core branch policy (minor / major / development).
		if ( 'core' === $type && isset( $item['branch'] ) ) {
			if ( ! self::core_branch_allowed( (string) $item['branch'], $settings ) ) {
				return self::result( self::DENY, 'core_branch_disabled' );
			}
		}

		// 5. Maintenance window.
		if ( ! self::in_window( $settings['window'] ?? array(), $now ) ) {
			return self::result( self::DENY, 'outside_window' );
		}

		// 6. Safety delay.
		$delay = self::delay_state( $item, $settings, $now );

		if ( 'waiting' === $delay ) {
			return self::result( self::DENY, 'delayed' );
		}

		if ( 'unknown' === $delay ) {
			// The version has never been observed. Deny once; the caller records
			// the sighting, and the countdown starts from now.
			return self::result( self::DENY, 'delay_pending_first_sighting' );
		}

		return self::result( self::ALLOW, 'allowed' );
	}

	/**
	 * Whether Update Pilot manages this type of update at all.
	 *
	 * When it does not, the plugin steps aside entirely instead of forcing a
	 * "no". Companion Auto Update forced `plugins_auto_update_enabled` to false,
	 * which hid the native column and left its own list and the option core
	 * consults disagreeing with each other, invisibly.
	 *
	 * @param string $type     Item type.
	 * @param array  $settings Plugin settings.
	 * @return bool
	 */
	public static function type_is_managed( string $type, array $settings ): bool {
		switch ( $type ) {
			case 'plugin':
				return ! empty( $settings['plugins']['enabled'] );

			case 'theme':
				return ! empty( $settings['themes']['enabled'] );

			case 'translation':
				return true;

			case 'core':
				return true;

			default:
				return false;
		}
	}

	/**
	 * Whether an item sits on an exclusion list.
	 *
	 * The identifier is compared as a whole string. For plugins that is
	 * `folder/file.php`; for themes it is the stylesheet directory. Getting the
	 * theme identifier wrong is precisely the bug that silently disabled theme
	 * exclusions in Companion Auto Update from March 2020 onwards.
	 *
	 * @param string $type     Item type.
	 * @param string $id       Item identifier.
	 * @param array  $settings Plugin settings.
	 * @return bool
	 */
	public static function is_excluded( string $type, string $id, array $settings ): bool {
		if ( '' === $id ) {
			return false;
		}

		switch ( $type ) {
			case 'plugin':
				$list = $settings['plugins']['excluded'] ?? array();
				break;

			case 'theme':
				$list = $settings['themes']['excluded'] ?? array();
				break;

			default:
				return false;
		}

		return in_array( $id, (array) $list, true );
	}

	/**
	 * Whether a core branch may update.
	 *
	 * @param string $branch 'minor', 'major' or 'dev'.
	 * @param array  $settings Plugin settings.
	 * @return bool
	 */
	public static function core_branch_allowed( string $branch, array $settings ): bool {
		$core = $settings['core'] ?? array();

		switch ( $branch ) {
			case 'minor':
				return ! empty( $core['minor'] );

			case 'major':
				return ! empty( $core['major'] );

			case 'dev':
				return ! empty( $core['dev'] );

			default:
				return false;
		}
	}

	/**
	 * Whether the current moment falls inside the maintenance window.
	 *
	 * Two things this handles that Companion Auto Update did not:
	 *
	 * - A window that crosses midnight. CAU compared `Hi`-formatted strings with
	 *   no notion of a day, so its window broke every night between 23:30 and
	 *   00:30 — the very hours it recommended using.
	 * - Weekdays. For a window that crosses midnight, the day that counts is the
	 *   day the window opened, so a Friday-night window still applies at 01:00 on
	 *   Saturday morning.
	 *
	 * @param array             $window Window settings.
	 * @param DateTimeImmutable $now    Current time, in the site's timezone.
	 * @return bool
	 */
	public static function in_window( array $window, DateTimeImmutable $now ): bool {
		if ( empty( $window['enabled'] ) ) {
			return true;
		}

		$start = (int) ( $window['start_hour'] ?? 0 );
		$end   = (int) ( $window['end_hour'] ?? 0 );
		$hour  = (int) $now->format( 'G' );

		// Same start and end means "all day".
		if ( $start === $end ) {
			$in_hours = true;
			$weekday  = (int) $now->format( 'w' );
		} elseif ( $start < $end ) {
			$in_hours = ( $hour >= $start && $hour < $end );
			$weekday  = (int) $now->format( 'w' );
		} else {
			// Crosses midnight.
			$in_hours = ( $hour >= $start || $hour < $end );

			// Past midnight, the window belongs to the previous day.
			$weekday = ( $hour < $end )
				? ( ( (int) $now->format( 'w' ) + 6 ) % 7 )
				: (int) $now->format( 'w' );
		}

		if ( ! $in_hours ) {
			return false;
		}

		$weekdays = $window['weekdays'] ?? array( 0, 1, 2, 3, 4, 5, 6 );

		if ( ! is_array( $weekdays ) || array() === $weekdays ) {
			return true;
		}

		return in_array( $weekday, array_map( 'intval', $weekdays ), true );
	}

	/**
	 * Where an item stands with respect to the safety delay.
	 *
	 * @param array             $item     Normalised item.
	 * @param array             $settings Plugin settings.
	 * @param DateTimeImmutable $now      Current time.
	 * @return string 'not_applicable', 'elapsed', 'waiting' or 'unknown'.
	 */
	public static function delay_state( array $item, array $settings, DateTimeImmutable $now ): string {
		$delay = $settings['delay'] ?? array();

		if ( empty( $delay['enabled'] ) ) {
			return 'not_applicable';
		}

		$target = self::delay_target( (string) ( $item['type'] ?? '' ) );

		if ( null === $target || ! in_array( $target, (array) ( $delay['applies_to'] ?? array() ), true ) ) {
			return 'not_applicable';
		}

		$first_seen = $item['first_seen'] ?? null;

		if ( empty( $first_seen ) ) {
			return 'unknown';
		}

		$days = max( 0, (int) ( $delay['days'] ?? 0 ) );

		if ( 0 === $days ) {
			return 'elapsed';
		}

		return ( $now->getTimestamp() - (int) $first_seen ) >= ( $days * self::DAY )
			? 'elapsed'
			: 'waiting';
	}

	/**
	 * When a delayed item becomes eligible.
	 *
	 * @param int   $first_seen Timestamp of first sighting.
	 * @param array $settings   Plugin settings.
	 * @return int Timestamp.
	 */
	public static function delay_expires_at( int $first_seen, array $settings ): int {
		$days = max( 0, (int) ( $settings['delay']['days'] ?? 0 ) );

		return $first_seen + ( $days * self::DAY );
	}

	/**
	 * How many whole days a delayed item still has to wait.
	 *
	 * Rounded up, deliberately. A seven-day delay armed ten minutes ago has six
	 * days and twenty-three hours to run: "7 days left" is the honest answer,
	 * floor() would claim 6, and round() would flip between the two halfway
	 * through every day. The caller does the wording, including the case where
	 * this returns 0 — the wait is over but the next eligible run has not
	 * happened yet.
	 *
	 * @param int               $first_seen Timestamp of first sighting.
	 * @param array             $settings   Plugin settings.
	 * @param DateTimeImmutable $now        Current time.
	 * @return int Never negative.
	 */
	public static function days_remaining( int $first_seen, array $settings, DateTimeImmutable $now ): int {
		$seconds = self::delay_expires_at( $first_seen, $settings ) - $now->getTimestamp();

		return $seconds <= 0 ? 0 : (int) ceil( $seconds / self::DAY );
	}

	/**
	 * Map an item type to the key used in the delay's applies_to list.
	 *
	 * @param string $type Item type.
	 * @return string|null
	 */
	private static function delay_target( string $type ): ?string {
		switch ( $type ) {
			case 'plugin':
				return 'plugins';

			case 'theme':
				return 'themes';

			case 'core':
				return 'core';

			default:
				// Translations are never delayed: they carry no executable code.
				return null;
		}
	}

	/**
	 * Build a result array.
	 *
	 * @param string $decision One of ALLOW, DENY, DEFER.
	 * @param string $reason   Stable reason slug.
	 * @return array{decision: string, reason: string}
	 */
	private static function result( string $decision, string $reason ): array {
		return array(
			'decision' => $decision,
			'reason'   => $reason,
		);
	}
}

/**
 * Binds the core eligibility filters to the policy engine.
 *
 * The identifiers used below were established by observation on a real site
 * (WordPress 7.0.4), not from documentation:
 *
 *   auto_update_plugin       $item->plugin  e.g. 'hello-dolly/hello.php'
 *   auto_update_theme        $item->theme   e.g. 'twentytwentyone'   (no ->slug!)
 *   auto_update_core         $item->current e.g. '7.0.4'
 *   auto_update_translation  $item->type / ->slug / ->language
 *
 * Core itself uses `$item->{$type}` to look an item up in the native
 * auto_update_{$type}s option, which is the authoritative confirmation. A theme
 * item carries no `slug` property at all: reading one yields null, every
 * comparison fails, and theme exclusions quietly stop working. That is the bug
 * that sat in Companion Auto Update from March 2020 until today.
 */
class Update_Pilot_Policy_Filters {

	/**
	 * Register the six eligibility filters.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'auto_update_plugin', array( __CLASS__, 'filter_plugin' ), 10, 2 );
		add_filter( 'auto_update_theme', array( __CLASS__, 'filter_theme' ), 10, 2 );
		add_filter( 'auto_update_core', array( __CLASS__, 'filter_core' ), 10, 2 );
		add_filter( 'auto_update_translation', array( __CLASS__, 'filter_translation' ), 10, 2 );

		add_filter( 'allow_minor_auto_core_updates', array( __CLASS__, 'filter_core_minor' ) );
		add_filter( 'allow_major_auto_core_updates', array( __CLASS__, 'filter_core_major' ) );
		add_filter( 'allow_dev_auto_core_updates', array( __CLASS__, 'filter_core_dev' ) );

		// Record the first time each offered version is seen — the delay clock.
		add_action( 'set_site_transient_update_plugins', array( __CLASS__, 'record_plugin_sightings' ) );
		add_action( 'set_site_transient_update_themes', array( __CLASS__, 'record_theme_sightings' ) );
		add_action( 'set_site_transient_update_core', array( __CLASS__, 'record_core_sightings' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Filters
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Eligibility of a plugin update.
	 *
	 * @param bool|null $update Whether WordPress intends to update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public static function filter_plugin( $update, $item ) {
		return self::decide( 'plugin', $update, $item );
	}

	/**
	 * Eligibility of a theme update.
	 *
	 * @param bool|null    $update Whether WordPress intends to update.
	 * @param object|array $item   Update offer.
	 * @return bool|null
	 */
	public static function filter_theme( $update, $item ) {
		return self::decide( 'theme', $update, $item );
	}

	/**
	 * Eligibility of a translation update.
	 *
	 * @param bool|null $update Whether WordPress intends to update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public static function filter_translation( $update, $item ) {
		return self::decide( 'translation', $update, $item );
	}

	/**
	 * Eligibility of a core update.
	 *
	 * Restrict-only on purpose: this filter may turn a yes into a no, never a no
	 * into a yes. Whether core updates at all is decided by the branch filters
	 * below and, above them, by the WP_AUTO_UPDATE_CORE constant. Promoting a
	 * refusal here would silently overrule wp-config.php.
	 *
	 * @param bool|null $update Whether WordPress intends to update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public static function filter_core( $update, $item ) {
		$decision = self::decide( 'core', $update, $item );

		return false === $decision ? false : $update;
	}

	/**
	 * Minor core updates.
	 *
	 * @param bool $enabled Current value.
	 * @return bool
	 */
	public static function filter_core_minor( $enabled ): bool {
		return self::core_branch_setting( 'minor', $enabled );
	}

	/**
	 * Major core updates.
	 *
	 * @param bool $enabled Current value.
	 * @return bool
	 */
	public static function filter_core_major( $enabled ): bool {
		return self::core_branch_setting( 'major', $enabled );
	}

	/**
	 * Development core updates.
	 *
	 * @param bool $enabled Current value.
	 * @return bool
	 */
	public static function filter_core_dev( $enabled ): bool {
		return self::core_branch_setting( 'dev', $enabled );
	}

	/**
	 * Resolve a core branch setting, leaving wp-config.php the last word.
	 *
	 * When WP_AUTO_UPDATE_CORE is defined, core has already derived $enabled from
	 * it, and we hand that value straight back. An administrator who wrote a
	 * constant into wp-config.php meant it; the Status page explains that it is
	 * in charge and that the on-screen core options are inert.
	 *
	 * @param string $branch  'minor', 'major' or 'dev'.
	 * @param bool   $enabled Value computed by core.
	 * @return bool
	 */
	private static function core_branch_setting( string $branch, $enabled ): bool {
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			return (bool) $enabled;
		}

		$settings = Update_Pilot_Settings::get();

		return ! empty( $settings['core'][ $branch ] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Decision plumbing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Normalise an item, evaluate it, and translate the verdict into what the
	 * filter must return.
	 *
	 * @param string $type   Item type.
	 * @param mixed  $update Value passed by WordPress.
	 * @param mixed  $item   Update offer.
	 * @return mixed
	 */
	private static function decide( string $type, $update, $item ) {
		/*
		 * wordpress.org sets disable_autoupdate on a release that has been pulled
		 * or found harmful. Core honours it just before calling this filter, and
		 * its own comment says the flag "overrides any user-choice, but allows
		 * filters" — so returning true here would push out a release the people
		 * who published it have withdrawn.
		 *
		 * Update Pilot decides what may update; it does not overrule that. The
		 * flag can only ever mean no.
		 */
		$offer = is_array( $item ) ? (object) $item : $item;

		if ( is_object( $offer ) && ! empty( $offer->disable_autoupdate ) ) {
			return false;
		}

		$normalised = self::normalise( $type, $item );
		$settings   = Update_Pilot_Settings::get();

		$verdict = Update_Pilot_Policy::evaluate( $normalised, $settings, self::now() );

		/**
		 * Filter the eligibility verdict for one item.
		 *
		 * @param array $verdict    { 'decision' => 'allow'|'deny'|'defer', 'reason' => string }.
		 * @param array $normalised Normalised item: type, id, version, branch, first_seen.
		 * @param array $settings   Plugin settings.
		 */
		$verdict = apply_filters( 'update_pilot_decision', $verdict, $normalised, $settings );

		if ( ! is_array( $verdict ) || ! isset( $verdict['decision'] ) ) {
			return $update;
		}

		/*
		 * The delay is armed but this exact version has never been recorded.
		 * Record it now so the countdown starts, and hold the update back this
		 * once. Every subsequent check reads a real timestamp.
		 */
		if ( 'delay_pending_first_sighting' === ( $verdict['reason'] ?? '' ) && ! empty( $normalised['version'] ) ) {
			self::record_sighting( $type, (string) $normalised['id'], (string) $normalised['version'] );
		}

		if ( Update_Pilot_Policy::ALLOW === $verdict['decision'] ) {
			return true;
		}

		if ( Update_Pilot_Policy::DENY === $verdict['decision'] ) {
			return false;
		}

		return $update;
	}

	/**
	 * Turn whatever core passes into the flat array the policy understands.
	 *
	 * @param string $type Item type.
	 * @param mixed  $item Update offer.
	 * @return array
	 */
	public static function normalise( string $type, $item ): array {
		// Theme offers reach us as arrays cast to objects; be tolerant of both.
		$item = is_array( $item ) ? (object) $item : $item;

		$id      = '';
		$version = null;
		$branch  = null;

		if ( is_object( $item ) ) {
			switch ( $type ) {
				case 'plugin':
					$id      = isset( $item->plugin ) ? (string) $item->plugin : '';
					$version = isset( $item->new_version ) ? (string) $item->new_version : null;
					break;

				case 'theme':
					$id      = isset( $item->theme ) ? (string) $item->theme : '';
					$version = isset( $item->new_version ) ? (string) $item->new_version : null;
					break;

				case 'core':
					$id      = 'core';
					$version = isset( $item->current ) ? (string) $item->current : ( isset( $item->version ) ? (string) $item->version : null );
					$branch  = null === $version ? null : self::core_branch( $version );
					break;

				case 'translation':
					$id = sprintf(
						'%s:%s:%s',
						isset( $item->type ) ? (string) $item->type : 'core',
						isset( $item->slug ) ? (string) $item->slug : '',
						isset( $item->language ) ? (string) $item->language : ''
					);
					$version = isset( $item->version ) ? (string) $item->version : null;
					break;
			}
		}

		return array(
			'type'       => $type,
			'id'         => $id,
			'version'    => $version,
			'branch'     => $branch,
			'first_seen' => ( null === $version ) ? null : self::first_seen( $type, $id, $version ),
		);
	}

	/**
	 * Whether an offered core version is a minor or a major step.
	 *
	 * A WordPress branch is MAJOR.MINOR, so 7.0.3 -> 7.0.4 is minor and
	 * 6.9.2 -> 7.0 is major. This mirrors how Core_Upgrader compares branches.
	 *
	 * @param string $offered Offered version.
	 * @return string 'minor', 'major' or 'dev'.
	 */
	public static function core_branch( string $offered ): string {
		$current = get_bloginfo( 'version' );

		if ( false !== strpos( $current, '-' ) || false !== strpos( $offered, '-' ) ) {
			return 'dev';
		}

		$current_parts = explode( '.', $current );
		$offered_parts = explode( '.', $offered );

		$current_branch = ( $current_parts[0] ?? '0' ) . '.' . ( $current_parts[1] ?? '0' );
		$offered_branch = ( $offered_parts[0] ?? '0' ) . '.' . ( $offered_parts[1] ?? '0' );

		return $current_branch === $offered_branch ? 'minor' : 'major';
	}

	/**
	 * Current time in the site's timezone.
	 *
	 * wp_timezone() and DateTimeImmutable, never date_default_timezone_set():
	 * that function rewrites the timezone for every other plugin running in the
	 * same request, which is exactly what Companion Auto Update did.
	 *
	 * @return DateTimeImmutable
	 */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * First sightings — the delay clock
	 * ---------------------------------------------------------------------
	 */

	/**
	 * State key for an item.
	 *
	 * @param string $type Item type.
	 * @param string $id   Item identifier.
	 * @return string
	 */
	private static function state_key( string $type, string $id ): string {
		return $type . ':' . $id;
	}

	/**
	 * When a given version of a given item was first offered to this site.
	 *
	 * @param string $type    Item type.
	 * @param string $id      Item identifier.
	 * @param string $version Offered version.
	 * @return int|null
	 */
	public static function first_seen( string $type, string $id, string $version ): ?int {
		if ( '' === $id || '' === $version ) {
			return null;
		}

		$state = Update_Pilot_Settings::get_state();
		$key   = self::state_key( $type, $id );

		$seen = $state['first_seen'][ $key ][ $version ] ?? null;

		return $seen ? (int) $seen : null;
	}

	/**
	 * Record that a version is being offered, if it is not already known.
	 *
	 * Only the version currently on offer is kept for each item, which bounds the
	 * size of the state option: a new version replaces the previous entry and
	 * restarts its own countdown.
	 *
	 * @param string $type    Item type.
	 * @param string $id      Item identifier.
	 * @param string $version Offered version.
	 * @return void
	 */
	public static function record_sighting( string $type, string $id, string $version ): void {
		if ( '' === $id || '' === $version ) {
			return;
		}

		$state = Update_Pilot_Settings::get_state();
		$key   = self::state_key( $type, $id );

		if ( isset( $state['first_seen'][ $key ][ $version ] ) ) {
			return;
		}

		$state['first_seen'][ $key ] = array( $version => time() );

		Update_Pilot_Settings::save_state( $state );
	}

	/**
	 * Record every plugin version currently on offer.
	 *
	 * @param mixed $value The update_plugins transient.
	 * @return void
	 */
	public static function record_plugin_sightings( $value ): void {
		self::record_from_transient( 'plugin', $value, 'plugin', 'new_version' );
	}

	/**
	 * Record every theme version currently on offer.
	 *
	 * @param mixed $value The update_themes transient.
	 * @return void
	 */
	public static function record_theme_sightings( $value ): void {
		self::record_from_transient( 'theme', $value, 'theme', 'new_version' );
	}

	/**
	 * Record the core version currently on offer.
	 *
	 * @param mixed $value The update_core transient.
	 * @return void
	 */
	public static function record_core_sightings( $value ): void {
		if ( ! is_object( $value ) || empty( $value->updates ) || ! is_array( $value->updates ) ) {
			return;
		}

		// No delay configured means no clock to keep — the same early exit the
		// plugin and theme paths take, which this one was missing, so it wrote a
		// non-autoloaded option on every core update check for nothing.
		$settings = Update_Pilot_Settings::get();

		if ( empty( $settings['delay']['enabled'] ) || ! in_array( 'core', (array) ( $settings['delay']['applies_to'] ?? array() ), true ) ) {
			return;
		}

		foreach ( $value->updates as $offer ) {
			$offer = is_array( $offer ) ? (object) $offer : $offer;

			if ( ! is_object( $offer ) || ! isset( $offer->response ) || 'upgrade' !== $offer->response ) {
				continue;
			}

			$version = isset( $offer->current ) ? (string) $offer->current : '';

			if ( '' !== $version ) {
				self::record_sighting( 'core', 'core', $version );
			}
		}
	}

	/**
	 * Walk the response list of an update transient and record each sighting.
	 *
	 * @param string $type        Item type.
	 * @param mixed  $value       Transient value.
	 * @param string $id_property Property holding the identifier.
	 * @param string $version_key Property holding the offered version.
	 * @return void
	 */
	private static function record_from_transient( string $type, $value, string $id_property, string $version_key ): void {
		if ( ! is_object( $value ) || empty( $value->response ) || ! is_array( $value->response ) ) {
			return;
		}

		$settings = Update_Pilot_Settings::get();

		// No delay configured, or not for this type, means no clock to keep.
		$target = ( 'plugin' === $type ) ? 'plugins' : 'themes';

		if ( empty( $settings['delay']['enabled'] )
			|| ! in_array( $target, (array) ( $settings['delay']['applies_to'] ?? array() ), true ) ) {
			return;
		}

		$sightings = array();

		foreach ( $value->response as $key => $offer ) {
			$offer = is_array( $offer ) ? (object) $offer : $offer;

			if ( ! is_object( $offer ) ) {
				continue;
			}

			$id      = isset( $offer->{$id_property} ) ? (string) $offer->{$id_property} : (string) $key;
			$version = isset( $offer->{$version_key} ) ? (string) $offer->{$version_key} : '';

			if ( '' !== $id && '' !== $version ) {
				$sightings[ $id ] = $version;
			}
		}

		// One write for the whole batch. Recording each offer separately rewrote
		// the state option once per plugin, which on a site with many pending
		// updates meant dozens of writes per update check.
		self::record_sightings( $type, $sightings );
	}

	/**
	 * Record a batch of sightings in a single option write.
	 *
	 * @param string                $type      Item type.
	 * @param array<string, string> $sightings Identifier => offered version.
	 * @return void
	 */
	public static function record_sightings( string $type, array $sightings ): void {
		if ( array() === $sightings ) {
			return;
		}

		$state   = Update_Pilot_Settings::get_state();
		$changed = false;

		foreach ( $sightings as $id => $version ) {
			$id      = (string) $id;
			$version = (string) $version;

			if ( '' === $id || '' === $version ) {
				continue;
			}

			$key = self::state_key( $type, $id );

			if ( isset( $state['first_seen'][ $key ][ $version ] ) ) {
				continue;
			}

			$state['first_seen'][ $key ] = array( $version => time() );
			$changed                     = true;
		}

		if ( $changed ) {
			Update_Pilot_Settings::save_state( $state );
		}
	}
}
