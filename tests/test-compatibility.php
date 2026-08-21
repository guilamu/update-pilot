<?php
/**
 * Unit tests for Update_Pilot_Compatibility::classify().
 *
 * Run with `php tests/test-compatibility.php`.
 *
 * The point of these is the arithmetic Companion Auto Update got wrong: branch
 * distance is a position in a list, not a subtraction. 6.8, 6.9 and 7.0 are
 * three consecutive releases, and no amount of casting will tell you that.
 *
 * @package Update_Pilot
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'HOUR_IN_SECONDS', 3600 );

require_once __DIR__ . '/../includes/class-compatibility.php';

$tests_run    = 0;
$tests_failed = 0;

/**
 * Assert equality.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    Description.
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

// The real sequence, as returned by the WordPress stable-check endpoint.
$branches = array( '6.0', '6.1', '6.2', '6.3', '6.4', '6.5', '6.6', '6.7', '6.8', '6.9', '7.0' );
$current  = '7.0';
$default  = Update_Pilot_Compatibility::DEFAULT_THRESHOLD; // 3

echo "\n== Branch extraction ==\n";

check( '7.0', Update_Pilot_Compatibility::branch( '7.0.4' ), '7.0.4 belongs to branch 7.0' );
check( '6.9', Update_Pilot_Compatibility::branch( '6.9' ), 'a two-part version is its own branch' );
check( '6.9', Update_Pilot_Compatibility::branch( ' 6.9.7 ' ), 'surrounding whitespace is ignored' );

echo "\n== Counting releases, the way CAU could not ==\n";

// The case that made CAU noisy: one release behind is not abandonment.
$one_behind = Update_Pilot_Compatibility::classify( '6.9', $current, $branches, $default );

check( Update_Pilot_Compatibility::CURRENT, $one_behind['status'], 'tested up to 6.9 on a 7.0 site is not flagged' );
check( 1, $one_behind['behind'], 'but it is counted as one release behind' );

// The case that made CAU silent: 6.4 on a 6.9 site gave (int) 6 - (int) 6 = 0.
$five_behind = Update_Pilot_Compatibility::classify( '6.4', '6.9', $branches, $default );

check( Update_Pilot_Compatibility::BEHIND, $five_behind['status'], 'tested up to 6.4 on a 6.9 site is flagged' );
check( 5, $five_behind['behind'], 'and correctly counted as five releases behind' );

// Across the major boundary, which no subtraction can handle.
$across = Update_Pilot_Compatibility::classify( '6.8', $current, $branches, $default );

check( 2, $across['behind'], '6.8 is two releases behind 7.0, not "0.8"' );
check( Update_Pilot_Compatibility::CURRENT, $across['status'], 'and two is below the threshold' );

$far = Update_Pilot_Compatibility::classify( '6.2', $current, $branches, $default );

check( 8, $far['behind'], '6.2 is eight releases behind 7.0' );
check( Update_Pilot_Compatibility::BEHIND, $far['status'], 'which is well past the threshold' );

echo "\n== Threshold boundary ==\n";

$exactly = Update_Pilot_Compatibility::classify( '6.7', $current, $branches, 3 );
check( 3, $exactly['behind'], '6.7 is three releases behind' );
check( Update_Pilot_Compatibility::BEHIND, $exactly['status'], 'and three meets the threshold of three' );

$just_under = Update_Pilot_Compatibility::classify( '6.8', $current, $branches, 3 );
check( Update_Pilot_Compatibility::CURRENT, $just_under['status'], 'two does not' );

$strict = Update_Pilot_Compatibility::classify( '6.9', $current, $branches, 1 );
check( Update_Pilot_Compatibility::BEHIND, $strict['status'], 'a threshold of one flags a single release' );

echo "\n== Current and ahead ==\n";

$same = Update_Pilot_Compatibility::classify( '7.0', $current, $branches, $default );
check( Update_Pilot_Compatibility::CURRENT, $same['status'], 'the current branch is current' );
check( 0, $same['behind'], 'with nothing behind it' );

$ahead = Update_Pilot_Compatibility::classify( '7.1', $current, $branches, $default );
check( Update_Pilot_Compatibility::CURRENT, $ahead['status'], 'an author who declares the unreleased next branch is current' );

echo "\n== Nothing declared ==\n";

foreach ( array( null, '', '   ' ) as $empty ) {
	$undeclared = Update_Pilot_Compatibility::classify( $empty, $current, $branches, $default );

	check( Update_Pilot_Compatibility::UNDECLARED, $undeclared['status'], 'an absent declaration is reported as absent, not as outdated' );
	check( null, $undeclared['tested'], 'and carries no version' );
}

echo "\n== When the release history is unavailable ==\n";

$no_list = Update_Pilot_Compatibility::classify( '6.2', $current, array(), $default );

check( Update_Pilot_Compatibility::UNKNOWN, $no_list['status'], 'without the branch list nothing is claimed' );
check( '6.2', $no_list['tested'], 'but the declared version is still reported' );
check( null, $no_list['behind'], 'and no distance is invented' );

echo "\n== Summary and sorting ==\n";

$report = array(
	'a/a.php' => array( 'item' => 'a/a.php', 'status' => Update_Pilot_Compatibility::CURRENT, 'behind' => 0, 'tested' => '7.0' ),
	'b/b.php' => array( 'item' => 'b/b.php', 'status' => Update_Pilot_Compatibility::BEHIND, 'behind' => 4, 'tested' => '6.6' ),
	'c/c.php' => array( 'item' => 'c/c.php', 'status' => Update_Pilot_Compatibility::UNDECLARED, 'behind' => null, 'tested' => null ),
	'd/d.php' => array( 'item' => 'd/d.php', 'status' => Update_Pilot_Compatibility::NOT_HOSTED, 'behind' => null, 'tested' => null ),
	'e/e.php' => array( 'item' => 'e/e.php', 'status' => Update_Pilot_Compatibility::BEHIND, 'behind' => 9, 'tested' => '6.1' ),
);

$counts = Update_Pilot_Compatibility::summary( $report );

check( 1, $counts[ Update_Pilot_Compatibility::CURRENT ], 'one plugin is current' );
check( 2, $counts[ Update_Pilot_Compatibility::BEHIND ], 'two are behind' );
check( 1, $counts[ Update_Pilot_Compatibility::UNDECLARED ], 'one declares nothing' );
check( 1, $counts[ Update_Pilot_Compatibility::NOT_HOSTED ], 'one is not hosted on wordpress.org' );

$notable = Update_Pilot_Compatibility::noteworthy( $report );

check( 4, count( $notable ), 'the up-to-date plugin is left out of the list' );
check( 'e/e.php', array_key_first( $notable ), 'the most neglected comes first' );

echo "\n== Fingerprint ==\n";

$first = Update_Pilot_Compatibility::fingerprint( $report );

// Reordering must not look like a change.
$shuffled = array_reverse( $report, true );
check( $first, Update_Pilot_Compatibility::fingerprint( $shuffled ), 'order does not affect the fingerprint' );

// A plugin that is merely not hosted must not affect it either.
unset( $report['d/d.php'] );
check( $first, Update_Pilot_Compatibility::fingerprint( $report ), 'plugins outside wordpress.org do not affect it' );

// A real change must.
$report['b/b.php']['tested'] = '6.7';
check( true, $first !== Update_Pilot_Compatibility::fingerprint( $report ), 'but a changed declaration does' );

echo "\n== Whose declaration it is ==\n";

/*
 * describe() is the only place the distinction is visible, so it is the only
 * place worth asserting it. The two translation functions are stubbed to the
 * identity, which is what they do on an untranslated site anyway.
 */
if ( ! function_exists( '__' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}

	/**
	 * Stub.
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $number Count.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

$from_org = array_merge(
	Update_Pilot_Compatibility::classify( '6.5', $current, $branches, $default ),
	array( 'source' => Update_Pilot_Compatibility::DIRECTORY )
);

$from_self = array_merge(
	Update_Pilot_Compatibility::classify( '6.5', $current, $branches, $default ),
	array( 'source' => Update_Pilot_Compatibility::SELF_DECLARED )
);

check(
	'Tested up to WordPress 6.5 — 5 releases behind',
	Update_Pilot_Compatibility::describe( $from_org ),
	'wordpress.org speaks for itself and needs no attribution'
);

check(
	'Tested up to WordPress 6.5 — 5 releases behind (declared by the plugin itself, not by wordpress.org)',
	Update_Pilot_Compatibility::describe( $from_self ),
	'a plugin speaking about itself says so'
);

// A row from before this field existed must not gain an attribution it never had.
unset( $from_org['source'] );

check(
	'Tested up to WordPress 6.5 — 5 releases behind',
	Update_Pilot_Compatibility::describe( $from_org ),
	'a cached row with no source is treated as the directory'
);

check(
	'Not hosted on wordpress.org, so there is no declaration to read',
	Update_Pilot_Compatibility::describe(
		array(
			'status' => Update_Pilot_Compatibility::NOT_HOSTED,
			'source' => Update_Pilot_Compatibility::SELF_DECLARED,
		)
	),
	'a row with nothing to declare is not attributed to anybody'
);

printf( "\n%d checks, %d failures\n", $tests_run, $tests_failed );

exit( $tests_failed > 0 ? 1 : 0 );
