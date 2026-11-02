<?php
/**
 * One plugin's handle for registering its abilities.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

use WP_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returned by `Registrar::module()`; registers abilities under the module's
 * slug, so `$module->ability( 'get-fields', … )` on jcore-taika-acf becomes
 * `jcore-taika-acf/get-fields`.
 */
final class Module {

	/**
	 * Creates the handle. Use `Registrar::module()` instead.
	 *
	 * @param Registrar $registrar Registrar that records the abilities.
	 * @param string    $slug      Module slug and ability namespace.
	 * @param bool      $valid     False when the registrar refused the module.
	 */
	public function __construct(
		private readonly Registrar $registrar,
		public readonly string $slug,
		private readonly bool $valid
	) {}

	/**
	 * Registers one ability.
	 *
	 * The registrar sets the category, REST visibility, MCP Adapter opt-out and
	 * annotations; `$args` carries the rest of `wp_register_ability()`'s
	 * arguments: label, description, input and output schema, callbacks.
	 *
	 * Bump `$schema` whenever the input schema gains a field, so the gateway
	 * offers that field only to sites that accept it. Ability names and
	 * schemas are a public contract: add fields, never rename or remove one.
	 * A breaking change gets a new ability name.
	 *
	 * @param string               $name   Ability name without the module prefix, e.g. get-fields.
	 * @param array<string, mixed> $args   `wp_register_ability()` arguments.
	 * @param string               $kind   Registrar::READ, Registrar::WRITE or Registrar::DESTRUCTIVE.
	 * @param int                  $schema Schema version of the input, starting at 1.
	 *
	 * @return WP_Ability|null The ability, or null when it was refused.
	 */
	public function ability( string $name, array $args, string $kind, int $schema = 1 ): ?WP_Ability {
		if ( ! $this->valid ) {
			return null;
		}

		return $this->registrar->register( $this->slug, $name, $args, $kind, $schema );
	}
}
