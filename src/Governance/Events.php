<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Governance;

defined( 'ABSPATH' ) || exit;

final class Events {
	public const ANALYZED        = 'contentmigrator.analysis.completed';
	public const CREATED         = 'contentmigrator.job.created';
	public const BATCH           = 'contentmigrator.job.batchcopied';
	public const VERIFIED        = 'contentmigrator.job.verified';
	public const ROLLEDBACK      = 'contentmigrator.job.rolledback';
	public const ROLLBACK_FAILED = 'contentmigrator.rollback.failed';
	public const FINALIZED       = 'contentmigrator.job.finalized';
	public const FINALIZE_FAILED = 'contentmigrator.finalize.failed';
	public const TRASHED         = 'contentmigrator.source.trashed';
	public const TAKEN_OVER      = 'contentmigrator.job.takenover';
	public const ACTION_FAILED   = 'contentmigrator.action.failed';
	public const PLAN_CLEARED    = 'contentmigrator.plan.cleared';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 10 );
	}

	public static function register(): void {
		$labels = [
			self::ANALYZED        => __( 'Content migration analysis completed', 'core-blueprint-content-migrator' ),
			self::CREATED         => __( 'Content migration created', 'core-blueprint-content-migrator' ),
			self::BATCH           => __( 'Content migration batch copied', 'core-blueprint-content-migrator' ),
			self::VERIFIED        => __( 'Content migration verified', 'core-blueprint-content-migrator' ),
			self::ROLLEDBACK      => __( 'Content migration rolled back', 'core-blueprint-content-migrator' ),
			self::ROLLBACK_FAILED => __( 'Content migration rollback failed', 'core-blueprint-content-migrator' ),
			self::FINALIZED       => __( 'Content migration finalized', 'core-blueprint-content-migrator' ),
			self::FINALIZE_FAILED => __( 'Content migration finalization failed', 'core-blueprint-content-migrator' ),
			self::TRASHED         => __( 'Content migration source moved to Trash', 'core-blueprint-content-migrator' ),
			self::TAKEN_OVER      => __( 'Content migration ownership taken over', 'core-blueprint-content-migrator' ),
			self::ACTION_FAILED   => __( 'Content migration action failed', 'core-blueprint-content-migrator' ),
			self::PLAN_CLEARED    => __( 'Content migration plan cleared', 'core-blueprint-content-migrator' ),
		];
		foreach ( $labels as $id => $label ) {
			\CB\Core\Governance\EventRegistry::register( [
				'id'                 => $id,
				'label'              => $label,
				'retention_category' => 'maintenance',
			] );
		}
	}

	/** @param array<string,mixed> $context */
	public static function record( string $event, string $severity, array $context ): bool {
		return \CB\Core\Governance\Audit::record( $event, $severity, $context );
	}
}
