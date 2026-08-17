<?php
/**
 * Uninstall routine.
 *
 * Two different things happen here, and only one of them is optional.
 *
 * The plugin's own scheduled events and its custom capability are always
 * removed: leaving cron hooks pointing at code that no longer exists, or a
 * `manage_update_pilot` capability on the editor role after the plugin is gone,
 * is litter, not caution.
 *
 * Deleting the settings and the update history is the part that is opt-in, and
 * only happens when the administrator ticked "Delete all Update Pilot data when
 * the plugin is uninstalled". An update history is an audit trail, and losing it
 * because someone clicked Delete is not an acceptable outcome.
 *
 * @package Update_Pilot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// WordPress already gates the uninstall screen, but the capability is cheap to confirm.
if ( ! current_user_can( 'delete_plugins' ) ) {
	return;
}

/*
 * ---------------------------------------------------------------------------
 * Always: leave nothing of ours running or granting rights.
 * ---------------------------------------------------------------------------
 */

wp_clear_scheduled_hook( 'update_pilot_run' );
wp_clear_scheduled_hook( 'update_pilot_daily' );

$upilot_roles = wp_roles();

foreach ( $upilot_roles->role_objects as $upilot_role ) {
	if ( $upilot_role->has_cap( 'manage_update_pilot' ) ) {
		$upilot_role->remove_cap( 'manage_update_pilot' );
	}
}

delete_transient( 'update_pilot_github_release' );
delete_transient( 'update_pilot_compatibility' );
delete_transient( 'update_pilot_wp_branches' );

/*
 * ---------------------------------------------------------------------------
 * Only when asked: the settings, the state and the history.
 * ---------------------------------------------------------------------------
 */

$upilot_settings = get_option( 'update_pilot_settings' );

if ( ! is_array( $upilot_settings ) || empty( $upilot_settings['purge_on_uninstall'] ) ) {
	return;
}

global $wpdb;

/*
 * The table name is built from $wpdb->prefix and a hard-coded suffix, so no user
 * input reaches the query. Identifiers cannot be passed through $wpdb->prepare()
 * anyway — only values can.
 */
$upilot_table = $wpdb->prefix . 'update_pilot_log';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier built from a trusted prefix.
$wpdb->query( "DROP TABLE IF EXISTS `{$upilot_table}`" );

foreach ( array( 'update_pilot_settings', 'update_pilot_state', 'update_pilot_version', 'update_pilot_migrated_from_cau' ) as $upilot_option ) {
	delete_option( $upilot_option );
}
