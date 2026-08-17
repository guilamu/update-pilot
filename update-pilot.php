<?php
/**
 * Plugin Name:       Update Pilot
 * Plugin URI:        https://github.com/guilamu/update-pilot
 * Description:       Take command of WordPress auto-updates: choose what updates, schedule when, delay risky releases, and get a truthful log of everything that happened.
 * Version:           1.0.0
 * Author:            Guilamu
 * Author URI:        https://github.com/guilamu
 * Text Domain:       update-pilot
 * Domain Path:       /languages
 * Update URI:        https://github.com/guilamu/update-pilot/
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 *
 * Update Pilot never installs an update itself. WordPress core does that, and it
 * does it well. This plugin decides what is eligible, when it may run, how long a
 * release must wait before it is trusted, records what actually happened, and
 * reports it. Every design choice follows from that separation.
 *
 * @package Update_Pilot
 */

defined( 'ABSPATH' ) || exit;

define( 'UPILOT_VERSION', '1.0.0' );
define( 'UPILOT_FILE', __FILE__ );
define( 'UPILOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'UPILOT_URL', plugin_dir_url( __FILE__ ) );
define( 'UPILOT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check the runtime requirements.
 *
 * The plugin header already declares them, and modern WordPress refuses to
 * activate or update a plugin whose requirements are not met. This guard exists
 * for the site that was copied over FTP, where no header check ever ran.
 *
 * @return bool
 */
function upilot_requirements_met(): bool {
	return version_compare( PHP_VERSION, '8.0', '>=' )
		&& version_compare( get_bloginfo( 'version' ), '6.5', '>=' );
}

/**
 * Class files, loaded in dependency order.
 *
 * No Composer, no autoloader, no namespaces: this matches the conventions of the
 * other plugins in this repository, and the release workflow excludes composer.*
 * from the shipped zip anyway.
 *
 * @return void
 */
function upilot_load_classes(): void {
	$files = array(
		'includes/class-settings.php',
		'includes/class-policy.php',
		'includes/class-scheduler.php',
		'includes/class-logger.php',
		'includes/class-log-repository.php',
		'includes/class-listeners.php',
		'includes/class-notifier.php',
		'includes/class-compatibility.php',
		'includes/class-diagnostics.php',
		'includes/class-migrator.php',
		'includes/class-admin.php',
		'includes/class-github-updater.php',
	);

	foreach ( $files as $file ) {
		require_once UPILOT_PATH . $file;
	}
}

if ( upilot_requirements_met() ) {
	upilot_load_classes();
	add_action( 'plugins_loaded', 'upilot_bootstrap' );
	add_action( 'init', 'upilot_load_textdomain' );
} else {
	add_action( 'admin_notices', 'upilot_requirements_notice' );
}

/**
 * Register every hook the plugin uses.
 *
 * @return void
 */
function upilot_bootstrap(): void {
	upilot_maybe_upgrade();

	Update_Pilot_Settings::init();
	Update_Pilot_Policy_Filters::init();
	Update_Pilot_Scheduler::init();
	Update_Pilot_Logger::init();
	Update_Pilot_Listeners::init();
	Update_Pilot_Notifier::init();
	Update_Pilot_Compatibility::init();
	Update_Pilot_Diagnostics::init();
	Update_Pilot_Migrator::init();

	if ( is_admin() ) {
		Update_Pilot_Admin::init();
	}
}

/**
 * Load the French (and any other bundled) translation.
 *
 * The plugin is not hosted on wordpress.org, so translate.wordpress.org never
 * ships anything for it: the .mo files travel in the plugin itself.
 *
 * @return void
 */
function upilot_load_textdomain(): void {
	load_plugin_textdomain( 'update-pilot', false, dirname( UPILOT_BASENAME ) . '/languages' );
}

/**
 * Tell the administrator why the plugin is dormant.
 *
 * @return void
 */
function upilot_requirements_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required WordPress version, 2: required PHP version, 3: current WordPress version, 4: current PHP version. */
				__( 'Update Pilot requires WordPress %1$s and PHP %2$s or higher. This site runs WordPress %3$s on PHP %4$s, so the plugin is not doing anything.', 'update-pilot' ),
				'6.5',
				'8.0',
				get_bloginfo( 'version' ),
				PHP_VERSION
			)
		)
	);
}

/*
 * -------------------------------------------------------------------------
 * Repository integrations
 * -------------------------------------------------------------------------
 */

/**
 * Register with Guilamu Bug Reporter, when it is installed.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
			Guilamu_Bug_Reporter::register(
				array(
					'slug'        => 'update-pilot',
					'name'        => 'Update Pilot',
					'version'     => UPILOT_VERSION,
					'github_repo' => 'guilamu/update-pilot',
				)
			);
		}
	},
	20
);

add_filter( 'plugin_row_meta', 'upilot_plugin_row_meta', 10, 2 );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'upilot_plugin_action_links' );

/**
 * Add the "View details" and "Report a Bug" links to the plugins list.
 *
 * @param string[] $links Existing links.
 * @param string   $file  Plugin file being rendered.
 * @return string[]
 */
function upilot_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( __FILE__ ) !== $file ) {
		return $links;
	}

	// The thickbox modal WordPress.org-hosted plugins get, fed by our README.md.
	$links[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url(
			self_admin_url(
				'plugin-install.php?tab=plugin-information&plugin=update-pilot'
				. '&TB_iframe=true&width=772&height=926'
			)
		),
		esc_attr__( 'More information about Update Pilot', 'update-pilot' ),
		esc_attr__( 'Update Pilot', 'update-pilot' ),
		esc_html__( 'View details', 'update-pilot' )
	);

	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		$links[] = sprintf(
			'<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="update-pilot" data-plugin-name="%s">%s</a>',
			esc_attr__( 'Update Pilot', 'update-pilot' ),
			esc_html__( '🐛 Report a Bug', 'update-pilot' )
		);
	} else {
		$links[] = '<a href="https://github.com/guilamu/guilamu-bug-reporter/releases" target="_blank">'
			. esc_html__( '🐛 Report a Bug (install Bug Reporter)', 'update-pilot' )
			. '</a>';
	}

	return $links;
}

/**
 * Add a Settings link next to Activate / Deactivate.
 *
 * @param string[] $links Existing links.
 * @return string[]
 */
function upilot_plugin_action_links( $links ) {
	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=update-pilot' ) ),
			esc_html__( 'Settings', 'update-pilot' )
		)
	);

	return $links;
}

/*
 * -------------------------------------------------------------------------
 * Activation, deactivation, install
 * -------------------------------------------------------------------------
 */

register_activation_hook( __FILE__, 'upilot_activate' );
register_deactivation_hook( __FILE__, 'upilot_deactivate' );

/**
 * Activation.
 *
 * @return void
 */
function upilot_activate(): void {
	if ( ! upilot_requirements_met() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html(
				sprintf(
					/* translators: 1: required WordPress version, 2: required PHP version. */
					__( 'Update Pilot requires WordPress %1$s and PHP %2$s or higher.', 'update-pilot' ),
					'6.5',
					'8.0'
				)
			),
			'',
			array( 'back_link' => true )
		);
	}

	upilot_install();
}

/**
 * Deactivation.
 *
 * Deliberately minimal. Companion Auto Update dropped both of its tables here,
 * which meant that disabling the plugin for ten minutes to diagnose a conflict
 * destroyed every setting and the whole update history. Settings, state and log
 * survive deactivation; only our own scheduled event is removed, and the core
 * cron events are left exactly as we found them because we never took them over.
 *
 * @return void
 */
function upilot_deactivate(): void {
	/*
	 * The classes are only loaded when the requirements are met. If PHP or
	 * WordPress was downgraded below the minimum after activation, calling into
	 * them here would fatal and leave the plugin impossible to switch off, so
	 * the hooks are cleared directly in that case.
	 */
	if ( class_exists( 'Update_Pilot_Scheduler' ) ) {
		Update_Pilot_Scheduler::unschedule_all();

		return;
	}

	wp_clear_scheduled_hook( 'update_pilot_run' );
	wp_clear_scheduled_hook( 'update_pilot_daily' );
}

/**
 * Create or update everything the plugin needs to run.
 *
 * Idempotent: safe to call on activation, after an update, or from the schema
 * guard below.
 *
 * @return void
 */
function upilot_install(): void {
	Update_Pilot_Settings::install();
	Update_Pilot_Logger::install_table();
	Update_Pilot_Settings::grant_capability();
	Update_Pilot_Scheduler::reschedule();

	update_option( 'update_pilot_version', UPILOT_VERSION, false );
}

/**
 * Run the install routine when the stored schema is behind the code.
 *
 * The schema number lives in `update_pilot_settings['schema']` and is read and
 * written through the same API on purpose. Companion Auto Update read its schema
 * version with get_site_option() and wrote it with add_option(), so on multisite
 * the two never agreed and dbDelta() ran after every single plugin update on the
 * whole network.
 *
 * @return void
 */
function upilot_maybe_upgrade(): void {
	$settings = get_option( 'update_pilot_settings' );

	$stored_schema = is_array( $settings ) && isset( $settings['schema'] ) ? (int) $settings['schema'] : 0;

	if ( Update_Pilot_Settings::SCHEMA === $stored_schema ) {
		return;
	}

	upilot_install();
}
