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
 * Declares every ability: its schema, annotations and callbacks.
 *
 * Ability names and schemas are a public contract with the gateway and its
 * users' saved conversations. Add fields; never rename or remove one. A
 * breaking change gets a new ability name.
 */
final class Abilities {

	/**
	 * Ability category and name prefix.
	 */
	public const CATEGORY = 'jcore-taika';

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
	 * Registers every ability.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		self::register(
			'list-posts',
			array(
				'label'               => __( 'List posts', 'jcore-taika' ),
				'description'         => __( 'Lists posts or pages you can edit, most recently modified first, with ID, title, status and modified time. Use it to find a post ID before reading or editing.', 'jcore-taika' ),
				'input_schema'        => self::object_schema(
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
			self::read_only()
		);

		self::register(
			'get-post',
			array(
				'label'               => __( 'Get post', 'jcore-taika' ),
				'description'         => __( 'Returns one post or page with its full content as block markup, plus a version token. Keep the <!-- wp:... --> block comments when editing, and pass version as expected_version to update-post or publish-post.', 'jcore-taika' ),
				'input_schema'        => self::object_schema( array( 'id' => self::id_schema() ), array( 'id' ) ),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'get_post' ),
				'permission_callback' => array( Posts::class, 'can_edit' ),
			),
			self::read_only()
		);

		self::register(
			'list-terms',
			array(
				'label'               => __( 'List categories and tags', 'jcore-taika' ),
				'description'         => __( 'Lists categories or tags with their IDs. Use category IDs from here when creating or updating posts.', 'jcore-taika' ),
				'input_schema'        => self::object_schema(
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
			self::read_only()
		);

		self::register(
			'create-post',
			array(
				'label'               => __( 'Create draft', 'jcore-taika' ),
				'description'         => __( 'Creates a new post or page as a draft, or pending review. It is not visible to visitors until publish-post is used. Write content as block markup.', 'jcore-taika' ),
				'input_schema'        => self::object_schema(
					array(
						'post_type' => self::post_type_schema(),
						'status'    => self::draft_status_schema(),
					) + self::content_fields(),
					array( 'title' )
				),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'create_post' ),
				'permission_callback' => array( Posts::class, 'can_create' ),
			),
			self::write( false )
		);

		self::register(
			'update-post',
			array(
				'label'               => __( 'Update draft', 'jcore-taika' ),
				'description'         => __( 'Changes a post or page that is not live yet. Only the fields given are changed. Refuses published posts: use publish-post for those. Fetch the post first and pass its version as expected_version.', 'jcore-taika' ),
				'input_schema'        => self::object_schema(
					array(
						'id'               => self::id_schema(),
						'expected_version' => self::expected_version_schema(),
						'status'           => self::draft_status_schema(),
					) + self::content_fields(),
					array( 'id', 'expected_version' )
				),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'update_post' ),
				'permission_callback' => array( Posts::class, 'can_edit' ),
			),
			self::write( false )
		);

		self::register(
			'publish-post',
			array(
				'label'               => __( 'Publish post', 'jcore-taika' ),
				'description'         => __( 'Makes a draft visible to visitors, or applies the given changes to a post that is already live. Confirm with the user before calling. Pass the post\'s version as expected_version.', 'jcore-taika' ),
				'input_schema'        => self::object_schema(
					array(
						'id'               => self::id_schema(),
						'expected_version' => self::expected_version_schema(),
					) + self::content_fields(),
					array( 'id', 'expected_version' )
				),
				'output_schema'       => self::post_detail_schema(),
				'execute_callback'    => array( Posts::class, 'publish_post' ),
				'permission_callback' => array( Posts::class, 'can_publish' ),
			),
			self::write( true )
		);

		self::register(
			'upload-media',
			array(
				'label'               => __( 'Upload media', 'jcore-taika' ),
				'description'         => __( 'Adds an image or file from a public https URL to the media library and returns its attachment ID and URL.', 'jcore-taika' ),
				'input_schema'        => self::object_schema(
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
			self::write( false )
		);
	}

	/**
	 * Registers one ability under the plugin's prefix and category.
	 *
	 * @param string               $name        Ability name without the prefix.
	 * @param array<string, mixed> $args        `wp_register_ability()` arguments.
	 * @param array<string, bool>  $annotations Behaviour annotations.
	 *
	 * @return void
	 */
	private static function register( string $name, array $args, array $annotations ): void {
		$args['category'] = self::CATEGORY;
		$args['meta']     = array(
			'show_in_rest' => true,
			'annotations'  => $annotations,
			// Not offered to the MCP Adapter: Taika's tools are reached only through
			// the gateway at mcp.bojaco.com, which checks invitations and plans, rate
			// limits and audits every call. A site that also runs the adapter would
			// otherwise expose them to anyone with a login, bypassing all of that.
			'mcp'          => array(
				'public' => false,
			),
		);

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}

	/**
	 * Annotations for an ability that changes nothing.
	 *
	 * @return array<string, bool>
	 */
	private static function read_only(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Annotations for an ability that writes.
	 *
	 * @param bool $destructive Whether visitors can see the result.
	 *
	 * @return array<string, bool>
	 */
	private static function write( bool $destructive ): array {
		return array(
			'readonly'    => false,
			'destructive' => $destructive,
			'idempotent'  => false,
		);
	}

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
	private static function object_schema( array $properties, array $required = array() ): array {
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
	private static function id_schema(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Post or page ID.', 'jcore-taika' ),
		);
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
