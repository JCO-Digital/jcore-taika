<?php
/**
 * WP-CLI: print a module's gateway tool manifest.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika\Cli;

use Jcore\Taika\Manifest;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp jcore-taika manifest <module>`
 */
final class Manifest_Command {

	/**
	 * Prints the gateway tool manifest (taika-tools.json) of a Taika module.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module slug, e.g. jcore-taika or jcore-taika-acf.
	 *
	 * [--output=<file>]
	 * : Write to this file instead of standard output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jcore-taika manifest jcore-taika-acf --output=taika-tools.json
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$module   = (string) ( $args[0] ?? '' );
		$manifest = Manifest::for_module( $module );

		if ( null === $manifest ) {
			WP_CLI::error( sprintf( 'No Taika module named %s is registered. Is the plugin active?', $module ) );
		}

		if ( array() === $manifest['tools'] ) {
			WP_CLI::warning( sprintf( '%s registers no abilities with tool metadata.', $module ) );
		}

		$json = (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

		if ( isset( $assoc_args['output'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes a local file the caller named.
			if ( false === file_put_contents( $assoc_args['output'], $json ) ) {
				WP_CLI::error( sprintf( 'Could not write %s.', $assoc_args['output'] ) );
			}
			WP_CLI::success( sprintf( 'Wrote %d tools of %s to %s.', count( $manifest['tools'] ), $module, $assoc_args['output'] ) );

			return;
		}

		WP_CLI::line( rtrim( $json ) );
	}
}
