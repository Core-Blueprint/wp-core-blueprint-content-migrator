<?php
/**
 * Plugin Name:       Core Blueprint Content Migrator
 * Plugin URI:        https://github.com/Core-Blueprint/wp-core-blueprint-content-migrator
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
 * Requires Plugins: core-blueprint
 *
 * @package CB_Content_Migrator
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'CB_CONTENT_MIGRATOR_VERSION', '1.0.0-rc1' );
define( 'CB_CONTENT_MIGRATOR_REQUIRED_API', '1.1' );
define( 'CB_CONTENT_MIGRATOR_REQUIRED_BASE', '1.0.0-rc1' );
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

/** Whether the required Core Blueprint Base runtime and public API are available. */
function cb_content_migrator_base_ready(): bool {
	if ( ! defined( 'CB_CORE_VERSION' ) || version_compare( (string) CB_CORE_VERSION, CB_CONTENT_MIGRATOR_REQUIRED_BASE, '<' ) ) {
		return false;
	}
	if ( ! defined( 'CB_CORE_API_VERSION' ) || 1 !== preg_match( '/^(\\d+)\\.(\\d+)$/', (string) CB_CORE_API_VERSION, $available ) ) {
		return false;
	}
	if ( 1 !== preg_match( '/^(\\d+)\\.(\\d+)$/', CB_CONTENT_MIGRATOR_REQUIRED_API, $required ) ) {
		return false;
	}
	return (int) $available[1] === (int) $required[1]
		&& (int) $available[2] >= (int) $required[2]
		&& class_exists( '\\CB\\Core\\ExtensionRegistry' )
		&& class_exists( '\\CB\\Core\\Governance\\EventRegistry' )
		&& class_exists( '\\CB\\Core\\Governance\\Audit' );
}

add_action( 'plugins_loaded', static function (): void {
	if ( ! cb_content_migrator_base_ready() ) {
		add_action( 'admin_notices', static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			$message = sprintf(
				/* translators: 1: minimum Core Blueprint Base version, 2: required Core API version. */
				__( 'Core Blueprint Content Migrator requires Core Blueprint %1$s or newer with compatible Core API %2$s.', 'core-blueprint-content-migrator' ),
				CB_CONTENT_MIGRATOR_REQUIRED_BASE,
				CB_CONTENT_MIGRATOR_REQUIRED_API
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		} );
		return;
	}
	\CB\ContentMigrator\Plugin::boot();
}, 30 );
