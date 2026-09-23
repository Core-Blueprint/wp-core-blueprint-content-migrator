<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Migration;

defined( 'ABSPATH' ) || exit;

final class TermRollbackGuard {
	public static function blocker( int $term_id, string $taxonomy ): string {
		$objects = get_objects_in_term( $term_id, $taxonomy );
		if ( is_wp_error( $objects ) ) {
			return sprintf(
				/* translators: %d: target term ID. */
				__( 'Target term %d was not deleted because its relationships could not be verified.', 'core-blueprint-content-migrator' ),
				$term_id
			);
		}
		if ( ! empty( $objects ) ) {
			return sprintf(
				/* translators: %d: target term ID. */
				__( 'Target term %d was not deleted because it is now used by content outside the rollback.', 'core-blueprint-content-migrator' ),
				$term_id
			);
		}

		$children = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'parent'     => $term_id,
			'fields'     => 'ids',
			'number'     => 1,
		] );
		if ( is_wp_error( $children ) ) {
			return sprintf(
				/* translators: %d: target term ID. */
				__( 'Target term %d was not deleted because its child terms could not be verified.', 'core-blueprint-content-migrator' ),
				$term_id
			);
		}
		if ( ! empty( $children ) ) {
			return sprintf(
				/* translators: %d: target term ID. */
				__( 'Target term %d was not deleted because it now has child terms outside the rollback.', 'core-blueprint-content-migrator' ),
				$term_id
			);
		}

		return '';
	}
}
