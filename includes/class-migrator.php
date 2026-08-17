<?php
/**
 * Import from Companion Auto Update.
 *
 * Three rules, all of them the consequence of how CAU behaves:
 *
 * - Nothing is read until the administrator asks for it, and nothing of CAU's
 *   is ever written to or deleted. Its tables stay exactly as they are, because
 *   they are the only way back if the import is not what was wanted.
 * - The old update_log table is not imported. Its dates were produced by
 *   strtotime( date( 'ydm', ... ) ), which returns false every single time, so
 *   every row carries the date of whichever day it happens to be rendered. There
 *   is nothing in there worth keeping.
 * - The README says it in plain words: import first, deactivate CAU afterwards.
 *   Deactivating it drops both of its tables.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects and imports a Companion Auto Update configuration.
 */
class Update_Pilot_Migrator {

	/**
	 * Option recording what was done, so the notice appears once.
	 */
	public const MARKER = 'update_pilot_migrated_from_cau';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_update_pilot_migrate', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_update_pilot_migrate_dismiss', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Whether a Companion Auto Update settings table is present.
	 *
	 * @return bool
	 */
	public static function source_available(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'auto_updates';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Whether the notice still has something to say.
	 *
	 * @return bool
	 */
	public static function pending(): bool {
		if ( get_option( self::MARKER ) ) {
			return false;
		}

		return self::source_available();
	}

	/**
	 * Offer the import.
	 *
	 * @return void
	 */
	public static function notice(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) || ! self::pending() ) {
			return;
		}

		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Update Pilot', 'update-pilot' ); ?></strong> —
				<?php esc_html_e( 'Companion Auto Update settings were found on this site. Would you like to import them?', 'update-pilot' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Nothing belonging to Companion Auto Update is modified or deleted. Import first, then deactivate it: deactivating that plugin destroys its tables.', 'update-pilot' ); ?>
			</p>
			<div class="upilot-notice-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="update_pilot_migrate">
					<?php wp_nonce_field( 'update_pilot_migrate' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import settings', 'update-pilot' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="update_pilot_migrate_dismiss">
					<?php wp_nonce_field( 'update_pilot_migrate_dismiss' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'No thanks', 'update-pilot' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Run the import.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'update-pilot' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'update_pilot_migrate' );

		self::import();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'update-pilot',
					'update-pilot-msg' => 'migrated',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Record that the offer was declined.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( Update_Pilot_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'update-pilot' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'update_pilot_migrate_dismiss' );

		update_option(
			self::MARKER,
			array(
				'status' => 'dismissed',
				'date'   => gmdate( 'c' ),
			),
			false
		);

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );

		exit;
	}

	/**
	 * Read the old settings and write the new ones.
	 *
	 * Idempotent: running it twice produces the same result.
	 *
	 * @return string[] Keys that were imported.
	 */
	public static function import(): array {
		$old = self::read_source();

		if ( array() === $old ) {
			return array();
		}

		$settings = Update_Pilot_Settings::get();
		$imported = array();

		// What may update.
		foreach ( array( 'plugins', 'themes' ) as $type ) {
			if ( isset( $old[ $type ] ) ) {
				$settings[ $type ]['enabled'] = self::truthy( $old[ $type ] );
				$imported[]                   = $type;
			}
		}

		if ( isset( $old['minor'] ) ) {
			$settings['core']['minor'] = self::truthy( $old['minor'] );
			$imported[]                = 'core.minor';
		}

		if ( isset( $old['major'] ) ) {
			$settings['core']['major'] = self::truthy( $old['major'] );
			$imported[]                = 'core.major';
		}

		if ( isset( $old['translations'] ) ) {
			$settings['translations'] = self::truthy( $old['translations'] );
			$imported[]               = 'translations';
		}

		/*
		 * Companion Auto Update kept plugin exclusions as slugs ("akismet"),
		 * because that is what its filter compared against. WordPress identifies
		 * a plugin by its file ("akismet/akismet.php"), which is what core uses
		 * in the auto_update_plugins option, so the list is translated here.
		 */
		if ( isset( $old['notUpdateList'] ) ) {
			$settings['plugins']['excluded'] = Update_Pilot_Settings::sanitize_plugin_list(
				self::slugs_to_plugin_files( self::split_list( $old['notUpdateList'] ) )
			);
			$imported[]                      = 'plugins.excluded';
		}

		// The theme list CAU wrote but never applied. Here it works.
		if ( isset( $old['notUpdateListTh'] ) ) {
			$settings['themes']['excluded'] = Update_Pilot_Settings::sanitize_theme_list(
				self::split_list( $old['notUpdateListTh'] )
			);
			$imported[]                     = 'themes.excluded';
		}

		// Notifications.
		if ( isset( $old['email'] ) ) {
			$settings['recipients'] = Update_Pilot_Settings::valid_recipients( $old['email'] );
			$imported[]             = 'recipients';
		}

		if ( isset( $old['sendupdate'] ) ) {
			$settings['notify']['on_success'] = self::truthy( $old['sendupdate'] );
			$imported[]                       = 'notify.on_success';
		}

		if ( isset( $old['send'] ) ) {
			$settings['notify']['on_available'] = self::truthy( $old['send'] );
			$imported[]                         = 'notify.on_available';
		}

		// CAU's "outdated software" alert. Its detector never worked, but the
		// administrator's intent to be told is worth carrying over.
		if ( isset( $old['sendoutdated'] ) ) {
			$settings['notify']['on_untested'] = self::truthy( $old['sendoutdated'] );
			$imported[]                        = 'notify.on_untested';
		}

		if ( isset( $old['html_or_text'] ) ) {
			$settings['mail_format'] = 'text' === strtolower( (string) $old['html_or_text'] ) ? 'text' : 'html';
			$imported[]              = 'mail_format';
		}

		/*
		 * CAU's "wpemails" answered "do you want WordPress's own e-mails?".
		 * Ours asks the opposite question, so the value is inverted rather than
		 * copied — getting this backwards would silently switch off every
		 * notification the site still had.
		 */
		if ( isset( $old['wpemails'] ) ) {
			$settings['suppress_native_mail'] = ! self::truthy( $old['wpemails'] );
			$imported[]                       = 'suppress_native_mail';
		}

		// Delay.
		if ( isset( $old['update_delay'] ) ) {
			$settings['delay']['enabled'] = self::truthy( $old['update_delay'] );
			$imported[]                   = 'delay.enabled';
		}

		if ( isset( $old['update_delay_days'] ) && is_numeric( $old['update_delay_days'] ) ) {
			$settings['delay']['days'] = max( 1, min( 90, (int) $old['update_delay_days'] ) );
			$imported[]                = 'delay.days';
		}

		// Roles.
		$roles = array();

		if ( isset( $old['allow_editor'] ) && self::truthy( $old['allow_editor'] ) ) {
			$roles[] = 'editor';
		}

		if ( isset( $old['allow_author'] ) && self::truthy( $old['allow_author'] ) ) {
			$roles[] = 'author';
		}

		if ( array() !== $roles ) {
			$settings['access_roles'] = $roles;
			$imported[]               = 'access_roles';
		}

		/*
		 * The recurrence Companion Auto Update had forced onto the core event is
		 * copied into our own schedule fields, but the schedule is deliberately
		 * left switched off: turning it on would change when this site installs
		 * updates, on the strength of a value read from another plugin's leftovers.
		 * The administrator is told it was imported and can enable it.
		 *
		 * CAU also kept separate cadences for plugins, themes and core; only the
		 * plugin one is read, because a single schedule cannot honour three.
		 */
		$recurrence = wp_get_schedule( 'wp_update_plugins' );

		if ( $recurrence ) {
			$settings['schedule']['interval'] = Update_Pilot_Settings::valid_interval( $recurrence );

			$next = wp_next_scheduled( 'wp_update_plugins' );

			if ( $next ) {
				$moment                         = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
				$settings['schedule']['hour']   = (int) $moment->format( 'G' );
				$settings['schedule']['minute'] = (int) $moment->format( 'i' );
			}

			$imported[] = 'schedule';
		}

		Update_Pilot_Settings::save( $settings );
		Update_Pilot_Settings::grant_capability();
		Update_Pilot_Scheduler::reschedule();

		/*
		 * The marker belongs here rather than in the request handler: it is what
		 * stops the notice from coming back, so it has to be written by whoever
		 * actually performs the import, whatever called it.
		 */
		update_option(
			self::MARKER,
			array(
				'status'   => 'imported',
				'date'     => gmdate( 'c' ),
				'imported' => $imported,
			),
			false
		);

		return $imported;
	}

	/**
	 * Read the Companion Auto Update key/value table.
	 *
	 * @return array<string, string>
	 */
	private static function read_source(): array {
		global $wpdb;

		if ( ! self::source_available() ) {
			return array();
		}

		$table = $wpdb->prefix . 'auto_updates';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT name, onoroff FROM {$table}", ARRAY_A );

		$values = array();

		foreach ( (array) $rows as $row ) {
			if ( isset( $row['name'] ) ) {
				$values[ (string) $row['name'] ] = (string) ( $row['onoroff'] ?? '' );
			}
		}

		return $values;
	}

	/**
	 * Interpret one of CAU's stored values as a boolean.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private static function truthy( $value ): bool {
		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * Split one of CAU's comma-separated lists.
	 *
	 * @param mixed $value Stored value.
	 * @return string[]
	 */
	private static function split_list( $value ): array {
		$parts = preg_split( '/[\s,]+/', (string) $value );

		return array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
	}

	/**
	 * Turn plugin slugs into plugin files.
	 *
	 * Anything that no longer matches an installed plugin is dropped rather than
	 * guessed at.
	 *
	 * @param string[] $slugs Slugs.
	 * @return string[]
	 */
	private static function slugs_to_plugin_files( array $slugs ): array {
		$installed = Update_Pilot_Settings::installed_plugins();
		$by_slug   = array();

		foreach ( $installed as $file ) {
			$directory = dirname( $file );
			$slug      = ( '.' === $directory ) ? basename( $file, '.php' ) : $directory;

			$by_slug[ $slug ] = $file;
		}

		$files = array();

		foreach ( $slugs as $slug ) {
			// Already a plugin file? Keep it.
			if ( in_array( $slug, $installed, true ) ) {
				$files[] = $slug;
				continue;
			}

			if ( isset( $by_slug[ $slug ] ) ) {
				$files[] = $by_slug[ $slug ];
			}
		}

		return array_values( array_unique( $files ) );
	}
}
