<?php
/**
 * Site ability: what content the assistant can work on here.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika\Abilities;

use WP_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute and permission callbacks for describe-site.
 */
final class Site {

	/**
	 * Permission: may the user edit any of the post types Taika works on?
	 *
	 * @return bool
	 */
	public static function can_describe(): bool {
		return array() !== self::editable_types();
	}

	/**
	 * Describes the site and the post types the user can edit through Taika.
	 *
	 * Add-ons extend each post type's entry with the
	 * `jcore_taika_post_type_description` filter, e.g. with its custom fields.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe_site(): array {
		return array(
			'name'       => (string) get_bloginfo( 'name' ),
			'url'        => home_url(),
			'language'   => (string) get_bloginfo( 'language' ),
			'post_types' => array_map( array( self::class, 'describe_type' ), self::editable_types() ),
		);
	}

	/**
	 * Describes one post type.
	 *
	 * @param WP_Post_Type $type Post type.
	 *
	 * @return array<string, mixed>
	 */
	private static function describe_type( WP_Post_Type $type ): array {
		$description = array(
			'name'         => $type->name,
			'label'        => $type->labels->singular_name,
			'hierarchical' => (bool) $type->hierarchical,
			'supports'     => array(
				'title'          => post_type_supports( $type->name, 'title' ),
				'content'        => post_type_supports( $type->name, 'editor' ),
				'excerpt'        => post_type_supports( $type->name, 'excerpt' ),
				'featured_media' => post_type_supports( $type->name, 'thumbnail' ),
			),
			'taxonomies'   => array_values(
				array_filter(
					Terms::TAXONOMIES,
					static function ( string $taxonomy ) use ( $type ): bool {
						return is_object_in_taxonomy( $type->name, $taxonomy );
					}
				)
			),
			'can_create'   => current_user_can( $type->cap->create_posts ),
			'can_publish'  => current_user_can( $type->cap->publish_posts ),
		);

		/**
		 * Filters how describe-site presents a post type.
		 *
		 * Add-ons add what the assistant needs to know to write this type,
		 * such as its custom fields. Keep keys in snake_case and values
		 * JSON-serialisable.
		 *
		 * @since 0.5.0
		 *
		 * @param array<string, mixed> $description Post type description.
		 * @param WP_Post_Type         $type        Post type.
		 */
		$filtered = apply_filters( 'jcore_taika_post_type_description', $description, $type );

		return is_array( $filtered ) ? $filtered : $description;
	}

	/**
	 * Post types Taika works on that the user can edit.
	 *
	 * @return WP_Post_Type[]
	 */
	private static function editable_types(): array {
		$types = array();

		foreach ( Posts::post_types() as $name ) {
			$type = get_post_type_object( $name );
			if ( null !== $type && current_user_can( $type->cap->edit_posts ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}
}
