<?php
/**
 * Plugin bootstrap.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

use Jcore\Update\Config\UpdateConfig;
use Jcore\Update\Hooks\PluginUpdateHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every component to its hooks.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook. Called once on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->register_updater();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// The Abilities API is in core from WordPress 6.9.
		if ( function_exists( 'wp_register_ability' ) ) {
			Abilities::init();
		}
	}

	/**
	 * Registers the plugin's REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new Rest\Status_Controller() )->register_routes();
	}

	/**
	 * Hooks the plugin into the J&Co Digital update service.
	 *
	 * The library is vendored into the release; a source checkout without a
	 * `composer install` simply runs without update checks.
	 *
	 * @return void
	 */
	private function register_updater(): void {
		if ( ! class_exists( UpdateConfig::class ) ) {
			return;
		}

		$config = new UpdateConfig(
			pluginFile: JCORE_TAIKA_FILE,
			slug: 'jcore-taika',
			version: JCORE_TAIKA_VERSION,
			apiBaseUrl: 'https://update.jcore.fi/v1',
		);

		( new PluginUpdateHooks( $config ) )->register();
	}

	/**
	 * Loads the PHP translations shipped in languages/.
	 *
	 * Just-in-time loading only looks in wp-content/languages, which these
	 * plugins never ship to, so the bundled files have to be loaded explicitly.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Not hosted on WordPress.org; translations ship in this plugin's languages/ folder.
		load_plugin_textdomain( 'jcore-taika', false, dirname( plugin_basename( JCORE_TAIKA_FILE ) ) . '/languages' );
	}
}
