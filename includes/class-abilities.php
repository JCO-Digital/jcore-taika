<?php
/**
 * Registers the plugin's abilities with the WordPress Abilities API.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

use Jcore\Taika\Abilities\Media;
use Jcore\Taika\Abilities\Posts;
use Jcore\Taika\Abilities\Terms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares core's abilities: their schema, kind and callbacks. The registrar
 * adds the guard rails every Taika ability shares.
 *
 * Ability names and schemas are a public contract with the gateway and its
 * users' saved conversations. Add fields; never rename or remove one. A
 * breaking change gets a new ability name.
 */
final class Abilities {

	/**
	 * Ability category, shared with add-ons.
	 */
	public const CATEGORY = Registrar::CATEGORY;

	/**
	 * Hooks registration into the Abilities API's init actions.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Registers the ability category.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Taika', 'jcore-taika' ),
				'description' => __( 'Read, draft and publish posts and pages from AI assistants.', 'jcore-taika' ),
			)
		);
	}

	/**
	 * Registers core's abilities, then lets add-on plugins register theirs.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		$registrar = Registrar::instance();
		$core      = $registrar->module( 'jcore-taika', JCORE_TAIKA_VERSION );

		$core->ability(
			'list-posts',
			array(
				'label'               => __( 'List posts', 'jcore-taika' ),
				'description'         => __( 'Lists posts or pages you can edit, most recently modified first, with ID, title, status and modified time. Use it to find a post ID before reading or editing.', 'jcore-taika' ),
				'input_schema'        => Schema::object(
					array(
						'post_type' => self::post_type_schema(),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'publish', 'future', 'draft', 'pending', 'private' ),
							'default'     => 'any',
							'description' => __( 'Only list posts with this status.', 'jcore-taika' ),
						),
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Search words in the title and content.', 'jcore-taika' ),
						),
						'per_page'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 20,
						),
						'page'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					)
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts'       => array(
							'type'  => 'array',
							'items' => self::post_summary_schema(),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( Posts::class, 'list_posts' ),
				'permission_callback' => array( Posts::class, 'can_list' ),
			),
			Registrar::READ
		);

		$core->ability(
			'get-post',
			array(
				'label'               => __( 'Get post', 'jcore-taika' ),
				'description'         => __( 'Returns one post or page with its full content as block markup, plus a version token. Keep the <!-- wp:... --> block comments when editing, and pass version as expected_version to update-post or publish-post.', 'jcore-taika' ),
				'input_schema'        => Schema::object( array( 'id' => Schema::id() ), array( 'id' ) ),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'get_post' ),
				'permission_callback' => array( Posts::class, 'can_edit' ),
			),
			Registrar::READ
		);

		$core->ability(
			'list-terms',
			array(
				'label'               => __( 'List categories and tags', 'jcore-taika' ),
				'description'         => __( 'Lists categories or tags with their IDs. Use category IDs from here when creating or updating posts.', 'jcore-taika' ),
				'input_schema'        => Schema::object(
					array(
						'taxonomy' => array(
							'type'    => 'string',
							'enum'    => Terms::TAXONOMIES,
							'default' => 'category',
						),
						'search'   => array( 'type' => 'string' ),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 100,
						),
					)
				),
				'execute_callback'    => array( Terms::class, 'list_terms' ),
				'permission_callback' => array( Terms::class, 'can_list' ),
			),
			Registrar::READ
		);

		$core->ability(
			'create-post',
			array(
				'label'               => __( 'Create draft', 'jcore-taika' ),
				'description'         => __( 'Creates a new post or page as a draft, or pending review. It is not visible to visitors until publish-post is used. Write content as block markup. Set slug to choose the URL, and parent to place a page under another page.', 'jcore-taika' ),
				'input_schema'        => Schema::object(
					array(
						'post_type' => self::post_type_schema(),
						'status'    => self::draft_status_schema(),
					) + self::content_fields() + self::location_fields(),
					array( 'title' )
				),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'create_post' ),
				'permission_callback' => array( Posts::class, 'can_create' ),
			),
			Registrar::WRITE
		);

		$core->ability(
			'update-post',
			array(
				'label'               => __( 'Update draft', 'jcore-taika' ),
				'description'         => __( 'Changes a post or page that is not live yet, including its slug and parent page. Only the fields given are changed. Refuses published posts: use publish-post for those. Fetch the post first and pass its version as expected_version.', 'jcore-taika' ),
				'input_schema'        => Schema::object(
					array(
						'id'               => Schema::id(),
						'expected_version' => self::expected_version_schema(),
						'status'           => self::draft_status_schema(),
					) + self::content_fields() + self::location_fields(),
					array( 'id', 'expected_version' )
				),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'update_post' ),
				'permission_callback' => array( Posts::class, 'can_edit' ),
			),
			Registrar::WRITE
		);

		$core->ability(
			'publish-post',
			array(
				'label'               => __( 'Publish post', 'jcore-taika' ),
				'description'         => __( 'Makes a draft visible to visitors, or applies the given changes to a post that is already live. Confirm with the user before calling. Pass the post\'s version as expected_version.', 'jcore-taika' ),
				'input_schema'        => Schema::object(
					array(
						'id'               => Schema::id(),
						'expected_version' => self::expected_version_schema(),
					) + self::content_fields(),
					array( 'id', 'expected_version' )
				),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'publish_post' ),
				'permission_callback' => array( Posts::class, 'can_publish' ),
			),
			Registrar::DESTRUCTIVE
		);

		$core->ability(
			'upload-media',
			array(
				'label'               => __( 'Upload media', 'jcore-taika' ),
				'description'         => __( 'Adds an image or file from a public https URL to the media library and returns its attachment ID and URL.', 'jcore-taika' ),
				'input_schema'        => Schema::object(
					array(
						'url'      => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'Public https:// address of the file.', 'jcore-taika' ),
						),
						'title'    => array( 'type' => 'string' ),
						'alt_text' => array(
							'type'        => 'string',
							'description' => __( 'Alternative text for images.', 'jcore-taika' ),
						),
						'post_id'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Attach the file to this post.', 'jcore-taika' ),
						),
					),
					array( 'url' )
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'url'       => array( 'type' => 'string' ),
						'mime_type' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( Media::class, 'upload_media' ),
				'permission_callback' => array( Media::class, 'can_upload' ),
			),
			Registrar::WRITE
		);

		/**
		 * Fires when add-on plugins should register their abilities.
		 *
		 * Hook it at file scope or on `plugins_loaded`; it runs while the
		 * Abilities API is initialising, the only time abilities can be
		 * registered.
		 *
		 * @since 0.5.0
		 *
		 * @param Registrar $registrar Registers abilities under a module.
		 */
		do_action( 'jcore_taika_register', $registrar );
	}

	/**
	 * Schema for the post type selector.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_type_schema(): array {
		return array(
			'type'    => 'string',
			'enum'    => Posts::POST_TYPES,
			'default' => 'post',
		);
	}

	/**
	 * Schema for the statuses a draft-level write may set.
	 *
	 * @return array<string, mixed>
	 */
	private static function draft_status_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => Posts::DRAFT_STATUSES,
			'default'     => 'draft',
			'description' => __( 'draft, or pending to ask an editor for review.', 'jcore-taika' ),
		);
	}

	/**
	 * Schema for the optimistic-lock token.
	 *
	 * @return array<string, mixed>
	 */
	private static function expected_version_schema(): array {
		return array(
			'type'        => 'string',
			'description' => __( 'The version value from get-post. The write is refused if the post changed since.', 'jcore-taika' ),
		);
	}

	/**
	 * Schemas for the editable content fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function content_fields(): array {
		return array(
			'title'          => array( 'type' => 'string' ),
			'content'        => array(
				'type'        => 'string',
				'description' => __( 'Post content as block markup.', 'jcore-taika' ),
			),
			'excerpt'        => array( 'type' => 'string' ),
			'categories'     => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Category IDs from list-terms. Replaces the current categories. Posts only.', 'jcore-taika' ),
			),
			'tags'           => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Tag names; new ones are created. Replaces the current tags. Posts only.', 'jcore-taika' ),
			),
			'featured_media' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Attachment ID of an image from upload-media to use as the featured image. 0 removes it.', 'jcore-taika' ),
			),
		);
	}

	/**
	 * Schemas for the fields that decide a post's URL.
	 *
	 * Offered only on draft-level writes: changing them on a live post would
	 * break links to it.
	 *
	 * @return array<string, mixed>
	 */
	private static function location_fields(): array {
		return array(
			'slug'   => array(
				'type'        => 'string',
				'description' => __( 'URL slug, e.g. "contact-us". Empty lets WordPress derive it from the title when the post is published. A number is added if another post already uses it.', 'jcore-taika' ),
			),
			'parent' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'ID of the parent page, from list-posts with post_type page. 0 makes it a top-level page. Pages only.', 'jcore-taika' ),
			),
		);
	}

	/**
	 * Output schema for a post in a list.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'type'         => array( 'type' => 'string' ),
				'title'        => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'slug'         => array(
					'type'        => 'string',
					'description' => __( 'Empty on a draft that has none yet.', 'jcore-taika' ),
				),
				'parent'       => array(
					'type'        => 'integer',
					'description' => __( 'ID of the parent page, or 0.', 'jcore-taika' ),
				),
				'author'       => array( 'type' => 'string' ),
				'modified_gmt' => array( 'type' => array( 'string', 'null' ) ),
				'link'         => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Output schema for a full post.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_detail_schema(): array {
		$schema = self::post_summary_schema();

		$schema['properties'] += array(
			'version'        => array( 'type' => 'string' ),
			'content'        => array( 'type' => 'string' ),
			'excerpt'        => array( 'type' => 'string' ),
			'has_blocks'     => array( 'type' => 'boolean' ),
			'featured_media' => array(
				'type'        => 'integer',
				'description' => __( 'Attachment ID of the featured image, or 0 when there is none.', 'jcore-taika' ),
			),
			'categories'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'tags'           => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
		);

		return $schema;
	}
}
