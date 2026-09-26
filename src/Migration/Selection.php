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

	/** @param int[] $ids @return int[] */
	private static function normalize_allowed( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
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
			if ( $id <= 0 || ! in_array( $id, $allowed, true ) ) {
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
