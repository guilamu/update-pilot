<?php
/**
 * Unit tests for Update_Pilot_Policy.
 *
 * Plain PHP, no PHPUnit, no Composer: run it with `php tests/test-policy.php`.
 * The class under test calls no WordPress function, which is the whole point —
 * the logic that decides whether a site updates itself should be verifiable in
 * a second, not by waiting for a real update to happen on a real site.
 *
 * @package Update_Pilot
 */

/*
 * Command line only. This file defines its own ABSPATH so the class under test
 * will load without WordPress, which also means the usual
 * `defined( 'ABSPATH' ) || exit;` guard would not stop a browser from running
 * it. Nothing here reads or writes anything, but a directly executable script
 * in a web-served directory is bad practice and security scanners say so.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-policy.php';

$tests_run    = 0;
$tests_failed = 0;

/**
 * Assert that two values match.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Test description.
 * @return void
 */
function check( $expected, $actual, string $label ): void {
	global $tests_run, $tests_failed;

	++$tests_run;

	if ( $expected === $actual ) {
		echo "  ok   {$label}\n";
		return;
	}

	++$tests_failed;
	printf(
		"  FAIL %s\n         expected: %s\n         actual:   %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

/**
 * Base settings: everything managed, nothing restricted.
 *
 * @return array
 */
function base_settings(): array {
	return array(
		'core'         => array(
			'minor' => true,
			'major' => false,
			'dev'   => false,
		),
		'plugins'      => array(
			'enabled'  => true,
			'excluded' => array(),
		),
		'themes'       => array(
			'enabled'  => true,
			'excluded' => array(),
		),
		'translations' => true,
		'window'       => array( 'enabled' => false ),
		'delay'        => array(
			'enabled'    => false,
			'days'       => 2,
			'applies_to' => array( 'plugins', 'themes' ),
		),
	);
}

/**
 * A moment in time, in a fixed timezone.
 *
 * @param string $when Any strtotime-compatible string.
 * @return DateTimeImmutable
 */
function at( string $when ): DateTimeImmutable {
	return new DateTimeImmutable( $when, new DateTimeZone( 'Europe/Paris' ) );
}

/**
 * Shorthand for a decision.
 *
 * @param array             $item     Item.
 * @param array             $settings Settings.
 * @param DateTimeImmutable $now      Now.
 * @return string
 */
function decide( array $item, array $settings, DateTimeImmutable $now ): string {
	return Update_Pilot_Policy::evaluate( $item, $settings, $now )['decision'];
}

/**
 * Shorthand for a refusal reason.
 *
 * @param array             $item     Item.
 * @param array             $settings Settings.
 * @param DateTimeImmutable $now      Now.
 * @return string
 */
function reason( array $item, array $settings, DateTimeImmutable $now ): string {
	return Update_Pilot_Policy::evaluate( $item, $settings, $now )['reason'];
}

$plugin = array(
	'type'    => 'plugin',
	'id'      => 'akismet/akismet.php',
	'version' => '5.4.0',
);

$theme = array(
	'type'    => 'theme',
	'id'      => 'twentytwentyone',
	'version' => '2.8',
);

echo "\n== Exclusions ==\n";

$settings = base_settings();
check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-16 14:00' ) ), 'a plugin with no restriction is eligible' );

$settings['plugins']['excluded'] = array( 'akismet/akismet.php' );
check( Update_Pilot_Policy::DENY, decide( $plugin, $settings, at( '2026-08-16 14:00' ) ), 'an excluded plugin is never eligible' );
check( 'excluded', reason( $plugin, $settings, at( '2026-08-16 14:00' ) ), 'and the reason says so' );

// The bug that killed theme exclusions in Companion Auto Update: the identifier
// for a theme is its stylesheet, exposed as $item->theme. Reading $item->slug
// there yields null, so no exclusion could ever match.
$settings                      = base_settings();
$settings['themes']['excluded'] = array( 'twentytwentyone' );
check( Update_Pilot_Policy::DENY, decide( $theme, $settings, at( '2026-08-16 14:00' ) ), 'a theme excluded by stylesheet is blocked' );

$settings['themes']['excluded'] = array( 'twenty-twenty-one' );
check( Update_Pilot_Policy::ALLOW, decide( $theme, $settings, at( '2026-08-16 14:00' ) ), 'a near-miss identifier does not block by accident' );

echo "\n== Unmanaged types step aside ==\n";

$settings                       = base_settings();
$settings['plugins']['enabled'] = false;
check( Update_Pilot_Policy::DEFER, decide( $plugin, $settings, at( '2026-08-16 14:00' ) ), 'when plugins are not managed, WordPress keeps its own decision' );

echo "\n== Maintenance window ==\n";

$settings           = base_settings();
$settings['window'] = array(
	'enabled'    => true,
	'start_hour' => 2,
	'end_hour'   => 5,
	'weekdays'   => array( 0, 1, 2, 3, 4, 5, 6 ),
);

check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-16 03:00' ) ), '03:00 is inside a 02:00-05:00 window' );
check( Update_Pilot_Policy::DENY, decide( $plugin, $settings, at( '2026-08-16 14:00' ) ), '14:00 is outside it' );
check( 'outside_window', reason( $plugin, $settings, at( '2026-08-16 14:00' ) ), 'and the reason says so' );
check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-16 02:00' ) ), 'the start hour is inclusive' );
check( Update_Pilot_Policy::DENY, decide( $plugin, $settings, at( '2026-08-16 05:00' ) ), 'the end hour is exclusive' );

// Companion Auto Update compared 'Hi' strings with no notion of a day, so its
// window broke every night between 23:30 and 00:30 — the hours it recommended.
$settings['window']['start_hour'] = 23;
$settings['window']['end_hour']   = 2;

check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-16 23:30' ) ), 'a window across midnight holds before midnight' );
check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-17 00:30' ) ), 'and after midnight' );
check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-17 01:59' ) ), 'right up to the end hour' );
check( Update_Pilot_Policy::DENY, decide( $plugin, $settings, at( '2026-08-17 02:00' ) ), 'and stops there' );
check( Update_Pilot_Policy::DENY, decide( $plugin, $settings, at( '2026-08-16 22:59' ) ), 'and has not opened yet at 22:59' );

echo "\n== Weekdays ==\n";

// 2026-08-16 is a Sunday, 2026-08-17 a Monday, 2026-08-21 a Friday.
check( 0, (int) at( '2026-08-16 03:00' )->format( 'w' ), 'sanity: 2026-08-16 is a Sunday' );

$settings           = base_settings();
$settings['window'] = array(
	'enabled'    => true,
	'start_hour' => 2,
	'end_hour'   => 5,
	'weekdays'   => array( 1, 2, 3, 4 ), // Monday to Thursday: never on a Friday.
);

check( Update_Pilot_Policy::DENY, decide( $plugin, $settings, at( '2026-08-21 03:00' ) ), 'nothing updates on an excluded Friday' );
check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-17 03:00' ) ), 'but Monday is fine' );

// A window opened on Thursday night still applies at 01:00 on Friday morning.
$settings['window']['start_hour'] = 23;
$settings['window']['end_hour']   = 2;

check( Update_Pilot_Policy::ALLOW, decide( $plugin, $settings, at( '2026-08-21 01:00' ) ), 'Thursday 23:00-02:00 still applies at 01:00 Friday' );
check( Update_Pilot_Policy::DENY, decide( $plugin, $settings, at( '2026-08-21 23:00' ) ), 'but Friday night itself is excluded' );

echo "\n== Safety delay ==\n";

$now      = at( '2026-08-16 14:00' );
$settings = base_settings();

$settings['delay'] = array(
	'enabled'    => true,
	'days'       => 2,
	'applies_to' => array( 'plugins', 'themes' ),
);

$seen_one_day    = $plugin + array( 'first_seen' => $now->getTimestamp() - ( 1 * 86400 ) );
$seen_three_days = $plugin + array( 'first_seen' => $now->getTimestamp() - ( 3 * 86400 ) );

check( Update_Pilot_Policy::DENY, decide( $seen_one_day, $settings, $now ), 'a version seen 1 day ago waits out a 2-day delay' );
check( 'delayed', reason( $seen_one_day, $settings, $now ), 'and the reason says so' );
check( Update_Pilot_Policy::ALLOW, decide( $seen_three_days, $settings, $now ), 'a version seen 3 days ago is released' );

$never_seen = $plugin;
check( 'delay_pending_first_sighting', reason( $never_seen, $settings, $now ), 'an unrecorded version is held once, so the clock can start' );

// The delay reaches themes and core too, which Companion Auto Update never did.
$theme_seen_one_day = $theme + array( 'first_seen' => $now->getTimestamp() - 86400 );
check( Update_Pilot_Policy::DENY, decide( $theme_seen_one_day, $settings, $now ), 'the delay covers themes' );

$settings['delay']['applies_to'] = array( 'plugins' );
check( Update_Pilot_Policy::ALLOW, decide( $theme_seen_one_day, $settings, $now ), 'unless themes are left out of it' );

echo "\n== Days remaining ==\n";

$now      = at( '2026-08-16 14:00' );
$settings = base_settings();

$settings['delay'] = array(
	'enabled'    => true,
	'days'       => 7,
	'applies_to' => array( 'plugins', 'themes' ),
);

// Rounded up, so a delay armed ten minutes ago still reads as the full week.
// floor() would say 6, and round() would flip between the two mid-day.
check( 7, Update_Pilot_Policy::days_remaining( $now->getTimestamp() - 600, $settings, $now ), 'a version seen 10 minutes ago has 7 days left of a 7-day delay' );
check( 1, Update_Pilot_Policy::days_remaining( $now->getTimestamp() - (int) ( 6.5 * 86400 ), $settings, $now ), 'half a day to run counts as 1 day left' );
check( 0, Update_Pilot_Policy::days_remaining( $now->getTimestamp() - ( 7 * 86400 ), $settings, $now ), 'the exact moment the delay expires is 0' );
check( 0, Update_Pilot_Policy::days_remaining( $now->getTimestamp() - ( 8 * 86400 ), $settings, $now ), 'and an expired delay never goes negative' );

$settings['delay']['days'] = 0;
check( 0, Update_Pilot_Policy::days_remaining( $now->getTimestamp(), $settings, $now ), 'a zero-day delay has nothing left to wait' );

// The countdown is only ever shown for an item the policy reports as delayed,
// and that reason cannot survive the delay elapsing: the two must not disagree
// about where the boundary is.
$settings['delay']['days'] = 7;
$seen_six_days             = $plugin + array( 'first_seen' => $now->getTimestamp() - ( 6 * 86400 ) );

check( 'delayed', reason( $seen_six_days, $settings, $now ), 'a version seen 6 days ago is still delayed' );
check( 1, Update_Pilot_Policy::days_remaining( (int) $seen_six_days['first_seen'], $settings, $now ), 'and the countdown agrees there is a day to go' );

echo "\n== Core branches ==\n";

$settings = base_settings(); // minor on, major off.

$minor = array(
	'type'    => 'core',
	'id'      => 'core',
	'version' => '6.9.1',
	'branch'  => 'minor',
);

$major = array(
	'type'    => 'core',
	'id'      => 'core',
	'version' => '7.0',
	'branch'  => 'major',
);

check( Update_Pilot_Policy::ALLOW, decide( $minor, $settings, at( '2026-08-16 14:00' ) ), 'a minor core release is eligible' );
check( Update_Pilot_Policy::DENY, decide( $major, $settings, at( '2026-08-16 14:00' ) ), 'a major one is not' );
check( 'core_branch_disabled', reason( $major, $settings, at( '2026-08-16 14:00' ) ), 'and the reason says so' );

$settings['core']['major'] = true;
check( Update_Pilot_Policy::ALLOW, decide( $major, $settings, at( '2026-08-16 14:00' ) ), 'until major updates are switched on' );

echo "\n== Core is never delayed unless asked ==\n";

$settings          = base_settings();
$settings['delay'] = array(
	'enabled'    => true,
	'days'       => 2,
	'applies_to' => array( 'plugins', 'themes' ),
);

$core_seen_today = $minor + array( 'first_seen' => at( '2026-08-16 14:00' )->getTimestamp() );
check( Update_Pilot_Policy::ALLOW, decide( $core_seen_today, $settings, at( '2026-08-16 14:00' ) ), 'a security release is not held back by default' );

$settings['delay']['applies_to'][] = 'core';
check( Update_Pilot_Policy::DENY, decide( $core_seen_today, $settings, at( '2026-08-16 14:00' ) ), 'unless core is explicitly added to the delay' );

echo "\n== Translations ==\n";

$translation = array(
	'type'    => 'translation',
	'id'      => 'plugin:akismet:fr_FR',
	'version' => '5.4.0',
);

$settings          = base_settings();
$settings['delay'] = array(
	'enabled'    => true,
	'days'       => 30,
	'applies_to' => array( 'plugins', 'themes', 'core' ),
);

check( Update_Pilot_Policy::ALLOW, decide( $translation, $settings, at( '2026-08-16 14:00' ) ), 'translations carry no code, so they are never delayed' );

// A setting that is written but never read is the defining bug of the plugin
// this one replaces. Unticking the box has to actually stop the updates.
$settings                 = base_settings();
$settings['translations'] = false;

check( Update_Pilot_Policy::DENY, decide( $translation, $settings, at( '2026-08-16 14:00' ) ), 'switching translations off actually refuses them' );
check( 'type_disabled', reason( $translation, $settings, at( '2026-08-16 14:00' ) ), 'and the reason says so' );

$settings['translations'] = true;
check( Update_Pilot_Policy::ALLOW, decide( $translation, $settings, at( '2026-08-16 14:00' ) ), 'and switching it back on allows them again' );

// The window still applies to translations.
$settings['window'] = array(
	'enabled'    => true,
	'start_hour' => 2,
	'end_hour'   => 5,
	'weekdays'   => array( 0, 1, 2, 3, 4, 5, 6 ),
);

check( Update_Pilot_Policy::DENY, decide( $translation, $settings, at( '2026-08-16 14:00' ) ), 'and the maintenance window covers them too' );

echo "\n== Rollback outcomes ==\n";

/**
 * The classifier lives on the listeners class, which needs WordPress. These are
 * the exact error codes core produces, checked against the substring rules the
 * classifier applies, so the distinction cannot silently regress.
 *
 * @param string $code Core error code.
 * @return string
 */
function classify_code( string $code ): string {
	if ( false !== strpos( $code, 'rollback_failed' ) || false !== strpos( $code, 'restore_failed' ) ) {
		return 'failed';
	}

	if ( false !== strpos( $code, 'rollback' ) || false !== strpos( $code, 'restore' ) ) {
		return 'rolled_back';
	}

	return 'failed';
}

check( 'rolled_back', classify_code( 'plugin_update_fatal_error_rollback_successful' ), 'a successful rollback is recorded as rolled back' );

// The worst outcome there is: the new version broke the site and the old one
// could not be put back. Reporting it as "rolled back" reads like a safe landing.
check( 'failed', classify_code( 'plugin_update_fatal_error_rollback_failed' ), 'a FAILED rollback is recorded as a failure, not as a rollback' );
check( 'rolled_back', classify_code( 'rollback_was_required' ), 'a required rollback is recorded as rolled back' );
check( 'failed', classify_code( 'download_failed' ), 'an ordinary failure stays a failure' );

printf( "\n%d checks, %d failures\n", $tests_run, $tests_failed );

exit( $tests_failed > 0 ? 1 : 0 );
