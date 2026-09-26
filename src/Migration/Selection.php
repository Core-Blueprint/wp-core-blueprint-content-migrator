<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Migration;

defined( 'ABSPATH' ) || exit;

final class Selection {
	/**
	 * Validate a submitted subset of analyzed post IDs.
	 *
	 * @param int[] $allowed_ids
	 * @return int[]
	 */
	public static function posts( array $allowed_ids, mixed $raw ): array {
		$allowed = self::normalize_allowed( $allowed_ids );
		$selected = self::normalize_submitted(
			$allowed,
			$raw,
			__( 'Select at least one source post to migrate.', 'core-blueprint-content-migrator' ),
			__( 'One or more selected source posts are not part of the analyzed migration plan.', 'core-blueprint-content-migrator' )
		);

		return array_values(
			array_filter(
				$allowed,
				static fn( int $id ): bool => isset( $selected[ $id ] )
			)
		);
	}


	/**
	 * Validate a submitted subset of analyzed taxonomy term IDs.
	 * Required ancestors are included when hierarchy must be preserved.
	 *
	 * @param int[] $allowed_ids
	 * @return int[]
	 */
	public static function terms( array $allowed_ids, mixed $raw, string $source_taxonomy, bool $include_ancestors ): array {
		$allowed = self::normalize_allowed( $allowed_ids );
		$selected = self::normalize_submitted(
			$allowed,
			$raw,
			__( 'Select at least one source term to migrate.', 'core-blueprint-content-migrator' ),
			__( 'One or more selected source terms are not part of the analyzed migration plan.', 'core-blueprint-content-migrator' )
		);

		if ( $include_ancestors ) {
			$allowed_set = array_fill_keys( $allowed, true );
			$taxonomy = get_taxonomy( $source_taxonomy );
			if ( ! $taxonomy instanceof \WP_Taxonomy ) {
				throw new \RuntimeException( __( 'The analyzed source taxonomy is no longer available.', 'core-blueprint-content-migrator' ) );
			}

			foreach ( array_keys( $selected ) as $source_id ) {
				$term = get_term( (int) $source_id, $source_taxonomy );
				$seen = [];
				while ( $term instanceof \WP_Term && $term->parent > 0 ) {
					$parent_id = (int) $term->parent;
					if ( isset( $seen[ $parent_id ] ) ) {
						throw new \RuntimeException( __( 'A source term hierarchy loop was detected.', 'core-blueprint-content-migrator' ) );
					}
					$seen[ $parent_id ] = true;
					if ( ! isset( $allowed_set[ $parent_id ] ) ) {
						throw new \RuntimeException( __( 'A required parent term is no longer part of the analyzed migration plan.', 'core-blueprint-content-migrator' ) );
					}
					$selected[ $parent_id ] = true;
					$term = get_term( $parent_id, $source_taxonomy );
				}
			}
		}

		return array_values(
			array_filter(
				$allowed,
				static fn( int $id ): bool => isset( $selected[ $id ] )
			)
		);
	}

	/**
	 * Limit analyzed relationship objects to those affected by the explicit term selection.
	 *
	 * @param int[] $allowed_relationship_ids
	 * @param int[] $source_ids
	 * @return int[]
	 */
	public static function relationships( array $allowed_relationship_ids, array $source_ids, string $source_taxonomy ): array {
		$allowed = self::normalize_allowed( $allowed_relationship_ids );
		if ( empty( $allowed ) || empty( $source_ids ) ) {
			return [];
		}

		$objects = get_objects_in_term( array_values( array_map( 'intval', $source_ids ) ), $source_taxonomy );
		if ( is_wp_error( $objects ) ) {
			throw new \RuntimeException( $objects->get_error_message() );
		}

		$affected = array_fill_keys( array_map( 'intval', $objects ), true );
		return array_values(
			array_filter(
				$allowed,
				static fn( int $id ): bool => isset( $affected[ $id ] )
			)
		);
	}

	/** @param int[] $ids @return int[] */
	private static function normalize_allowed( array $ids ): array {
		$out = [];
		$seen = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * @param int[] $allowed
	 * @return array<int,true>
	 */
	private static function normalize_submitted( array $allowed, mixed $raw, string $empty_message, string $invalid_message ): array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			throw new \RuntimeException( $empty_message );
		}

		$allowed_set = array_fill_keys( $allowed, true );
		$selected = [];
		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				throw new \InvalidArgumentException( $invalid_message );
			}
			$value = (string) $value;
			if ( '' === $value || ! ctype_digit( $value ) ) {
				throw new \InvalidArgumentException( $invalid_message );
			}
			$id = (int) $value;
			if ( $id <= 0 || ! isset( $allowed_set[ $id ] ) ) {
				throw new \InvalidArgumentException( $invalid_message );
			}
			$selected[ $id ] = true;
		}

		if ( empty( $selected ) ) {
			throw new \RuntimeException( $empty_message );
		}

		return $selected;
	}
}
