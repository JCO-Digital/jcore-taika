<?php
/**
 * Public status route the gateway checks before linking a site.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika\Rest;

use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /jcore-taika/v1/status`: is the plugin here, and can a site be linked?
 */
final class Status_Controller extends Controller {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				// Public: the gateway calls this before the user has linked the
				// site, so there are no credentials yet. It reveals only what the
				// plugin's presence already implies.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Reports the plugin version and whether linking can work.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'plugin'                => 'jcore-taika',
				'version'               => JCORE_TAIKA_VERSION,
				'abilities_api'         => function_exists( 'wp_register_ability' ),
				'application_passwords' => wp_is_application_passwords_available(),
			)
		);
	}
}
