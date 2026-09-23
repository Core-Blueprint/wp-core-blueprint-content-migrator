<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Migration;

defined( 'ABSPATH' ) || exit;

final class PostAnalyzer {
	/** @return array<string,\WP_Post_Type> */
	public static function post_types(): array {
		$objects = get_post_types( [ 'show_ui' => true ], 'objects' );
		unset( $objects['attachment'] );
		ksort( $objects );
		return $objects;
	}

	/** @return array<string,mixed> */
	public static function analyze( string $source_type, string $target_type ): array {
		$source_type = sanitize_key( $source_type );
		$target_type = sanitize_key( $target_type );
		if ( $source_type === $target_type ) {
			throw new \InvalidArgumentException( __( 'Source and target post types must be different.', 'core-blueprint-content-migrator' ) );
		}
		$source = get_post_type_object( $source_type );
		$target = get_post_type_object( $target_type );
		if ( ! $source instanceof \WP_Post_Type || ! $target instanceof \WP_Post_Type ) {
			throw new \InvalidArgumentException( __( 'Source or target post type is not registered.', 'core-blueprint-content-migrator' ) );
		}
		if ( ! current_user_can( $source->cap->edit_posts ) || ! current_user_can( $target->cap->create_posts ) ) {
			throw new \RuntimeException( __( 'You do not have sufficient capabilities for the selected post types.', 'core-blueprint-content-migrator' ) );
		}

		$statuses = array_values( get_post_stati( [ 'internal' => false ], 'names' ) );
		$source_ids = get_posts( [
			'post_type'              => $source_type,
			'post_status'            => $statuses,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );
		$source_ids = array_values( array_map( 'intval', $source_ids ) );
		if ( empty( $source_ids ) ) {
			throw new \RuntimeException( __( 'The selected source post type has no migratable posts.', 'core-blueprint-content-migrator' ) );
		}

		return [
			'source_type'       => $source_type,
			'target_type'       => $target_type,
			'source_label'      => (string) $source->labels->name,
			'target_label'      => (string) $target->labels->name,
			'source_ids'        => $source_ids,
			'total'             => count( $source_ids ),
			'source_taxonomies' => self::taxonomies( $source_type, false ),
			'target_taxonomies' => self::taxonomies( $target_type, true ),
			'source_meta_keys'  => self::source_meta_keys( $source_type ),
			'target_meta_keys'  => array_keys( get_registered_meta_keys( 'post', $target_type ) ),
			'target_hierarchical' => (bool) $target->hierarchical,
		];
	}

	/** @return array<string,array{label:string,hierarchical:bool,public:bool}> */
	private static function taxonomies( string $post_type, bool $target ): array {
		$out = [];
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy ) {
				continue;
			}
			if ( $target && ( 'do_not_allow' === (string) $taxonomy->cap->assign_terms || ! current_user_can( $taxonomy->cap->assign_terms ) ) ) {
				continue;
			}
			$out[ $name ] = [
				'label'        => (string) $taxonomy->labels->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'public'       => (bool) $taxonomy->public,
			];
		}
		ksort( $out );
		return $out;
	}

	/** @return string[] */
	private static function source_meta_keys( string $post_type ): array {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s ORDER BY pm.meta_key ASC",
			$post_type
		);
		$keys = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$blocked = [ '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' ];
		$out = [];
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( '' === $key || in_array( $key, $blocked, true ) || str_starts_with( $key, '_cb_content_migrator_' ) ) {
				continue;
			}
			$out[] = $key;
		}
		return $out;
	}
}
