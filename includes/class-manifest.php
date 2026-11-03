<?php
/**
 * The gateway's tool manifest for one module.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds `taika-tools.json`: the MCP tools a module offers through the
 * gateway, generated from what the Registrar actually registered.
 *
 * The shared release workflow (`.github/workflows/taika-manifest.yml`) runs
 * `wp jcore-taika manifest <module>` in Playground and attaches the result to
 * the module's GitHub release, where the gateway reads it. Nothing here is
 * served by the site itself.
 */
final class Manifest {

	/**
	 * Format version of the manifest. Bump only for a breaking change.
	 */
	public const FORMAT = 1;

	/**
	 * The manifest for one module, or null when the module isn't registered.
	 *
	 * Field descriptions that name other abilities (`from list-terms`) are
	 * rewritten to the tool names the model sees (`from list_terms`).
	 *
	 * @param string $module Module slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function for_module( string $module ): ?array {
		$registrar = Registrar::instance();
		$modules   = $registrar->modules();
		if ( ! isset( $modules[ $module ] ) ) {
			return null;
		}

		$all   = $registrar->tools();
		$names = self::tool_names( $all );
		$tools = array();

		foreach ( $all as $id => $tool ) {
			$ability = wp_get_ability( $id );
			if ( $module !== $tool['module'] || null === $ability ) {
				continue;
			}

			$tools[] = array(
				'name'         => $tool['name'],
				'ability'      => $id,
				'kind'         => $tool['kind'],
				'title'        => $ability->get_label(),
				'description'  => $tool['description'],
				'schema'       => $tool['schema'],
				'input_schema' => self::schema( $ability->get_input_schema(), $names ),
			);
		}

		return array(
			'format'  => self::FORMAT,
			'module'  => $module,
			'version' => $modules[ $module ],
			'tools'   => $tools,
		);
	}

	/**
	 * Ability name (without the module) => tool name, for names that are unique.
	 *
	 * @param array<string, array{name: string}> $tools Full ability ID => tool.
	 *
	 * @return array<string, string>
	 */
	private static function tool_names( array $tools ): array {
		$names = array();
		$seen  = array();
		foreach ( $tools as $id => $tool ) {
			$short = substr( $id, (int) strpos( $id, '/' ) + 1 );
			if ( isset( $seen[ $short ] ) ) {
				unset( $names[ $short ] );
				continue;
			}
			$seen[ $short ]  = true;
			$names[ $short ] = $tool['name'];
		}

		return $names;
	}

	/**
	 * A JSON Schema ready for the manifest.
	 *
	 * Empty `properties` become JSON objects rather than `[]`, ability names
	 * in descriptions become tool names, and enums marked with
	 * `Registrar::SITE_ENUM_KEYWORD` are left out: they hold the values of the
	 * site the manifest was generated on, and the gateway serves every site.
	 *
	 * @param mixed                 $schema Schema (or part of one).
	 * @param array<string, string> $names  Ability name => tool name.
	 *
	 * @return mixed
	 */
	private static function schema( mixed $schema, array $names ): mixed {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		if ( ! empty( $schema[ Registrar::SITE_ENUM_KEYWORD ] ) ) {
			unset( $schema['enum'], $schema[ Registrar::SITE_ENUM_KEYWORD ] );
		}

		foreach ( $schema as $key => $value ) {
			if ( 'properties' === $key && is_array( $value ) ) {
				$properties = new stdClass();
				foreach ( $value as $field => $property ) {
					$properties->{$field} = self::schema( $property, $names );
				}
				$schema[ $key ] = $properties;
			} elseif ( 'description' === $key && is_string( $value ) ) {
				$schema[ $key ] = self::rename( $value, $names );
			} elseif ( is_array( $value ) ) {
				$schema[ $key ] = self::schema( $value, $names );
			}
		}

		return $schema;
	}

	/**
	 * Replaces whole-word ability names with tool names.
	 *
	 * @param string                $text  Description.
	 * @param array<string, string> $names Ability name => tool name.
	 *
	 * @return string
	 */
	private static function rename( string $text, array $names ): string {
		foreach ( $names as $short => $tool ) {
			$text = (string) preg_replace( '/(?<![a-z0-9_-])' . preg_quote( $short, '/' ) . '(?![a-z0-9_-])/', $tool, $text );
		}

		return $text;
	}
}
