<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Migration;

defined( 'ABSPATH' ) || exit;

final class TaxonomyAnalyzer {
	/** @return array<string,\WP_Taxonomy> */
	public static function taxonomies( bool $target = false ): array {
		$objects = get_taxonomies( [ 'show_ui' => true ], 'objects' );
		$out = [];
		foreach ( $objects as $name => $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy ) {
				continue;
			}
			if ( $target && ( 'do_not_allow' === (string) $taxonomy->cap->manage_terms || ! current_user_can( $taxonomy->cap->manage_terms ) ) ) {
				continue;
			}
			$out[ $name ] = $taxonomy;
		}
		ksort( $out );
		return $out;
	}

	/** @return array<string,mixed> */
	public static function analyze( string $source_taxonomy, string $target_taxonomy ): array {
		$source_taxonomy = sanitize_key( $source_taxonomy );
		$target_taxonomy = sanitize_key( $target_taxonomy );
		if ( $source_taxonomy === $target_taxonomy ) {
			throw new \InvalidArgumentException( __( 'Source and target taxonomies must be different.', 'core-blueprint-content-migrator' ) );
		}
		$source = get_taxonomy( $source_taxonomy );
		$target = get_taxonomy( $target_taxonomy );
		if ( ! $source instanceof \WP_Taxonomy || ! $target instanceof \WP_Taxonomy ) {
			throw new \InvalidArgumentException( __( 'Source or target taxonomy is not registered.', 'core-blueprint-content-migrator' ) );
		}
		if ( 'do_not_allow' === (string) $source->cap->manage_terms || ! current_user_can( $source->cap->manage_terms ) ) {
			throw new \RuntimeException( __( 'You cannot manage terms in the selected source taxonomy.', 'core-blueprint-content-migrator' ) );
		}
		if ( 'do_not_allow' === (string) $target->cap->manage_terms || ! current_user_can( $target->cap->manage_terms ) ) {
			throw new \RuntimeException( __( 'You cannot create or manage terms in the selected target taxonomy.', 'core-blueprint-content-migrator' ) );
		}

		$terms = get_terms( [
			'taxonomy'   => $source_taxonomy,
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		] );
		if ( is_wp_error( $terms ) ) {
			throw new \RuntimeException( $terms->get_error_message() );
		}
		$source_ids = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$source_ids[] = (int) $term->term_id;
			}
		}
		if ( empty( $source_ids ) ) {
			throw new \RuntimeException( __( 'The selected source taxonomy has no terms to migrate.', 'core-blueprint-content-migrator' ) );
		}

		$source_object_types = array_values( array_filter( array_map( 'sanitize_key', (array) $source->object_type ), 'post_type_exists' ) );
		$target_object_types = array_values( array_filter( array_map( 'sanitize_key', (array) $target->object_type ), 'post_type_exists' ) );
		$shared_object_types = array_values( array_intersect( $source_object_types, $target_object_types ) );
		$relationships_supported = 'do_not_allow' !== (string) $target->cap->assign_terms && current_user_can( $target->cap->assign_terms );
		$relationship_ids = [];
		if ( $relationships_supported && ! empty( $shared_object_types ) ) {
			$objects = get_objects_in_term( $source_ids, $source_taxonomy );
			if ( ! is_wp_error( $objects ) ) {
				foreach ( array_map( 'intval', $objects ) as $object_id ) {
					if ( in_array( (string) get_post_type( $object_id ), $shared_object_types, true ) ) {
						$relationship_ids[] = $object_id;
					}
				}
			}
		}
		$relationship_ids = array_values( array_unique( $relationship_ids ) );
		sort( $relationship_ids );

		return [
			'mode'                  => 'taxonomy',
			'source_taxonomy'       => $source_taxonomy,
			'target_taxonomy'       => $target_taxonomy,
			'source_label'          => (string) $source->labels->name,
			'target_label'          => (string) $target->labels->name,
			'source_ids'            => $source_ids,
			'total'                 => count( $source_ids ),
			'source_hierarchical'   => (bool) $source->hierarchical,
			'target_hierarchical'   => (bool) $target->hierarchical,
			'source_meta_keys'      => self::source_meta_keys( $source_taxonomy ),
			'target_meta_keys'      => array_keys( get_registered_meta_keys( 'term', $target_taxonomy ) ),
			'shared_object_types'   => $shared_object_types,
			'relationship_ids'      => $relationship_ids,
			'relationship_total'    => count( $relationship_ids ),
			'relationships_supported'=> $relationships_supported,
		];
	}

	/** @return string[] */
	private static function source_meta_keys( string $taxonomy ): array {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT DISTINCT tm.meta_key FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = %s ORDER BY tm.meta_key ASC",
			$taxonomy
		);
		$keys = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$out = [];
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( '' === $key || str_starts_with( $key, '_cb_content_migrator_' ) ) {
				continue;
			}
			$out[] = $key;
		}
		return $out;
	}
}
