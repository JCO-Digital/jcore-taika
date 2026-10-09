<?php
/**
 * Term ability: list categories and tags.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika\Abilities;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute and permission callbacks for list-terms.
 */
final class Terms {

	/**
	 * Taxonomies the ability lists.
	 */
	public const TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * Permission: may the user assign terms of this taxonomy?
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return bool
	 */
	public static function can_list( mixed $input = null ): bool {
		$taxonomy = get_taxonomy( self::taxonomy( $input ) );

		return false !== $taxonomy && current_user_can( $taxonomy->cap->assign_terms );
	}

	/**
	 * Lists terms by name, including empty ones.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_terms( mixed $input = null ): array {
		$input = is_array( $input ) ? $input : array();
		$args  = array(
			'taxonomy'   => self::taxonomy( $input ),
			'hide_empty' => false,
			'number'     => min( 200, max( 1, (int) ( $input['per_page'] ?? 100 ) ) ),
			'orderby'    => 'name',
		);

		if ( isset( $input['search'] ) && is_string( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = $input['search'];
		}

		$terms = get_terms( $args );
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		return array(
			'terms' => array_values(
				array_map(
					static function ( WP_Term $term ): array {
						return array(
							'id'     => $term->term_id,
							'name'   => $term->name,
							'slug'   => $term->slug,
							'parent' => $term->parent,
							'count'  => $term->count,
						);
					},
					$terms
				)
			),
		);
	}

	/**
	 * Reads the requested taxonomy, falling back to `category`.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return string
	 */
	private static function taxonomy( mixed $input ): string {
		$taxonomy = is_array( $input ) ? ( $input['taxonomy'] ?? 'category' ) : 'category';

		return in_array( $taxonomy, self::TAXONOMIES, true ) ? $taxonomy : 'category';
	}
}
