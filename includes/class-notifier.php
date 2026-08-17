<?php
/**
 * Notifications.
 *
 * One send path, one recipient list, one place where the content type is set
 * and unset. Companion Auto Update had six copies of the same nested loop over
 * a malformed array, a function declared inside another function, and a default
 * configuration in which no mail was ever sent at all — silently, because the
 * failure was a PHP warning rather than an error.
 *
 * The mail that matters most here is the one WordPress does not really cover:
 * an update that failed.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Composes and sends the plugin's e-mails.
 */
class Update_Pilot_Notifier {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		$settings = Update_Pilot_Settings::get();

		/*
		 * Only silence WordPress's own mails when the administrator has asked
		 * for it. Off by default: our messages are an addition, not a
		 * replacement, and if this plugin ever stops working the site still gets
		 * the native notifications.
		 */
		if ( ! empty( $settings['suppress_native_mail'] ) ) {
			add_filter( 'auto_plugin_update_send_email', '__return_false' );
			add_filter( 'auto_theme_update_send_email', '__return_false' );
			add_filter( 'auto_core_update_send_email', '__return_false' );
			add_filter( 'automatic_updates_send_debug_email', '__return_false' );
		}

		add_action( Update_Pilot_Scheduler::DAILY_EVENT, array( __CLASS__, 'maybe_report_available' ) );

		// Priority 10 runs after Compatibility::refresh(), which sits at 5.
		add_action( Update_Pilot_Scheduler::DAILY_EVENT, array( __CLASS__, 'maybe_report_untested' ) );
	}

	/**
	 * Mention plugins whose author has stopped declaring compatibility.
	 *
	 * Sent only when the set of flagged plugins changes. A message that arrives
	 * every single day about the same three plugins is a message nobody reads,
	 * and Companion Auto Update sent one on every cron run.
	 *
	 * @return void
	 */
	public static function maybe_report_untested(): void {
		$settings = Update_Pilot_Settings::get();

		if ( empty( $settings['notify']['on_untested'] ) ) {
			return;
		}

		$report = Update_Pilot_Compatibility::report();

		if ( array() === $report ) {
			return;
		}

		$flagged = array_filter(
			$report,
			static fn( $row ) => in_array(
				$row['status'] ?? '',
				array( Update_Pilot_Compatibility::BEHIND, Update_Pilot_Compatibility::UNDECLARED ),
				true
			)
		);

		$state       = Update_Pilot_Settings::get_state();
		$fingerprint = Update_Pilot_Compatibility::fingerprint( $report );

		if ( ( $state['untested_fingerprint'] ?? '' ) === $fingerprint ) {
			return;
		}

		$state['untested_fingerprint'] = $fingerprint;
		Update_Pilot_Settings::save_state( $state );

		if ( array() === $flagged ) {
			return;
		}

		$events = array();

		foreach ( $flagged as $row ) {
			$events[] = array(
				'type'         => 'plugin',
				'item'         => (string) $row['item'],
				'name'         => (string) $row['name'],
				'from_version' => null,
				'to_version'   => null,
				'status'       => 'untested',
				'message'      => Update_Pilot_Compatibility::describe( $row ),
			);
		}

		self::send(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Plugins that may no longer be maintained', 'update-pilot' ),
				self::site_name()
			),
			self::html_body( self::untested_intro(), array( array( 'title' => __( 'Worth a look', 'update-pilot' ), 'events' => $events ) ) ),
			self::text_body( self::untested_intro(), array( array( 'title' => __( 'Worth a look', 'update-pilot' ), 'events' => $events ) ) )
		);
	}

	/**
	 * The opening sentence of the compatibility e-mail.
	 *
	 * @return string
	 */
	private static function untested_intro(): string {
		return __( 'The authors of these plugins have not declared compatibility with a recent WordPress release. That does not mean they are broken — it means nobody may be looking after them, which matters the day a vulnerability is found.', 'update-pilot' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * After a run
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Report the outcome of an automatic update run.
	 *
	 * Successes and failures travel in a single message rather than one mail per
	 * item, so a run that touches twelve plugins produces one e-mail.
	 *
	 * @param array $events Log events produced by the run.
	 * @return void
	 */
	public static function report_run( array $events ): void {
		if ( array() === $events ) {
			return;
		}

		$settings = Update_Pilot_Settings::get();

		$succeeded = array_values(
			array_filter( $events, static fn( $event ) => 'success' === ( $event['status'] ?? '' ) )
		);

		$failed = array_values(
			array_filter( $events, static fn( $event ) => 'success' !== ( $event['status'] ?? '' ) )
		);

		$report_success = ! empty( $settings['notify']['on_success'] ) && array() !== $succeeded;
		$report_failure = ! empty( $settings['notify']['on_failure'] ) && array() !== $failed;

		if ( ! $report_success && ! $report_failure ) {
			return;
		}

		$subject = $report_failure
			? sprintf(
				/* translators: %s: site name. */
				__( '[%s] An update failed', 'update-pilot' ),
				self::site_name()
			)
			: sprintf(
				/* translators: %s: site name. */
				__( '[%s] Updates installed', 'update-pilot' ),
				self::site_name()
			);

		$sections = array();

		if ( $report_failure ) {
			$sections[] = array(
				'title'  => __( 'Failed', 'update-pilot' ),
				'events' => $failed,
			);
		}

		if ( $report_success ) {
			$sections[] = array(
				'title'  => __( 'Installed', 'update-pilot' ),
				'events' => $succeeded,
			);
		}

		$intro = $report_failure
			? __( 'One or more automatic updates did not complete on your site. The versions below were left as they were.', 'update-pilot' )
			: __( 'The following updates were installed automatically on your site.', 'update-pilot' );

		self::send(
			$subject,
			self::html_body( $intro, $sections ),
			self::text_body( $intro, $sections )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Updates that are available but not installed
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Once a day, mention updates that are sitting there uninstalled.
	 *
	 * @return void
	 */
	public static function maybe_report_available(): void {
		$settings = Update_Pilot_Settings::get();

		if ( empty( $settings['notify']['on_available'] ) ) {
			return;
		}

		$pending = self::pending_updates();

		if ( array() === $pending ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: number of available updates. */
			_n( '[%1$s] %2$d update is available', '[%1$s] %2$d updates are available', count( $pending ), 'update-pilot' ),
			self::site_name(),
			count( $pending )
		);

		$sections = array(
			array(
				'title'  => __( 'Available', 'update-pilot' ),
				'events' => $pending,
			),
		);

		$intro = __( 'These updates are available on your site and have not been installed.', 'update-pilot' );

		self::send(
			$subject,
			self::html_body( $intro, $sections ),
			self::text_body( $intro, $sections )
		);
	}

	/**
	 * Everything WordPress currently has on offer.
	 *
	 * Read from the update transients, which core keeps fresh. No HTTP request
	 * of our own: Companion Auto Update made one plugins_api() call per
	 * installed plugin, in series, from a cron job, with no cache.
	 *
	 * @return array Log-shaped event rows.
	 */
	public static function pending_updates(): array {
		$pending = array();

		$plugins = get_site_transient( 'update_plugins' );

		if ( is_object( $plugins ) && ! empty( $plugins->response ) && is_array( $plugins->response ) ) {
			foreach ( $plugins->response as $file => $offer ) {
				$offer = is_array( $offer ) ? (object) $offer : $offer;

				$pending[] = array(
					'type'         => 'plugin',
					'item'         => (string) $file,
					'name'         => self::plugin_name( (string) $file ),
					'from_version' => Update_Pilot_Listeners::installed_version( 'plugin', (string) $file ),
					'to_version'   => isset( $offer->new_version ) ? (string) $offer->new_version : null,
					'status'       => 'available',
				);
			}
		}

		$themes = get_site_transient( 'update_themes' );

		if ( is_object( $themes ) && ! empty( $themes->response ) && is_array( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $offer ) {
				$offer = is_array( $offer ) ? (object) $offer : $offer;
				$theme = wp_get_theme( (string) $stylesheet );

				$pending[] = array(
					'type'         => 'theme',
					'item'         => (string) $stylesheet,
					'name'         => $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $stylesheet,
					'from_version' => Update_Pilot_Listeners::installed_version( 'theme', (string) $stylesheet ),
					'to_version'   => isset( $offer->new_version ) ? (string) $offer->new_version : null,
					'status'       => 'available',
				);
			}
		}

		$core = get_site_transient( 'update_core' );

		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $offer ) {
				$offer = is_array( $offer ) ? (object) $offer : $offer;

				if ( is_object( $offer ) && 'upgrade' === ( $offer->response ?? '' ) ) {
					$pending[] = array(
						'type'         => 'core',
						'item'         => 'core',
						'name'         => 'WordPress',
						'from_version' => (string) get_bloginfo( 'version' ),
						'to_version'   => isset( $offer->current ) ? (string) $offer->current : null,
						'status'       => 'available',
					);
					break;
				}
			}
		}

		return $pending;
	}

	/**
	 * A plugin's display name.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	private static function plugin_name( string $file ): string {
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

	/*
	 * ---------------------------------------------------------------------
	 * Sending
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Send a test message to the configured recipients.
	 *
	 * Worth having before anything depends on it: a failure alert that never
	 * arrives is indistinguishable from no failure at all, and that was exactly
	 * the state Companion Auto Update left sites in.
	 *
	 * @return bool
	 */
	public static function send_test(): bool {
		$settings = Update_Pilot_Settings::get();

		$sections = array(
			array(
				'title'  => __( 'Example', 'update-pilot' ),
				'events' => array(
					array(
						'type'         => 'plugin',
						'item'         => 'example/example.php',
						'name'         => __( 'An example plugin', 'update-pilot' ),
						'from_version' => '1.0.0',
						'to_version'   => '1.1.0',
						'status'       => 'success',
					),
				),
			),
		);

		$intro = sprintf(
			/* translators: %s: 'HTML' or 'Plain text'. */
			__( 'This is a test message from Update Pilot. If you are reading it, this site can deliver the notifications you have switched on. Format in use: %s.', 'update-pilot' ),
			'html' === $settings['mail_format'] ? __( 'HTML', 'update-pilot' ) : __( 'Plain text', 'update-pilot' )
		);

		return self::send(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Update Pilot test message', 'update-pilot' ),
				self::site_name()
			),
			self::html_body( $intro, $sections ),
			self::text_body( $intro, $sections )
		);
	}

	/**
	 * The people to write to.
	 *
	 * An empty list falls back to the site administrator. This is the code path
	 * Companion Auto Update got wrong: its fallback pushed a string into an
	 * array that six callers then iterated as if it were nested, so wp_mail()
	 * was never reached on any site that had not opened the settings screen once.
	 *
	 * @return string[]
	 */
	public static function recipients(): array {
		$settings = Update_Pilot_Settings::get();

		$list = array_filter( (array) $settings['recipients'], 'is_string' );

		if ( array() === $list ) {
			$list = array( (string) get_option( 'admin_email' ) );
		}

		/**
		 * Filter the list of addresses Update Pilot writes to.
		 *
		 * @param string[] $list Recipient addresses.
		 */
		$list = (array) apply_filters( 'update_pilot_recipients', $list );

		return array_values( array_filter( $list, 'is_email' ) );
	}

	/**
	 * Send one message to every recipient.
	 *
	 * @param string $subject Subject line.
	 * @param string $html    HTML body.
	 * @param string $text    Plain text body.
	 * @return bool
	 */
	private static function send( string $subject, string $html, string $text ): bool {
		$recipients = self::recipients();

		if ( array() === $recipients ) {
			return false;
		}

		$settings = Update_Pilot_Settings::get();
		$as_html  = 'html' === $settings['mail_format'];

		if ( $as_html ) {
			add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		}

		/*
		 * One message per recipient. Passing the whole list to wp_mail() puts
		 * every address in the To: header, so each person sees who else is on
		 * the site's notification list — usually other clients or colleagues.
		 */
		$sent = false;

		foreach ( $recipients as $recipient ) {
			$sent = wp_mail( $recipient, $subject, $as_html ? $html : $text ) || $sent;
		}

		if ( $as_html ) {
			// Removed immediately, so no other plugin inherits our content type.
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		}

		return (bool) $sent;
	}

	/**
	 * Content type for HTML mail.
	 *
	 * A real method on the class. Companion Auto Update declared its equivalent
	 * inside another function, which would fatal with "Cannot redeclare" the
	 * second time that function ran in one request.
	 *
	 * @return string
	 */
	public static function html_content_type(): string {
		return 'text/html';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Composition
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The site name, for subject lines.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		$name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		return '' === $name ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : $name;
	}

	/**
	 * One line describing an event, in plain text.
	 *
	 * @param array $event Event row.
	 * @return string
	 */
	private static function describe( array $event ): string {
		$name = (string) ( $event['name'] ?? $event['item'] ?? '' );
		$from = $event['from_version'] ?? null;
		$to   = $event['to_version'] ?? null;

		if ( $from && $to ) {
			$versions = sprintf( '%s → %s', $from, $to );
		} elseif ( $to ) {
			$versions = (string) $to;
		} elseif ( $from ) {
			$versions = sprintf(
				/* translators: %s: version number. */
				__( 'still on %s', 'update-pilot' ),
				$from
			);
		} else {
			$versions = '';
		}

		$label = Update_Pilot_Log_Repository::type_label( (string) ( $event['type'] ?? '' ) );

		$line = sprintf( '%s: %s', $label, $name );

		if ( '' !== $versions ) {
			$line .= ' (' . $versions . ')';
		}

		if ( ! empty( $event['message'] ) ) {
			$line .= ' — ' . $event['message'];
		}

		return $line;
	}

	/**
	 * Plain text body.
	 *
	 * @param string $intro    Opening sentence.
	 * @param array  $sections Sections, each with a title and events.
	 * @return string
	 */
	private static function text_body( string $intro, array $sections ): string {
		$lines = array( $intro, '' );

		foreach ( $sections as $section ) {
			$lines[] = strtoupper( (string) $section['title'] );

			foreach ( $section['events'] as $event ) {
				$lines[] = '  - ' . self::describe( $event );
			}

			$lines[] = '';
		}

		$lines[] = sprintf(
			/* translators: %s: site URL. */
			__( 'Site: %s', 'update-pilot' ),
			home_url()
		);

		$lines[] = sprintf(
			/* translators: %s: URL of the update log. */
			__( 'Full log: %s', 'update-pilot' ),
			admin_url( 'admin.php?page=update-pilot-log' )
		);

		$lines[] = '';
		$lines[] = __( 'Sent by Update Pilot.', 'update-pilot' );

		return implode( "\n", $lines );
	}

	/**
	 * HTML body.
	 *
	 * Everything that came from a plugin or theme header is escaped here: those
	 * strings are written by third parties and are not to be trusted.
	 *
	 * @param string $intro    Opening sentence.
	 * @param array  $sections Sections, each with a title and events.
	 * @return string
	 */
	private static function html_body( string $intro, array $sections ): string {
		$html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;font-size:14px;line-height:1.6;color:#1d2327;">';

		$html .= '<p>' . esc_html( $intro ) . '</p>';

		foreach ( $sections as $section ) {
			$html .= '<h2 style="font-size:15px;margin:24px 0 8px;">' . esc_html( (string) $section['title'] ) . '</h2>';
			$html .= '<ul style="margin:0;padding-left:18px;">';

			foreach ( $section['events'] as $event ) {
				$html .= '<li style="margin-bottom:4px;">' . esc_html( self::describe( $event ) ) . '</li>';
			}

			$html .= '</ul>';
		}

		$html .= '<hr style="border:none;border-top:1px solid #dcdcde;margin:24px 0;">';

		$html .= '<p style="font-size:13px;color:#646970;">';
		$html .= sprintf(
			/* translators: %s: site link. */
			esc_html__( 'Site: %s', 'update-pilot' ),
			'<a href="' . esc_url( home_url() ) . '">' . esc_html( home_url() ) . '</a>'
		);
		$html .= '<br>';
		$html .= sprintf(
			/* translators: %s: link to the update log. */
			esc_html__( 'Full log: %s', 'update-pilot' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=update-pilot-log' ) ) . '">' . esc_html__( 'Update Pilot log', 'update-pilot' ) . '</a>'
		);
		$html .= '</p>';

		$html .= '<p style="font-size:12px;color:#8c8f94;">' . esc_html__( 'Sent by Update Pilot.', 'update-pilot' ) . '</p>';
		$html .= '</div>';

		return $html;
	}
}
