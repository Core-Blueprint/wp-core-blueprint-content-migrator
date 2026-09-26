<?php
declare(strict_types=1);

/**
 * Return Golden Standard safety-contract failures without booting WordPress.
 *
 * @return string[]
 */
function cb_cm_golden_safety_failures( string $root ): array {
	$failures = [];

	$read = static function ( string $path ) use ( $root, &$failures ): string {
		$full = $root . '/' . $path;
		if ( ! is_file( $full ) ) {
			$failures[] = 'Missing safety-contract file: ' . $path;
			return '';
		}
		return (string) file_get_contents( $full );
	};

	$require = static function ( string $content, string $needle, string $message ) use ( &$failures ): void {
		if ( ! str_contains( $content, $needle ) ) {
			$failures[] = $message;
		}
	};

	$bootstrap = $read( 'core-blueprint-content-migrator.php' );
	$require( $bootstrap, 'Requires Plugins: core-blueprint', 'Native Core Blueprint Base dependency is missing.' );
	$require( $bootstrap, "CB_CONTENT_MIGRATOR_REQUIRED_API', '1.1'", 'Required Core API 1.1 contract is missing.' );
	$require( $bootstrap, "CB_CONTENT_MIGRATOR_REQUIRED_BASE', '1.0.0-rc1'", 'Required Base version contract is missing.' );
	$require( $bootstrap, 'cb_content_migrator_base_ready()', 'Runtime Base compatibility gate is missing.' );

	$suite = $read( 'src/Integration/Suite.php' );
	$require( $suite, "'requires_base' => CB_CONTENT_MIGRATOR_REQUIRED_BASE", 'Extension Registry does not declare its Base version requirement.' );

	$events = $read( 'src/Governance/Events.php' );
	$require( $events, '\\CB\\Core\\Governance\\EventRegistry::register', 'Governance event registration is not mandatory.' );
	$require( $events, '\\CB\\Core\\Governance\\Audit::record', 'Governance audit writes are not mandatory.' );
	if ( str_contains( $events, "class_exists( '\\\\CB\\Core\\Governance" ) ) {
		$failures[] = 'Governance still contains an optional Base fallback.';
	}

	$controller = $read( 'src/Admin/Controller.php' );
	foreach ( [
		'assert_posted_job( $job )' => 'Stale-job request protection is missing.',
		'confirm_rollback' => 'Explicit rollback confirmation is missing.',
		'confirm_trash_source' => 'Explicit source-trash confirmation is missing.',
		'owner_user_id' => 'Migration ownership enforcement is missing.',
		'Events::TAKEN_OVER' => 'Migration ownership takeover audit event is missing.',
		'Events::ACTION_FAILED' => 'Failed migration actions are not audited.',
		"'finalization_failed'" => 'Failed finalization is not preserved as an active migration state.',
		'Events::FINALIZE_FAILED' => 'Failed finalization is not audited distinctly.',
	] as $needle => $message ) {
		$require( $controller, $needle, $message );
	}

	$require( $controller, 'Selection::posts(', 'Post jobs are not built from an explicitly validated source selection.' );
	$require( $controller, "'source_ids'          => \$source_ids", 'Validated post source selection is not stored in the migration job.' );
	$require( $controller, "'total'               => count( \$source_ids )", 'Post migration total does not follow the selected subset.' );

	$selection = $read( 'src/Migration/Selection.php' );
	$require( $selection, 'in_array( $id, $allowed, true )', 'Submitted post IDs are not pinned to the analyzed source allowlist.' );
	$require( $selection, 'Select at least one source post to migrate.', 'Empty post source selection is not explicitly rejected.' );
	$require( $selection, 'not part of the analyzed migration plan.', 'Tampered post source IDs are not explicitly rejected.' );

	$store = $read( 'src/Migration/JobStore.php' );
	$require( $store, 'add_option( self::ACTIVE_OPTION', 'Active migration lock is not acquired atomically.' );
	$require( $store, '$active_id !== $id', 'Job saves are not pinned to the active migration.' );

	$post = $read( 'src/Migration/PostRunner.php' );
	$tracked = strpos( $post, "\$job['target_map'][ (string) \$source_id ] = \$target_id;" );
	$post_marker = strpos( $post, 'update_post_meta( $target_id, self::JOB_META' );
	$meta = strpos( $post, 'self::copy_meta( $source_id, $target_id' );
	if ( false === $tracked || false === $post_marker || false === $meta || $tracked >= $post_marker || $tracked >= $meta ) {
		$failures[] = 'Post targets are not tracked immediately after insertion and before failure-prone writes.';
	}
	foreach ( [
		"current_user_can( 'edit_post', \$source_id )" => 'Source post object capability recheck is missing.',
		"current_user_can( 'delete_post', \$target_id )" => 'Target post delete capability recheck is missing.',
		'TermRollbackGuard::blocker' => 'Post migration term rollback guard is missing.',
		'self::finalize_preflight( $job, $trash_source )' => 'Post finalization preflight is missing.',
	] as $needle => $message ) {
		$require( $post, $needle, $message );
	}
	$preflight = strpos( $post, 'self::finalize_preflight( $job, $trash_source )' );
	$clear_marker = strpos( $post, 'delete_post_meta( (int) $target_id, self::JOB_META )' );
	if ( false === $preflight || false === $clear_marker || $preflight >= $clear_marker ) {
		$failures[] = 'Post rollback markers can be cleared before finalization preflight.';
	}

	$taxonomy = $read( 'src/Migration/TaxonomyRunner.php' );
	$tracked_term = strpos( $taxonomy, "\$job['created_target_ids'][] = \$target_id;" );
	$term_marker = strpos( $taxonomy, 'update_term_meta( $target_id, self::JOB_META' );
	$term_meta = strpos( $taxonomy, 'self::copy_term_meta( $source_id, $target_id' );
	if ( false === $tracked_term || false === $term_marker || false === $term_meta || $tracked_term >= $term_marker || $tracked_term >= $term_meta ) {
		$failures[] = 'Created taxonomy targets are not tracked immediately after insertion and before failure-prone writes.';
	}
	foreach ( [
		"current_user_can( 'edit_post', \$object_id )" => 'Relationship object capability recheck is missing.',
		'TermRollbackGuard::blocker' => 'Taxonomy migration term rollback guard is missing.',
		"current_user_can( 'edit_term_meta', \$target_id, \$target_key )" => 'Target term-meta capability recheck is missing.',
	] as $needle => $message ) {
		$require( $taxonomy, $needle, $message );
	}

	$term_guard = $read( 'src/Migration/TermRollbackGuard.php' );
	$require( $term_guard, 'get_objects_in_term( $term_id, $taxonomy )', 'Term rollback does not block externally used terms.' );
	$require( $term_guard, "'parent'     => \$term_id", 'Term rollback does not protect newly attached child terms.' );

	$page = $read( 'src/Admin/Page.php' );
	$require( $page, 'name="source_ids[]"', 'Post review UI does not submit an explicit source subset.' );
	$require( $page, 'cb-select-all-1', 'Post review UI is missing a select-all control.' );
	foreach ( [
		'name="job_id"' => 'Migration forms do not pin actions to a job ID.',
		'name="confirm_rollback"' => 'Rollback UI confirmation is missing.',
		'name="confirm_trash_source"' => 'Source-trash UI confirmation is missing.',
		'name="confirm_takeover"' => 'Ownership takeover UI confirmation is missing.',
	] as $needle => $message ) {
		$require( $page, $needle, $message );
	}

	return $failures;
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	$failures = cb_cm_golden_safety_failures( dirname( __DIR__ ) );
	if ( $failures ) {
		fwrite( STDERR, "Core Blueprint Content Migrator Golden safety regression: FAIL\n" );
		foreach ( $failures as $failure ) {
			fwrite( STDERR, '- ' . $failure . "\n" );
		}
		exit( 1 );
	}
	fwrite( STDOUT, "Core Blueprint Content Migrator Golden safety regression: PASS\n" );
}
