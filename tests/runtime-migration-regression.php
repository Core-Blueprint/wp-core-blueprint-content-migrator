<?php
declare(strict_types=1);

use CB\ContentMigrator\Governance\Events;
use CB\ContentMigrator\Integration\Suite;
use CB\ContentMigrator\Migration\PostRunner;
use CB\ContentMigrator\Migration\TaxonomyRunner;

defined( 'ABSPATH' ) || exit;

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$assert_same = static function ( mixed $expected, mixed $actual, string $message ) use ( &$failures ): void {
	if ( $expected !== $actual ) {
		$failures[] = $message . sprintf(
			' (expected %s, got %s)',
			wp_json_encode( $expected ),
			wp_json_encode( $actual )
		);
	}
};

wp_set_current_user( 1 );
$suffix = strtolower( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 ) );

$source_type = 'cbcm_src_' . $suffix;
$target_type = 'cbcm_dst_' . $suffix;
$object_type = 'cbcm_obj_' . $suffix;
$source_tax  = 'cbcm_st_' . $suffix;
$target_tax  = 'cbcm_tt_' . $suffix;
$guard_source_tax = 'cbcm_gs_' . $suffix;
$guard_target_tax = 'cbcm_gt_' . $suffix;

foreach ( [
	$source_type => [ 'title', 'editor', 'thumbnail' ],
	$target_type => [ 'title', 'editor', 'thumbnail' ],
	$object_type => [ 'title' ],
] as $post_type => $supports ) {
	register_post_type(
		$post_type,
		[
			'label'        => $post_type,
			'public'       => false,
			'show_ui'      => true,
			'supports'     => $supports,
			'capability_type' => 'post',
			'map_meta_cap' => true,
		]
	);
}

register_taxonomy( $source_tax, [ $source_type ], [
	'label'        => $source_tax,
	'public'       => false,
	'show_ui'      => true,
	'hierarchical' => true,
] );
register_taxonomy( $target_tax, [ $target_type ], [
	'label'        => $target_tax,
	'public'       => false,
	'show_ui'      => true,
	'hierarchical' => true,
] );
register_taxonomy( $guard_source_tax, [ $object_type ], [
	'label'        => $guard_source_tax,
	'public'       => false,
	'show_ui'      => true,
	'hierarchical' => true,
] );
register_taxonomy( $guard_target_tax, [ $object_type ], [
	'label'        => $guard_target_tax,
	'public'       => false,
	'show_ui'      => true,
	'hierarchical' => true,
] );

// Required Base/runtime integration.
$assert( function_exists( 'cb_content_migrator_base_ready' ) && cb_content_migrator_base_ready(), 'Required Core Blueprint Base runtime is not ready.' );
$definition = \CB\Core\ExtensionRegistry::definition( Suite::ID );
$assert( is_array( $definition ), 'Content Migrator did not register with the Base Extension Registry.' );
if ( is_array( $definition ) ) {
	$assert_same( CB_CONTENT_MIGRATOR_REQUIRED_API, $definition['requires_api'] ?? null, 'Extension Registry Core API requirement differs.' );
	$assert_same( CB_CONTENT_MIGRATOR_REQUIRED_BASE, $definition['requires_base'] ?? null, 'Extension Registry Base requirement differs.' );
}
$assert_same(
	'maintenance',
	\CB\Core\Governance\EventRegistry::retention_category( Events::CREATED ),
	'Migration Governance event does not use maintenance retention.'
);

// Real post migration: copy core data, mapped meta and taxonomy, verify, rollback.
$source_term_result = wp_insert_term( 'Runtime Source Term ' . $suffix, $source_tax, [ 'slug' => 'runtime-term-' . $suffix ] );
$assert( ! is_wp_error( $source_term_result ), 'Could not create runtime source taxonomy term.' );
$source_term_id = is_wp_error( $source_term_result ) ? 0 : (int) $source_term_result['term_id'];

$source_id = wp_insert_post(
	[
		'post_type'    => $source_type,
		'post_status'  => 'publish',
		'post_title'   => 'Runtime source ' . $suffix,
		'post_content' => 'Runtime migration body ' . $suffix,
		'post_excerpt' => 'Runtime excerpt ' . $suffix,
	],
	true
);
$assert( ! is_wp_error( $source_id ), 'Could not create runtime source post.' );
$source_id = is_wp_error( $source_id ) ? 0 : (int) $source_id;

if ( $source_id > 0 ) {
	update_post_meta( $source_id, 'cbcm_runtime_meta', 'runtime-value-' . $suffix );
	if ( $source_term_id > 0 ) {
		wp_set_object_terms( $source_id, [ $source_term_id ], $source_tax, false );
	}
}

$post_job = [
	'id'                     => 'post' . $suffix,
	'mode'                   => 'post',
	'source_type'            => $source_type,
	'target_type'            => $target_type,
	'source_ids'             => [ $source_id ],
	'total'                  => 1,
	'cursor'                 => 0,
	'batch_size'             => 10,
	'tax_map'                => [ $source_tax => $target_tax ],
	'meta_map'               => [ 'cbcm_runtime_meta' => 'cbcm_runtime_meta' ],
	'copy_featured_image'    => false,
	'target_map'             => [],
	'created_taxonomy_terms' => [],
	'errors'                 => [],
	'status'                 => 'ready',
];

$post_job = PostRunner::run_batch( $post_job );
$assert_same( 'copied', $post_job['status'] ?? null, 'Post migration did not reach copied state.' );
$assert_same( [], $post_job['errors'] ?? null, 'Post migration recorded unexpected copy errors.' );
$target_id = (int) ( $post_job['target_map'][ (string) $source_id ] ?? 0 );
$assert( $target_id > 0 && get_post( $target_id ) instanceof WP_Post, 'Post migration did not create and track a target post.' );
if ( $target_id > 0 ) {
	$assert_same( 'runtime-value-' . $suffix, get_post_meta( $target_id, 'cbcm_runtime_meta', true ), 'Mapped post meta was not copied.' );
	$target_slugs = wp_get_object_terms( $target_id, $target_tax, [ 'fields' => 'slugs' ] );
	$assert( ! is_wp_error( $target_slugs ) && in_array( 'runtime-term-' . $suffix, (array) $target_slugs, true ), 'Mapped taxonomy term was not copied.' );
}
$post_verify = PostRunner::verify( $post_job );
$assert( ! empty( $post_verify['passed'] ), 'Post migration verification failed: ' . implode( '; ', (array) ( $post_verify['issues'] ?? [] ) ) );

$post_rollback = PostRunner::rollback( $post_job );
$assert_same( [], $post_rollback['issues'] ?? null, 'Post migration rollback returned issues.' );
$assert( $target_id <= 0 || null === get_post( $target_id ), 'Post rollback left the target post behind.' );
$assert( $source_id > 0 && get_post( $source_id ) instanceof WP_Post, 'Post rollback modified or removed the source post.' );
foreach ( (array) ( $post_job['created_taxonomy_terms'][ $target_tax ] ?? [] ) as $created_term_id ) {
	$assert( ! get_term( (int) $created_term_id, $target_tax ) instanceof WP_Term, 'Post rollback left a job-created target term behind.' );
}

// Partial post-copy failure must remain tracked and rollbackable.
$partial_source_id = wp_insert_post(
	[
		'post_type'   => $source_type,
		'post_status' => 'draft',
		'post_title'  => 'Runtime partial ' . $suffix,
	],
	true
);
$partial_source_id = is_wp_error( $partial_source_id ) ? 0 : (int) $partial_source_id;
if ( $partial_source_id > 0 ) {
	update_post_meta( $partial_source_id, 'cbcm_blocked_meta', 'must-fail' );
}
$deny_meta = static fn( mixed $allowed ): bool => false;
add_filter( 'auth_post_meta_cbcm_blocked_meta', $deny_meta, 10, 1 );

$partial_job = [
	'id'                     => 'partial' . $suffix,
	'mode'                   => 'post',
	'source_type'            => $source_type,
	'target_type'            => $target_type,
	'source_ids'             => [ $partial_source_id ],
	'total'                  => 1,
	'cursor'                 => 0,
	'batch_size'             => 10,
	'tax_map'                => [],
	'meta_map'               => [ 'cbcm_blocked_meta' => 'cbcm_blocked_meta' ],
	'copy_featured_image'    => false,
	'target_map'             => [],
	'created_taxonomy_terms' => [],
	'errors'                 => [],
	'status'                 => 'ready',
];
$partial_job = PostRunner::run_batch( $partial_job );
remove_filter( 'auth_post_meta_cbcm_blocked_meta', $deny_meta, 10 );

$partial_target_id = (int) ( $partial_job['target_map'][ (string) $partial_source_id ] ?? 0 );
$assert( ! empty( $partial_job['errors'] ), 'Forced post-meta failure did not record a copy error.' );
$assert( $partial_target_id > 0 && get_post( $partial_target_id ) instanceof WP_Post, 'Partial target was not retained in rollback tracking.' );
$partial_verify = PostRunner::verify( $partial_job );
$assert( empty( $partial_verify['passed'] ), 'Verification passed despite a recorded partial-copy error.' );
$partial_rollback = PostRunner::rollback( $partial_job );
$assert_same( [], $partial_rollback['issues'] ?? null, 'Partial post rollback returned issues.' );
$assert( $partial_target_id <= 0 || null === get_post( $partial_target_id ), 'Partial target was not removed by rollback.' );
$assert( $partial_source_id > 0 && get_post( $partial_source_id ) instanceof WP_Post, 'Partial rollback modified or removed the source post.' );

// Taxonomy rollback must refuse deletion after external content starts using a job-created term.
$guard_source_result = wp_insert_term( 'Runtime Guard Source ' . $suffix, $guard_source_tax, [ 'slug' => 'runtime-guard-' . $suffix ] );
$guard_source_id = is_wp_error( $guard_source_result ) ? 0 : (int) $guard_source_result['term_id'];
$assert( $guard_source_id > 0, 'Could not create taxonomy rollback-guard source term.' );

$taxonomy_job = [
	'id'                  => 'tax' . $suffix,
	'mode'                => 'taxonomy',
	'source_taxonomy'     => $guard_source_tax,
	'target_taxonomy'     => $guard_target_tax,
	'source_ids'          => [ $guard_source_id ],
	'relationship_ids'    => [],
	'copy_relationships'  => false,
	'target_hierarchical' => true,
	'term_meta_map'       => [],
	'term_cursor'         => 0,
	'relationship_cursor' => 0,
	'cursor'              => 0,
	'total'               => 1,
	'batch_size'          => 10,
	'term_map'            => [],
	'created_target_ids'  => [],
	'added_relationships' => [],
	'errors'              => [],
	'status'              => 'ready',
];

$taxonomy_job = TaxonomyRunner::run_batch( $taxonomy_job );
$assert_same( [], $taxonomy_job['errors'] ?? null, 'Taxonomy migration recorded unexpected copy errors.' );
$guard_target_id = (int) ( $taxonomy_job['term_map'][ (string) $guard_source_id ] ?? 0 );
$assert( $guard_target_id > 0 && get_term( $guard_target_id, $guard_target_tax ) instanceof WP_Term, 'Taxonomy migration did not create and track a target term.' );
$taxonomy_verify = TaxonomyRunner::verify( $taxonomy_job );
$assert( ! empty( $taxonomy_verify['passed'] ), 'Taxonomy migration verification failed: ' . implode( '; ', (array) ( $taxonomy_verify['issues'] ?? [] ) ) );

$external_post_id = wp_insert_post(
	[
		'post_type'   => $object_type,
		'post_status' => 'publish',
		'post_title'  => 'Runtime external consumer ' . $suffix,
	],
	true
);
$external_post_id = is_wp_error( $external_post_id ) ? 0 : (int) $external_post_id;
if ( $external_post_id > 0 && $guard_target_id > 0 ) {
	wp_set_object_terms( $external_post_id, [ $guard_target_id ], $guard_target_tax, false );
}
$guarded_rollback = TaxonomyRunner::rollback( $taxonomy_job );
$assert( ! empty( $guarded_rollback['issues'] ), 'Taxonomy rollback did not refuse deletion of an externally used target term.' );
$assert( $guard_target_id > 0 && get_term( $guard_target_id, $guard_target_tax ) instanceof WP_Term, 'Taxonomy rollback deleted a target term that external content was using.' );

if ( $external_post_id > 0 && $guard_target_id > 0 ) {
	wp_remove_object_terms( $external_post_id, [ $guard_target_id ], $guard_target_tax );
}
$clean_rollback = TaxonomyRunner::rollback( $taxonomy_job );
$assert_same( [], $clean_rollback['issues'] ?? null, 'Taxonomy rollback did not recover after the external relationship was removed.' );
$assert( $guard_target_id <= 0 || ! get_term( $guard_target_id, $guard_target_tax ) instanceof WP_Term, 'Taxonomy rollback left an unreferenced job-created term behind.' );

// Cleanup source fixtures. Rollback assertions above intentionally happen first.
foreach ( [ $source_id, $partial_source_id, $external_post_id ] as $post_id ) {
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
}
foreach ( [
	[ $source_term_id, $source_tax ],
	[ $guard_source_id, $guard_source_tax ],
] as [ $term_id, $taxonomy ] ) {
	if ( $term_id > 0 && get_term( $term_id, $taxonomy ) instanceof WP_Term ) {
		wp_delete_term( $term_id, $taxonomy );
	}
}

if ( $failures ) {
	fwrite( STDERR, "Core Blueprint Content Migrator runtime regression: FAIL\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '- ' . $failure . "\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "Core Blueprint Content Migrator runtime regression: PASS\n" );
