<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Migration;

defined( 'ABSPATH' ) || exit;

final class TaxonomyRunner {
	private const JOB_META    = '_cb_content_migrator_job';
	private const SOURCE_META = '_cb_content_migrator_source_term_id';

	/** @param array<string,mixed> $job @return array<string,mixed> */
	public static function run_batch( array $job ): array {
		$term_ids = array_values( array_map( 'intval', (array) ( $job['source_ids'] ?? [] ) ) );
		$relationship_ids = array_values( array_map( 'intval', (array) ( $job['relationship_ids'] ?? [] ) ) );
		$batch_size = max( 10, min( 200, (int) ( $job['batch_size'] ?? 50 ) ) );
		$job['term_map'] = is_array( $job['term_map'] ?? null ) ? $job['term_map'] : [];
		$job['created_target_ids'] = array_values( array_map( 'intval', (array) ( $job['created_target_ids'] ?? [] ) ) );
		$job['added_relationships'] = is_array( $job['added_relationships'] ?? null ) ? $job['added_relationships'] : [];
		$job['errors'] = is_array( $job['errors'] ?? null ) ? $job['errors'] : [];
		$job['term_cursor'] = max( 0, (int) ( $job['term_cursor'] ?? 0 ) );
		$job['relationship_cursor'] = max( 0, (int) ( $job['relationship_cursor'] ?? 0 ) );

		if ( $job['term_cursor'] < count( $term_ids ) ) {
			$job['status'] = 'copying_terms';
			$end = min( count( $term_ids ), $job['term_cursor'] + $batch_size );
			for ( $i = $job['term_cursor']; $i < $end; $i++ ) {
				$source_id = $term_ids[ $i ];
				try {
					self::ensure_term( $source_id, $job );
				} catch ( \Throwable $e ) {
					self::add_error( $job, $source_id, 'term', $e->getMessage() );
				}
			}
			$job['term_cursor'] = $end;
			self::sync_progress( $job );
			if ( $end < count( $term_ids ) ) {
				return $job;
			}
		}

		if ( ! empty( $job['copy_relationships'] ) && $job['relationship_cursor'] < count( $relationship_ids ) ) {
			$job['status'] = 'copying_relationships';
			$end = min( count( $relationship_ids ), $job['relationship_cursor'] + $batch_size );
			for ( $i = $job['relationship_cursor']; $i < $end; $i++ ) {
				$object_id = $relationship_ids[ $i ];
				try {
					self::copy_relationships_for_object( $object_id, $job );
				} catch ( \Throwable $e ) {
					self::add_error( $job, $object_id, 'relationship', $e->getMessage() );
				}
			}
			$job['relationship_cursor'] = $end;
			self::sync_progress( $job );
			if ( $end < count( $relationship_ids ) ) {
				return $job;
			}
		}

		$job['status'] = 'copied';
		self::sync_progress( $job );
		return $job;
	}

	/** @param array<string,mixed> $job */
	private static function ensure_term( int $source_id, array &$job ): int {
		$key = (string) $source_id;
		if ( isset( $job['term_map'][ $key ] ) ) {
			return (int) $job['term_map'][ $key ];
		}

		$source_tax = sanitize_key( (string) ( $job['source_taxonomy'] ?? '' ) );
		$target_tax = sanitize_key( (string) ( $job['target_taxonomy'] ?? '' ) );
		$source_taxonomy = get_taxonomy( $source_tax );
		$target_taxonomy = get_taxonomy( $target_tax );
		if (
			! $source_taxonomy instanceof \WP_Taxonomy
			|| 'do_not_allow' === (string) $source_taxonomy->cap->manage_terms
			|| ! current_user_can( $source_taxonomy->cap->manage_terms )
		) {
			throw new \RuntimeException( __( 'You no longer have permission to manage the source taxonomy.', 'core-blueprint-content-migrator' ) );
		}
		if (
			! $target_taxonomy instanceof \WP_Taxonomy
			|| 'do_not_allow' === (string) $target_taxonomy->cap->manage_terms
			|| ! current_user_can( $target_taxonomy->cap->manage_terms )
		) {
			throw new \RuntimeException( __( 'You no longer have permission to manage the target taxonomy.', 'core-blueprint-content-migrator' ) );
		}

		$source = get_term( $source_id, $source_tax );
		if ( ! $source instanceof \WP_Term ) {
			throw new \RuntimeException( __( 'Source term is unavailable.', 'core-blueprint-content-migrator' ) );
		}

		$existing = get_term_by( 'slug', $source->slug, $target_tax );
		if ( $existing instanceof \WP_Term ) {
			$job['term_map'][ $key ] = (int) $existing->term_id;
			return (int) $existing->term_id;
		}

		$parent = 0;
		if ( $source->parent > 0 && $target_taxonomy->hierarchical ) {
			$parent = self::ensure_term( (int) $source->parent, $job );
		}

		$inserted = wp_insert_term(
			$source->name,
			$target_tax,
			[
				'slug'        => $source->slug,
				'description' => $source->description,
				'parent'      => $parent,
			]
		);
		if ( is_wp_error( $inserted ) ) {
			if ( 'term_exists' === $inserted->get_error_code() ) {
				$target_id = (int) $inserted->get_error_data();
				$job['term_map'][ $key ] = $target_id;
				return $target_id;
			}
			throw new \RuntimeException( $inserted->get_error_message() );
		}

		$target_id = (int) $inserted['term_id'];
		$job_marker = update_term_meta( $target_id, self::JOB_META, sanitize_key( (string) $job['id'] ) );
		$source_marker = update_term_meta( $target_id, self::SOURCE_META, $source_id );
		if ( false === $job_marker || false === $source_marker ) {
			wp_delete_term( $target_id, $target_tax );
			throw new \RuntimeException( __( 'The target term could not be marked for safe rollback and was removed.', 'core-blueprint-content-migrator' ) );
		}

		// Track ownership before meta copying can fail.
		$job['term_map'][ $key ] = $target_id;
		$job['created_target_ids'][] = $target_id;
		$job['created_target_ids'] = array_values( array_unique( array_map( 'intval', $job['created_target_ids'] ) ) );

		self::copy_term_meta( $source_id, $target_id, $target_tax, (array) ( $job['term_meta_map'] ?? [] ) );
		return $target_id;
	}

	/** @param array<string,string> $meta_map */
	private static function copy_term_meta( int $source_id, int $target_id, string $target_taxonomy, array $meta_map ): void {
		foreach ( $meta_map as $source_key => $target_key ) {
			$source_key = (string) $source_key;
			$target_key = self::sanitize_meta_key( (string) $target_key );
			if ( '' === $source_key || '' === $target_key ) {
				continue;
			}
			if (
				str_starts_with( $target_key, '_cb_content_migrator_' )
				|| ! current_user_can( 'edit_term_meta', $target_id, $target_key )
			) {
				throw new \RuntimeException( __( 'A mapped target term meta key is reserved or not writable.', 'core-blueprint-content-migrator' ) );
			}
			$values = get_term_meta( $source_id, $source_key, false );
			if ( empty( $values ) ) {
				continue;
			}
			delete_term_meta( $target_id, $target_key );
			foreach ( $values as $value ) {
				add_term_meta( $target_id, $target_key, sanitize_meta( $target_key, $value, 'term', $target_taxonomy ) );
			}
		}
	}

	/** @param array<string,mixed> $job */
	private static function copy_relationships_for_object( int $object_id, array &$job ): void {
		$source_tax = sanitize_key( (string) $job['source_taxonomy'] );
		$target_tax = sanitize_key( (string) $job['target_taxonomy'] );
		$target_taxonomy = get_taxonomy( $target_tax );
		if (
			! $target_taxonomy instanceof \WP_Taxonomy
			|| 'do_not_allow' === (string) $target_taxonomy->cap->assign_terms
			|| ! current_user_can( $target_taxonomy->cap->assign_terms )
			|| ! current_user_can( 'edit_post', $object_id )
		) {
			throw new \RuntimeException( __( 'You no longer have permission to change one or more target taxonomy relationships.', 'core-blueprint-content-migrator' ) );
		}

		$source_term_ids = wp_get_object_terms( $object_id, $source_tax, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $source_term_ids ) ) {
			throw new \RuntimeException( $source_term_ids->get_error_message() );
		}
		$target_ids = [];
		foreach ( array_map( 'intval', $source_term_ids ) as $source_term_id ) {
			$target_id = (int) ( $job['term_map'][ (string) $source_term_id ] ?? 0 );
			if ( $target_id > 0 ) {
				$target_ids[] = $target_id;
			}
		}
		$target_ids = array_values( array_unique( $target_ids ) );
		if ( empty( $target_ids ) ) {
			return;
		}

		$current = wp_get_object_terms( $object_id, $target_tax, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $current ) ) {
			throw new \RuntimeException( $current->get_error_message() );
		}
		$current = array_map( 'intval', $current );
		$added = array_values( array_diff( $target_ids, $current ) );
		if ( empty( $added ) ) {
			return;
		}

		$result = wp_set_object_terms( $object_id, $target_ids, $target_tax, true );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}
		$job['added_relationships'][ (string) $object_id ] = array_values(
			array_unique(
				array_merge(
					array_map( 'intval', (array) ( $job['added_relationships'][ (string) $object_id ] ?? [] ) ),
					$added
				)
			)
		);
	}

	/** @param array<string,mixed> $job @return array{passed:bool,checked:int,issues:array<int,string>} */
	public static function verify( array $job ): array {
		$issues = [];
		$checked = 0;
		$error_count = count( (array) ( $job['errors'] ?? [] ) );
		if ( $error_count > 0 ) {
			$issues[] = sprintf(
				/* translators: %d: number of copy errors. */
				_n(
					'The copy phase recorded %d issue. Roll back and retry before finalizing.',
					'The copy phase recorded %d issues. Roll back and retry before finalizing.',
					$error_count,
					'core-blueprint-content-migrator'
				),
				$error_count
			);
		}

		$source_tax = sanitize_key( (string) $job['source_taxonomy'] );
		$target_tax = sanitize_key( (string) $job['target_taxonomy'] );
		foreach ( (array) ( $job['term_map'] ?? [] ) as $source_id => $target_id ) {
			$source = get_term( (int) $source_id, $source_tax );
			$target = get_term( (int) $target_id, $target_tax );
			$checked++;
			if ( ! $source instanceof \WP_Term || ! $target instanceof \WP_Term ) {
				$issues[] = sprintf(
					/* translators: 1: source term ID, 2: target term ID. */
					__( 'Source term %1$d or target term %2$d no longer exists.', 'core-blueprint-content-migrator' ),
					(int) $source_id,
					(int) $target_id
				);
				continue;
			}
			if ( $source->slug !== $target->slug ) {
				$issues[] = sprintf(
					/* translators: %d: target term ID. */
					__( 'Target term %d has a different slug.', 'core-blueprint-content-migrator' ),
					(int) $target_id
				);
			}

			$created_by_job = sanitize_key( (string) get_term_meta( (int) $target_id, self::JOB_META, true ) ) === sanitize_key( (string) $job['id'] );
			if ( $created_by_job ) {
				if ( (int) get_term_meta( (int) $target_id, self::SOURCE_META, true ) !== (int) $source_id ) {
					$issues[] = sprintf(
						/* translators: %d: target term ID. */
						__( 'Created target term %d has an invalid source marker.', 'core-blueprint-content-migrator' ),
						(int) $target_id
					);
				}
				if ( $source->name !== $target->name || $source->description !== $target->description ) {
					$issues[] = sprintf(
						/* translators: %d: target term ID. */
						__( 'Created target term %d differs in name or description.', 'core-blueprint-content-migrator' ),
						(int) $target_id
					);
				}
				if ( ! empty( $job['target_hierarchical'] ) && $source->parent > 0 ) {
					$expected_parent = (int) ( $job['term_map'][ (string) $source->parent ] ?? 0 );
					if ( $expected_parent !== (int) $target->parent ) {
						$issues[] = sprintf(
							/* translators: %d: target term ID. */
							__( 'Created target term %d has an unexpected parent.', 'core-blueprint-content-migrator' ),
							(int) $target_id
						);
					}
				}
				self::verify_term_meta( $source, $target, $target_tax, (array) ( $job['term_meta_map'] ?? [] ), $issues );
			}
			if ( count( $issues ) >= 100 ) {
				break;
			}
		}

		if ( ! empty( $job['copy_relationships'] ) && count( $issues ) < 100 ) {
			foreach ( array_map( 'intval', (array) ( $job['relationship_ids'] ?? [] ) ) as $object_id ) {
				$source_ids = wp_get_object_terms( $object_id, $source_tax, [ 'fields' => 'ids' ] );
				$target_ids = wp_get_object_terms( $object_id, $target_tax, [ 'fields' => 'ids' ] );
				if ( is_wp_error( $source_ids ) || is_wp_error( $target_ids ) ) {
					$issues[] = sprintf(
						/* translators: %d: object ID. */
						__( 'Relationship verification failed for object %d.', 'core-blueprint-content-migrator' ),
						$object_id
					);
					continue;
				}
				$expected = [];
				foreach ( array_map( 'intval', $source_ids ) as $source_id ) {
					$mapped = (int) ( $job['term_map'][ (string) $source_id ] ?? 0 );
					if ( $mapped > 0 ) {
						$expected[] = $mapped;
					}
				}
				if ( ! empty( array_diff( array_unique( $expected ), array_map( 'intval', $target_ids ) ) ) ) {
					$issues[] = sprintf(
						/* translators: %d: object ID. */
						__( 'Object %d is missing one or more migrated target relationships.', 'core-blueprint-content-migrator' ),
						$object_id
					);
				}
				if ( count( $issues ) >= 100 ) {
					break;
				}
			}
		}

		return [
			'passed'  => empty( $issues ) && $checked === count( (array) ( $job['source_ids'] ?? [] ) ),
			'checked' => $checked,
			'issues'  => array_slice( $issues, 0, 100 ),
		];
	}

	/** @param array<string,string> $meta_map @param string[] $issues */
	private static function verify_term_meta( \WP_Term $source, \WP_Term $target, string $target_taxonomy, array $meta_map, array &$issues ): void {
		foreach ( $meta_map as $source_key => $target_key ) {
			$target_key = self::sanitize_meta_key( (string) $target_key );
			if ( '' === $target_key ) {
				continue;
			}
			$expected = array_map(
				static fn( mixed $value ): mixed => sanitize_meta( $target_key, $value, 'term', $target_taxonomy ),
				get_term_meta( $source->term_id, (string) $source_key, false )
			);
			$actual = get_term_meta( $target->term_id, $target_key, false );
			if ( $expected !== $actual ) {
				$issues[] = sprintf(
					/* translators: 1: target term ID, 2: meta key. */
					__( 'Target term %1$d differs for mapped term meta %2$s.', 'core-blueprint-content-migrator' ),
					$target->term_id,
					$target_key
				);
			}
		}
	}

	/** @param array<string,mixed> $job @return array{deleted:int,relationships_removed:int,issues:array<int,string>} */
	public static function rollback( array $job ): array {
		$issues = [];
		$relationships_removed = 0;
		$target_tax = sanitize_key( (string) $job['target_taxonomy'] );
		$target_taxonomy = get_taxonomy( $target_tax );
		$can_assign = $target_taxonomy instanceof \WP_Taxonomy
			&& 'do_not_allow' !== (string) $target_taxonomy->cap->assign_terms
			&& current_user_can( $target_taxonomy->cap->assign_terms );
		$can_manage = $target_taxonomy instanceof \WP_Taxonomy
			&& 'do_not_allow' !== (string) $target_taxonomy->cap->manage_terms
			&& current_user_can( $target_taxonomy->cap->manage_terms );

		foreach ( (array) ( $job['added_relationships'] ?? [] ) as $object_id => $target_ids ) {
			$ids = array_values( array_unique( array_map( 'intval', (array) $target_ids ) ) );
			if ( empty( $ids ) ) {
				continue;
			}
			if ( ! $can_assign || ! current_user_can( 'edit_post', (int) $object_id ) ) {
				$issues[] = sprintf(
					/* translators: %d: object ID. */
					__( 'Migrated relationships on object %d were not removed because you no longer have permission.', 'core-blueprint-content-migrator' ),
					(int) $object_id
				);
				continue;
			}
			$result = wp_remove_object_terms( (int) $object_id, $ids, $target_tax );
			if ( is_wp_error( $result ) ) {
				$issues[] = sprintf(
					/* translators: %d: object ID. */
					__( 'Could not remove migrated relationships from object %d.', 'core-blueprint-content-migrator' ),
					(int) $object_id
				);
			} else {
				$relationships_removed += count( $ids );
			}
		}

		$created = array_values( array_unique( array_map( 'intval', (array) ( $job['created_target_ids'] ?? [] ) ) ) );
		usort( $created, static fn( int $a, int $b ): int => self::term_depth( $b, $target_tax ) <=> self::term_depth( $a, $target_tax ) );
		$deleted = 0;
		foreach ( $created as $target_id ) {
			$term = get_term( $target_id, $target_tax );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( ! $can_manage ) {
				$issues[] = sprintf(
					/* translators: %d: target term ID. */
					__( 'Target term %d was not deleted because you no longer have permission.', 'core-blueprint-content-migrator' ),
					$target_id
				);
				continue;
			}
			if ( sanitize_key( (string) get_term_meta( $target_id, self::JOB_META, true ) ) !== sanitize_key( (string) $job['id'] ) ) {
				$issues[] = sprintf(
					/* translators: %d: target term ID. */
					__( 'Target term %d was not deleted because its rollback marker changed.', 'core-blueprint-content-migrator' ),
					$target_id
				);
				continue;
			}
			$blocker = TermRollbackGuard::blocker( $target_id, $target_tax );
			if ( '' !== $blocker ) {
				$issues[] = $blocker;
				continue;
			}
			$result = wp_delete_term( $target_id, $target_tax );
			if ( is_wp_error( $result ) || false === $result || null === $result ) {
				$issues[] = sprintf(
					/* translators: %d: target term ID. */
					__( 'Target term %d could not be deleted.', 'core-blueprint-content-migrator' ),
					$target_id
				);
				continue;
			}
			$deleted++;
		}

		return [ 'deleted' => $deleted, 'relationships_removed' => $relationships_removed, 'issues' => $issues ];
	}

	/** @param array<string,mixed> $job @return array{trashed:int,issues:array<int,string>} */
	public static function finalize( array $job, bool $trash_source = false ): array {
		unset( $trash_source );
		$issues = [];
		$job_id = sanitize_key( (string) ( $job['id'] ?? '' ) );
		$target_tax = sanitize_key( (string) ( $job['target_taxonomy'] ?? '' ) );

		foreach ( array_map( 'intval', (array) ( $job['created_target_ids'] ?? [] ) ) as $target_id ) {
			if ( ! get_term( $target_id, $target_tax ) ) {
				$issues[] = sprintf(
					/* translators: %d: target term ID. */
					__( 'Target term %d is missing. Verify the migration again before finalizing.', 'core-blueprint-content-migrator' ),
					$target_id
				);
				continue;
			}
			if ( sanitize_key( (string) get_term_meta( $target_id, self::JOB_META, true ) ) !== $job_id ) {
				$issues[] = sprintf(
					/* translators: %d: target term ID. */
					__( 'Target term %d no longer has the expected migration marker.', 'core-blueprint-content-migrator' ),
					$target_id
				);
			}
		}
		if ( ! empty( $issues ) ) {
			return [ 'trashed' => 0, 'issues' => $issues ];
		}

		foreach ( array_map( 'intval', (array) ( $job['created_target_ids'] ?? [] ) ) as $target_id ) {
			delete_term_meta( $target_id, self::JOB_META );
			delete_term_meta( $target_id, self::SOURCE_META );
		}
		return [ 'trashed' => 0, 'issues' => [] ];
	}

	private static function term_depth( int $term_id, string $taxonomy ): int {
		$depth = 0;
		$seen = [];
		while ( $term_id > 0 && ! isset( $seen[ $term_id ] ) ) {
			$seen[ $term_id ] = true;
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term || $term->parent <= 0 ) {
				break;
			}
			$depth++;
			$term_id = (int) $term->parent;
		}
		return $depth;
	}

	/** @param array<string,mixed> $job */
	private static function sync_progress( array &$job ): void {
		$job['cursor'] = (int) ( $job['term_cursor'] ?? 0 ) + (int) ( $job['relationship_cursor'] ?? 0 );
		$job['total'] = count( (array) ( $job['source_ids'] ?? [] ) )
			+ ( ! empty( $job['copy_relationships'] ) ? count( (array) ( $job['relationship_ids'] ?? [] ) ) : 0 );
	}

	private static function sanitize_meta_key( string $key ): string {
		$key = trim( $key );
		return preg_match( '/^[A-Za-z0-9_-]{1,191}$/', $key ) ? $key : '';
	}

	/** @param array<string,mixed> $job */
	private static function add_error( array &$job, int $source_id, string $stage, string $message ): void {
		if ( count( (array) $job['errors'] ) >= 100 ) {
			return;
		}
		$job['errors'][] = [
			'source_id' => $source_id,
			'stage'     => sanitize_key( $stage ),
			'message'   => sanitize_text_field( $message ),
		];
	}
}
