<?php
declare(strict_types=1);

namespace CB\ContentMigrator\Integration;

use CB\ContentMigrator\Admin\Page;
use CB\ContentMigrator\Migration\JobStore;

defined( 'ABSPATH' ) || exit;

final class Suite {
	public const ID = 'core-blueprint-content-migrator';

	public static function init(): void {
		add_action( 'cb_core_register_extensions', [ __CLASS__, 'register_extension' ] );
		add_filter( 'cb_core_module_status_definitions', [ __CLASS__, 'register_status_definition' ] );
	}

	public static function register_extension(): void {
		\CB\Core\ExtensionRegistry::register( [
			'id'            => self::ID,
			'plugin_file'   => CB_CONTENT_MIGRATOR_BASENAME,
			'requires_api'  => CB_CONTENT_MIGRATOR_REQUIRED_API,
			'requires_base' => CB_CONTENT_MIGRATOR_REQUIRED_BASE,
			'menu_url'      => admin_url( 'tools.php?page=' . Page::SLUG ),
			'status_id'     => self::ID,
		] );
	}

	/** @param array<string,array<string,mixed>> $definitions @return array<string,array<string,mixed>> */
	public static function register_status_definition( array $definitions ): array {
		$definitions[ self::ID ] = [
			'provider' => [ __CLASS__, 'status' ],
			'label'    => __( 'Content Migrator', 'core-blueprint-content-migrator' ),
			'url'      => admin_url( 'tools.php?page=' . Page::SLUG ),
		];
		return $definitions;
	}

	/** @return array{state:string,detail:string,url:string} */
	public static function status(): array {
		$job = JobStore::load_active();
		$url = admin_url( 'tools.php?page=' . Page::SLUG );
		if ( ! is_array( $job ) ) {
			return [
				'state'  => 'ok',
				'detail' => __( 'No active migration', 'core-blueprint-content-migrator' ),
				'url'    => $url,
			];
		}

		$status = sanitize_key( (string) ( $job['status'] ?? 'unknown' ) );
		$errors = count( (array) ( $job['errors'] ?? [] ) );
		$state = 'ok';
		if ( 'rollback_failed' === $status ) {
			$state = 'err';
		} elseif ( 'verification_failed' === $status || $errors > 0 ) {
			$state = 'warn';
		}

		$detail = sprintf(
			/* translators: 1: migration status, 2: processed items, 3: total items. */
			__( '%1$s · %2$d/%3$d processed', 'core-blueprint-content-migrator' ),
			$status,
			(int) ( $job['cursor'] ?? 0 ),
			(int) ( $job['total'] ?? 0 )
		);

		return [ 'state' => $state, 'detail' => $detail, 'url' => $url ];
	}
}
