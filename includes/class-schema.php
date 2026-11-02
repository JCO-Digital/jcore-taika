<?php
/**
 * JSON Schema helpers shared by core and add-on abilities.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builders for the schema shapes every Taika ability uses, so add-ons
 * validate input the same way core does.
 */
final class Schema {

	/**
	 * An object schema that rejects unknown fields.
	 *
	 * The top-level default lets a call with no input reach the callback.
	 *
	 * @param array<string, mixed> $properties Property schemas.
	 * @param string[]             $required   Required property names.
	 *
	 * @return array<string, mixed>
	 */
	public static function object( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);

		if ( array() === $required ) {
			$schema['default'] = array();
		} else {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Schema for a post ID.
	 *
	 * @return array<string, mixed>
	 */
	public static function id(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Post or page ID.', 'jcore-taika' ),
		);
	}
}
