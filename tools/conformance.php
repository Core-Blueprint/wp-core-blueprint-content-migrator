<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$failures = [];
$expected = [
	'core-blueprint-content-migrator.php',
	'src/Plugin.php',
	'src/Admin/Page.php',
	'src/Admin/Controller.php',
	'src/Migration/PostAnalyzer.php',
	'src/Migration/PostRunner.php',
	'src/Migration/Selection.php',
	'src/Migration/TaxonomyAnalyzer.php',
	'src/Migration/TaxonomyRunner.php',
	'src/Migration/TermRollbackGuard.php',
	'src/Migration/PlanStore.php',
	'src/Migration/JobStore.php',
	'src/Governance/Events.php',
	'src/Integration/Suite.php',
];
foreach ( $expected as $path ) {
	if ( ! is_file( $root . '/' . $path ) ) {
		$failures[] = 'Missing expected runtime file: ' . $path;
	}
}

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if (
		! $file instanceof SplFileInfo
		|| ! $file->isFile()
		|| 'php' !== strtolower( $file->getExtension() )
		|| str_contains( $file->getPathname(), '/build/' )
		|| $file->getPathname() === __FILE__
	) {
		continue;
	}
	$content = (string) file_get_contents( $file->getPathname() );
	$forbidden = [
		'cb-core-css-'                  => 'private Base asset handles are forbidden',
		'CB\\Core\\Admin\\PageRegistry' => 'extensions must not require the private Base Admin PageRegistry',
		'jquery'                        => 'Content Migrator has no jQuery runtime',
		'cb_post_migrator'              => 'old Post Migrator identifiers must not remain',
		'CB\\PostMigrator'              => 'old Post Migrator namespace must not remain',
	];
	foreach ( $forbidden as $needle => $reason ) {
		if ( false !== stripos( $content, $needle ) ) {
			$failures[] = $file->getFilename() . ' contains forbidden pattern ' . $needle . ' (' . $reason . ').';
		}
	}
}

$bootstrap = (string) file_get_contents( $root . '/core-blueprint-content-migrator.php' );
foreach ( [
	'Requires Plugins: core-blueprint' => 'Native Core Blueprint Base dependency is missing.',
	"CB_CONTENT_MIGRATOR_REQUIRED_API', '1.1'" => 'Core API 1.1 requirement is missing.',
	"CB_CONTENT_MIGRATOR_REQUIRED_BASE', '1.0.0-rc1'" => 'Minimum Base version requirement is missing.',
	'cb_content_migrator_base_ready()' => 'Runtime Base compatibility gate is missing.',
	'\\CB\\ContentMigrator\\Plugin::boot()' => 'Plugin bootstrap is missing.',
] as $needle => $message ) {
	if ( ! str_contains( $bootstrap, $needle ) ) {
		$failures[] = $message;
	}
}
if ( str_contains( $bootstrap, 'deactivate_plugins' ) ) {
	$failures[] = 'Content Migrator must rely on the native dependency contract and must not self-deactivate Base or itself.';
}

$post_runner = (string) file_get_contents( $root . '/src/Migration/PostRunner.php' );
foreach ( [ 'wp_insert_post', 'wp_trash_post', 'wp_delete_post', 'wp_insert_term', 'wp_delete_term', 'created_taxonomy_terms', '_cb_content_migrator_job', 'verify(', 'rollback(', 'finalize(' ] as $required ) {
	if ( ! str_contains( $post_runner, $required ) ) {
		$failures[] = 'Post migration safety contract is missing ' . $required . '.';
	}
}
if ( str_contains( $post_runner, 'wp_delete_post( $source' ) || str_contains( $post_runner, 'wp_delete_post( $source_id' ) ) {
	$failures[] = 'Source posts must never be permanently deleted in RC1.';
}

$taxonomy_runner = (string) file_get_contents( $root . '/src/Migration/TaxonomyRunner.php' );
foreach ( [ 'wp_insert_term', 'wp_delete_term', 'wp_set_object_terms', 'wp_remove_object_terms', 'get_term_meta', 'verify(', 'rollback(', 'finalize(' ] as $required ) {
	if ( ! str_contains( $taxonomy_runner, $required ) ) {
		$failures[] = 'Taxonomy migration safety contract is missing ' . $required . '.';
	}
}
if ( str_contains( $taxonomy_runner, 'wp_delete_term( $source' ) ) {
	$failures[] = 'Source taxonomy terms must never be deleted in RC1.';
}

$events = (string) file_get_contents( $root . '/src/Governance/Events.php' );
if ( ! str_contains( $events, 'EventRegistry::register' ) || ! str_contains( $events, 'Audit::record' ) ) {
	$failures[] = 'Governance registration and audit writes are required Base contracts.';
}
if ( str_contains( $events, 'class_exists' ) ) {
	$failures[] = 'Governance must not silently fall back when required Base contracts are missing.';
}

$page = (string) file_get_contents( $root . '/src/Admin/Page.php' );
if ( ! str_contains( $page, 'add_management_page' ) || ! str_contains( $page, 'Content Migrator' ) ) {
	$failures[] = 'WordPress-native Content Migrator Tools page is missing.';
}

$safety_test = $root . '/tests/golden-safety-regression.php';
if ( ! is_file( $safety_test ) ) {
	$failures[] = 'Golden safety regression test is missing.';
} else {
	require_once $safety_test;
	$failures = array_merge( $failures, cb_cm_golden_safety_failures( $root ) );
}

if ( $failures ) {
	fwrite( STDERR, "Core Blueprint Content Migrator conformance: FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '- ' . $failure . "\n" );
	}
	exit( 1 );
}
fwrite( STDOUT, "Core Blueprint Content Migrator conformance: PASS\n" );
