<?php
/**
 * Plugin Name:       Core Blueprint Content Migrator
 * Plugin URI:        https://github.com/christiaanbruinsma/wp-core-blueprint-content-migrator
 * Description:       Safely migrate WordPress posts and taxonomies with explicit mapping, verification and rollback.
 * Version:           1.0.0-rc1
 * Author:            Core Blueprint
 * Author URI:        https://coreblueprint.io
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       core-blueprint-content-migrator
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.4
 *
 * @package CB_Content_Migrator
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'CB_CONTENT_MIGRATOR_VERSION', '1.0.0-rc1' );
define( 'CB_CONTENT_MIGRATOR_REQUIRED_API', '1.0' );
define( 'CB_CONTENT_MIGRATOR_FILE', __FILE__ );
define( 'CB_CONTENT_MIGRATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_CONTENT_MIGRATOR_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\ContentMigrator\\';
	if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file = CB_CONTENT_MIGRATOR_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

add_action( 'init', static function (): void {
	load_plugin_textdomain(
		'core-blueprint-content-migrator',
		false,
		dirname( CB_CONTENT_MIGRATOR_BASENAME ) . '/languages'
	);
}, 1 );

/** Whether a compatible Core Blueprint Base public API is available. */
function cb_content_migrator_base_ready(): bool {
	if ( ! defined( 'CB_CORE_API_VERSION' ) || 1 !== preg_match( '/^(\\d+)\\.(\\d+)$/', (string) CB_CORE_API_VERSION, $available ) ) {
		return false;
	}
	if ( 1 !== preg_match( '/^(\\d+)\\.(\\d+)$/', CB_CONTENT_MIGRATOR_REQUIRED_API, $required ) ) {
		return false;
	}
	return (int) $available[1] === (int) $required[1]
		&& (int) $available[2] >= (int) $required[2]
		&& class_exists( '\\CB\\Core\\ExtensionRegistry' );
}

add_action( 'plugins_loaded', static function (): void {
	\CB\ContentMigrator\Plugin::boot();
}, 30 );
