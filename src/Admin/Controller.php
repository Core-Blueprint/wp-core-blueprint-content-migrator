<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Admin;

use CB\ContentMigrator\Governance\Events;
use CB\ContentMigrator\Migration\JobStore;
use CB\ContentMigrator\Migration\PlanStore;
use CB\ContentMigrator\Migration\PostAnalyzer;
use CB\ContentMigrator\Migration\PostRunner;
use CB\ContentMigrator\Migration\Selection;
use CB\ContentMigrator\Migration\TaxonomyAnalyzer;
use CB\ContentMigrator\Migration\TaxonomyRunner;

defined( 'ABSPATH' ) || exit;

final class Controller {
	public static function init(): void {
		foreach ( [ 'analyze', 'create_job', 'run_batch', 'verify', 'rollback', 'finalize', 'clear_plan', 'takeover' ] as $action ) {
			add_action( 'admin_post_cb_content_migrator_' . $action, [ __CLASS__, $action ] );
		}
	}

	public static function analyze(): void {
		self::guard( 'analyze' );
		try {
			$mode = isset( $_POST['mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['mode'] ) ) : 'post';
			if ( 'taxonomy' === $mode ) {
				$source = isset( $_POST['source_taxonomy'] ) ? sanitize_key( (string) wp_unslash( $_POST['source_taxonomy'] ) ) : '';
				$target = isset( $_POST['target_taxonomy'] ) ? sanitize_key( (string) wp_unslash( $_POST['target_taxonomy'] ) ) : '';
				$plan = TaxonomyAnalyzer::analyze( $source, $target );
			} else {
				$source = isset( $_POST['source_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['source_type'] ) ) : '';
				$target = isset( $_POST['target_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['target_type'] ) ) : '';
				$plan = PostAnalyzer::analyze( $source, $target );
				$plan['mode'] = 'post';
			}
			PlanStore::save( $plan );
			Events::record( Events::ANALYZED, 'info', self::plan_context( $plan ) );
			self::redirect( 'plan_ready' );
		} catch ( \Throwable $e ) {
			self::fail( 'analyze', $e );
		}
	}

	public static function create_job(): void {
		self::guard( 'create_job' );
		try {
			$plan = PlanStore::load();
			if ( ! is_array( $plan ) ) {
				throw new \RuntimeException( __( 'Migration analysis expired. Analyze the source and target again.', 'core-blueprint-content-migrator' ) );
			}
			$mode = sanitize_key( (string) ( $plan['mode'] ?? 'post' ) );
			$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;
			$batch_size = max( 10, min( 200, $batch_size ) );
			if ( 'taxonomy' === $mode ) {
				$allowed_source_ids = array_values( array_map( 'intval', (array) $plan['source_ids'] ) );
				$selected_source_ids = Selection::terms(
					$allowed_source_ids,
					isset( $_POST['source_ids'] ) ? wp_unslash( $_POST['source_ids'] ) : [],
					(string) $plan['source_taxonomy'],
					false
				);
				$source_ids = Selection::terms(
					$allowed_source_ids,
					$selected_source_ids,
					(string) $plan['source_taxonomy'],
					! empty( $plan['source_hierarchical'] ) && ! empty( $plan['target_hierarchical'] )
				);
				$term_meta_map = self::sanitize_meta_map( $plan, isset( $_POST['term_meta_map'] ) && is_array( $_POST['term_meta_map'] ) ? wp_unslash( $_POST['term_meta_map'] ) : [], 'term' );
				$copy_relationships = ! empty( $_POST['copy_relationships'] ) && ! empty( $plan['relationships_supported'] );
				$relationship_ids = $copy_relationships
					? Selection::relationships(
						array_values( array_map( 'intval', (array) $plan['relationship_ids'] ) ),
						$selected_source_ids,
						(string) $plan['source_taxonomy']
					)
					: [];
				$job = JobStore::create( [
					'mode'                  => 'taxonomy',
					'source_taxonomy'       => (string) $plan['source_taxonomy'],
					'target_taxonomy'       => (string) $plan['target_taxonomy'],
					'source_label'          => (string) $plan['source_label'],
					'target_label'          => (string) $plan['target_label'],
					'source_ids'            => $source_ids,
					'target_hierarchical'   => ! empty( $plan['target_hierarchical'] ),
					'term_meta_map'         => $term_meta_map,
					'copy_relationships'    => $copy_relationships,
					'relationship_ids'      => $relationship_ids,
					'term_cursor'           => 0,
					'relationship_cursor'   => 0,
					'cursor'                => 0,
					'total'                 => count( $source_ids ) + count( $relationship_ids ),
					'batch_size'            => $batch_size,
					'term_map'              => [],
					'created_target_ids'    => [],
					'added_relationships'   => [],
					'errors'                => [],
					'status'                => 'ready',
					'verification'          => null,
				] );
			} else {
				$source_ids = Selection::posts(
					array_values( array_map( 'intval', (array) $plan['source_ids'] ) ),
					isset( $_POST['source_ids'] ) ? wp_unslash( $_POST['source_ids'] ) : []
				);
				$tax_map = self::sanitize_tax_map( $plan, isset( $_POST['tax_map'] ) && is_array( $_POST['tax_map'] ) ? wp_unslash( $_POST['tax_map'] ) : [] );
				$meta_map = self::sanitize_meta_map( $plan, isset( $_POST['meta_map'] ) && is_array( $_POST['meta_map'] ) ? wp_unslash( $_POST['meta_map'] ) : [], 'post' );
				$job = JobStore::create( [
					'mode'                => 'post',
					'source_type'         => (string) $plan['source_type'],
					'target_type'         => (string) $plan['target_type'],
					'source_label'        => (string) $plan['source_label'],
					'target_label'        => (string) $plan['target_label'],
					'source_ids'          => $source_ids,
					'total'               => count( $source_ids ),
					'cursor'              => 0,
					'batch_size'          => $batch_size,
					'tax_map'             => $tax_map,
					'meta_map'            => $meta_map,
					'copy_featured_image' => ! empty( $_POST['copy_featured_image'] ),
					'target_map'          => [],
					'errors'              => [],
					'status'              => 'ready',
					'verification'        => null,
				] );
			}
			PlanStore::clear();
			Events::record( Events::CREATED, 'notice', self::event_context( $job ) );
			self::redirect( 'job_created' );
		} catch ( \Throwable $e ) {
			self::fail( 'create_job', $e );
		}
	}

	public static function run_batch(): void {
		self::guard( 'run_batch' );
		try {
			$job = self::active_job();
			if ( ! in_array( (string) $job['status'], [ 'ready', 'copying', 'copying_terms', 'copying_relationships' ], true ) ) {
				throw new \RuntimeException( __( 'This migration is not ready to copy another batch.', 'core-blueprint-content-migrator' ) );
			}
			$before = (int) ( $job['cursor'] ?? 0 );
			$job = self::runner( $job )::run_batch( $job );
			JobStore::save( $job );
			Events::record(
				Events::BATCH,
				empty( $job['errors'] ) ? 'info' : 'warning',
				self::event_context( $job ) + [
					'batch_count' => max( 0, (int) $job['cursor'] - $before ),
					'issues'      => count( (array) ( $job['errors'] ?? [] ) ),
				]
			);
			self::redirect( 'batch_complete' );
		} catch ( \Throwable $e ) {
			self::fail( 'run_batch', $e );
		}
	}

	public static function verify(): void {
		self::guard( 'verify' );
		try {
			$job = self::active_job();
			if ( ! in_array( (string) $job['status'], [ 'copied', 'verification_failed', 'verified' ], true ) ) {
				throw new \RuntimeException( __( 'Finish copying all batches before verification.', 'core-blueprint-content-migrator' ) );
			}
			$result = self::runner( $job )::verify( $job );
			$job['verification'] = $result;
			$job['status'] = $result['passed'] ? 'verified' : 'verification_failed';
			JobStore::save( $job );
			Events::record(
				Events::VERIFIED,
				$result['passed'] ? 'notice' : 'warning',
				self::event_context( $job ) + [
					'passed' => $result['passed'],
					'issues' => count( $result['issues'] ),
				]
			);
			self::redirect( $result['passed'] ? 'verified' : 'verification_failed' );
		} catch ( \Throwable $e ) {
			self::fail( 'verify', $e );
		}
	}

	public static function rollback(): void {
		self::guard( 'rollback' );
		try {
			self::require_confirmation(
				'confirm_rollback',
				__( 'Confirm that you want to permanently delete the targets created by this migration.', 'core-blueprint-content-migrator' )
			);
			$job = self::active_job();
			$result = self::runner( $job )::rollback( $job );
			if ( ! empty( $result['issues'] ) ) {
				$job['status'] = 'rollback_failed';
				$job['rollback'] = $result;
				JobStore::save( $job );
				Events::record(
					Events::ROLLBACK_FAILED,
					'warning',
					self::event_context( $job ) + [ 'issues' => count( (array) $result['issues'] ) ]
				);
				self::redirect( 'rollback_failed' );
				return;
			}
			Events::record(
				Events::ROLLEDBACK,
				'warning',
				self::event_context( $job ) + [ 'deleted_targets' => (int) ( $result['deleted'] ?? 0 ) ]
			);
			JobStore::delete( (string) $job['id'] );
			self::redirect( 'rolled_back' );
		} catch ( \Throwable $e ) {
			self::fail( 'rollback', $e );
		}
	}

	public static function finalize(): void {
		self::guard( 'finalize' );
		try {
			$job = self::active_job();
			if ( ! in_array( (string) $job['status'], [ 'verified', 'finalization_failed' ], true ) ) {
				throw new \RuntimeException( __( 'A migration can only be finalized after a successful verification.', 'core-blueprint-content-migrator' ) );
			}
			$trash_source = 'post' === (string) ( $job['mode'] ?? 'post' ) && isset( $_POST['trash_source'] ) && '1' === (string) wp_unslash( $_POST['trash_source'] );
			if ( $trash_source ) {
				self::require_confirmation(
					'confirm_trash_source',
					__( 'Confirm that you want to move the source posts to WordPress Trash.', 'core-blueprint-content-migrator' )
				);
			}

			$result = self::runner( $job )::finalize( $job, $trash_source );
			if ( $trash_source ) {
				Events::record(
					Events::TRASHED,
					empty( $result['issues'] ) ? 'notice' : 'warning',
					self::event_context( $job ) + [
						'trashed_sources' => (int) ( $result['trashed'] ?? 0 ),
						'issues'          => count( (array) $result['issues'] ),
					]
				);
			}

			if ( ! empty( $result['issues'] ) ) {
				$job['status'] = 'finalization_failed';
				$job['finalization'] = $result;
				JobStore::save( $job );
				Events::record(
					Events::FINALIZE_FAILED,
					'warning',
					self::event_context( $job ) + [
						'source_kept' => ! $trash_source,
						'issues'      => count( (array) $result['issues'] ),
					]
				);
				self::redirect( 'finalization_failed' );
				return;
			}

			$job['status'] = 'finalized';
			Events::record(
				Events::FINALIZED,
				'notice',
				self::event_context( $job ) + [
					'source_kept' => ! $trash_source,
					'issues'      => 0,
				]
			);
			JobStore::delete( (string) $job['id'] );
			self::redirect( 'finalized' );
		} catch ( \Throwable $e ) {
			self::fail( 'finalize', $e );
		}
	}

	public static function clear_plan(): void {
		self::guard( 'clear_plan' );
		$plan = PlanStore::load();
		PlanStore::clear();
		Events::record( Events::PLAN_CLEARED, 'info', is_array( $plan ) ? self::plan_context( $plan ) : [] );
		self::redirect( 'plan_cleared' );
	}

	public static function takeover(): void {
		self::guard( 'takeover' );
		try {
			self::require_confirmation(
				'confirm_takeover',
				__( 'Confirm that you want to take ownership of this migration.', 'core-blueprint-content-migrator' )
			);
			$job = JobStore::load_active();
			if ( ! is_array( $job ) ) {
				throw new \RuntimeException( __( 'No active migration was found.', 'core-blueprint-content-migrator' ) );
			}
			self::assert_posted_job( $job );
			$previous_owner = (int) ( $job['owner_user_id'] ?? 0 );
			$job['owner_user_id'] = get_current_user_id();
			JobStore::save( $job );
			Events::record(
				Events::TAKEN_OVER,
				'warning',
				self::event_context( $job ) + [ 'previous_owner_user_id' => $previous_owner ]
			);
			self::redirect( 'job_taken_over' );
		} catch ( \Throwable $e ) {
			self::fail( 'takeover', $e );
		}
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to use Content Migrator.', 'core-blueprint-content-migrator' ) );
		}
		check_admin_referer( 'cb_content_migrator_' . $action, 'cb_content_migrator_nonce' );
	}

	/** @return array<string,mixed> */
	private static function active_job(): array {
		$job = JobStore::load_active();
		if ( ! is_array( $job ) ) {
			throw new \RuntimeException( __( 'No active migration was found.', 'core-blueprint-content-migrator' ) );
		}
		self::assert_posted_job( $job );
		$owner = (int) ( $job['owner_user_id'] ?? 0 );
		if ( $owner <= 0 || $owner !== get_current_user_id() ) {
			throw new \RuntimeException( __( 'This migration is owned by another administrator. Take over the migration before changing it.', 'core-blueprint-content-migrator' ) );
		}
		return $job;
	}

	/** @param array<string,mixed> $job */
	private static function assert_posted_job( array $job ): void {
		$posted = isset( $_POST['job_id'] ) && ! is_array( $_POST['job_id'] )
			? sanitize_key( (string) wp_unslash( $_POST['job_id'] ) )
			: '';
		$current = sanitize_key( (string) ( $job['id'] ?? '' ) );
		if ( '' === $posted || '' === $current || ! hash_equals( $current, $posted ) ) {
			throw new \RuntimeException( __( 'The active migration changed. Refresh this page before running another action.', 'core-blueprint-content-migrator' ) );
		}
	}

	private static function require_confirmation( string $field, string $message ): void {
		$value = isset( $_POST[ $field ] ) && ! is_array( $_POST[ $field ] )
			? (string) wp_unslash( $_POST[ $field ] )
			: '';
		if ( '1' !== $value ) {
			throw new \RuntimeException( $message );
		}
	}

	/** @param array<string,mixed> $job @return class-string<PostRunner|TaxonomyRunner> */
	private static function runner( array $job ): string {
		return 'taxonomy' === sanitize_key( (string) ( $job['mode'] ?? 'post' ) ) ? TaxonomyRunner::class : PostRunner::class;
	}

	/** @param array<string,mixed> $plan @param array<mixed> $raw @return array<string,string> */
	private static function sanitize_tax_map( array $plan, array $raw ): array {
		$source = array_keys( (array) $plan['source_taxonomies'] );
		$target = array_keys( (array) $plan['target_taxonomies'] );
		$out = [];
		foreach ( $raw as $source_tax => $target_tax ) {
			$source_tax = sanitize_key( (string) $source_tax );
			$target_tax = sanitize_key( (string) $target_tax );
			if ( '' !== $target_tax && in_array( $source_tax, $source, true ) && in_array( $target_tax, $target, true ) ) {
				$out[ $source_tax ] = $target_tax;
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $plan @param array<mixed> $raw @return array<string,string> */
	private static function sanitize_meta_map( array $plan, array $raw, string $object_kind ): array {
		$source = array_values( array_map( 'strval', (array) $plan['source_meta_keys'] ) );
		$out = [];
		foreach ( $raw as $source_key => $target_key ) {
			$source_key = (string) $source_key;
			$target_key = trim( (string) $target_key );
			if ( ! in_array( $source_key, $source, true ) || '' === $target_key ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,191}$/', $target_key ) || str_starts_with( $target_key, '_cb_content_migrator_' ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: 1: object kind, 2: invalid meta key. */
						__( 'Invalid target %1$s meta key: %2$s', 'core-blueprint-content-migrator' ),
						$object_kind,
						$target_key
					)
				);
			}
			$out[ $source_key ] = $target_key;
		}
		return $out;
	}

	/** @param array<string,mixed> $job @return array<string,mixed> */
	private static function event_context( array $job ): array {
		return [
			'job_id'    => sanitize_key( (string) ( $job['id'] ?? '' ) ),
			'mode'      => sanitize_key( (string) ( $job['mode'] ?? 'post' ) ),
			'source'    => sanitize_key( (string) ( $job['source_type'] ?? $job['source_taxonomy'] ?? '' ) ),
			'target'    => sanitize_key( (string) ( $job['target_type'] ?? $job['target_taxonomy'] ?? '' ) ),
			'status'    => sanitize_key( (string) ( $job['status'] ?? '' ) ),
			'total'     => (int) ( $job['total'] ?? 0 ),
			'processed' => (int) ( $job['cursor'] ?? 0 ),
		];
	}

	/** @param array<string,mixed> $plan @return array<string,mixed> */
	private static function plan_context( array $plan ): array {
		return [
			'mode'   => sanitize_key( (string) ( $plan['mode'] ?? 'post' ) ),
			'source' => sanitize_key( (string) ( $plan['source_type'] ?? $plan['source_taxonomy'] ?? '' ) ),
			'target' => sanitize_key( (string) ( $plan['target_type'] ?? $plan['target_taxonomy'] ?? '' ) ),
			'total'  => (int) ( $plan['total'] ?? count( (array) ( $plan['source_ids'] ?? [] ) ) ),
		];
	}

	private static function fail( string $action, \Throwable $error ): never {
		Events::record(
			Events::ACTION_FAILED,
			'warning',
			[
				'action'  => sanitize_key( $action ),
				'message' => sanitize_text_field( $error->getMessage() ),
			]
		);
		self::redirect( 'error', $error->getMessage() );
	}

	private static function redirect( string $state, string $message = '' ): never {
		$args = [ 'page' => Page::SLUG, 'cb_cm_state' => sanitize_key( $state ) ];
		if ( '' !== $message ) {
			$args['cb_cm_message'] = sanitize_text_field( $message );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}
}
