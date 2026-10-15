<?php
/**
 * Plugin Name:       JCORE Taika
 * Plugin URI:        https://github.com/JCO-Digital/jcore-taika
 * Description:       Lets invited users read, draft and publish posts from AI assistants through the bojaco MCP gateway.
 * Version:           0.2.1
 * Requires at least: 6.9
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            J&Co Digital Oy
 * Author URI:        https://jco.fi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jcore-taika
 * Domain Path:       /languages
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// foonver rewrites this along with the header on every release.
define( 'JCORE_TAIKA_VERSION', '0.2.1' );
define( 'JCORE_TAIKA_FILE', __FILE__ );
define( 'JCORE_TAIKA_PATH', plugin_dir_path( __FILE__ ) );
define( 'JCORE_TAIKA_URL', plugin_dir_url( __FILE__ ) );

// The update library is vendored into the release, but a source checkout has
// no vendor directory until `composer install` has run.
if ( is_readable( JCORE_TAIKA_PATH . 'vendor/autoload.php' ) ) {
	require_once JCORE_TAIKA_PATH . 'vendor/autoload.php';
}

/**
 * Autoloads classes from the Jcore\Taika namespace.
 *
 * Maps `Jcore\Taika\Foo\Bar_Baz` to `includes/foo/class-bar-baz.php`, the file
 * naming the WordPress coding standards ask for.
 *
 * @param string $class_name Fully qualified class name.
 *
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$parts = array_map(
			static function ( string $part ): string {
				return strtolower( str_replace( '_', '-', $part ) );
			},
			$parts
		);

		$parts[ array_key_last( $parts ) ] = 'class-' . end( $parts );

		$file = JCORE_TAIKA_PATH . 'includes/' . implode( '/', $parts ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);

// Registered at file scope, not in Plugin::boot(): other JCORE components read
// this list while plugins are still loading.
add_filter(
	'jcore_plugins_loaded',
	static function ( array $plugins ): array {
		$plugins['jcore-taika'] = __DIR__;

		return $plugins;
	}
);
