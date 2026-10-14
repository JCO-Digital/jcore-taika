<?php
/**
 * Post abilities: list, read, create, update and publish.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika\Abilities;

use Jcore\Taika\Write_Guard;
use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute and permission callbacks for the post abilities.
 *
 * Every callback runs as the WordPress user behind the request's Application
 * Password, so core capability checks decide what that person may do.
 * Writes never make content public except through `publish_post()`.
 */
final class Posts {

	/**
	 * Post types the abilities work on.
	 */
	public const POST_TYPES = array( 'post', 'page' );

	/**
	 * Statuses a draft-level write may set.
	 */
	public const DRAFT_STATUSES = array( 'draft', 'pending' );

	/**
	 * Statuses whose content visitors can already see, or will at a set time.
	 */
	private const LIVE_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * Statuses listed when the caller asks for `any`.
	 */
	private const LISTED_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Permission for list-posts: may the user edit posts of this type at all?
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return bool
	 */
	public static function can_list( mixed $input = null ): bool {
		$type = get_post_type_object( self::post_type( $input ) );

		return null !== $type && current_user_can( $type->cap->edit_posts );
	}

	/**
	 * Lists posts or pages the user can edit, most recently modified first.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_posts( mixed $input = null ): array {
		$input  = is_array( $input ) ? $input : array();
		$status = isset( $input['status'] ) && is_string( $input['status'] ) ? $input['status'] : 'any';

		$args = array(
			'post_type'           => self::post_type( $input ),
			'post_status'         => 'any' === $status ? self::LISTED_STATUSES : $status,
			'perm'                => 'editable',
			'posts_per_page'      => min( 50, max( 1, (int) ( $input['per_page'] ?? 20 ) ) ),
			'paged'               => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		);

		if ( isset( $input['search'] ) && is_string( $input['search'] ) && '' !== $input['search'] ) {
			$args['s'] = $input['search'];
		}

		$query = new WP_Query( $args );

		return array(
			'posts'       => array_map( array( self::class, 'summary' ), $query->posts ),
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Permission for abilities that act on one existing post.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return bool
	 */
	public static function can_edit( mixed $input = null ): bool {
		$post = self::find( $input );

		// A missing post passes here so the callback can say "not found"
		// instead of "not allowed", which would send the model the wrong way.
		return null === $post || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Returns one post with its full content.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_post( mixed $input = null ): array|WP_Error {
		$post = self::find( $input );
		if ( ! $post instanceof WP_Post ) {
			return self::not_found();
		}

		return self::detail( $post );
	}

	/**
	 * Permission for create-post.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return bool
	 */
	public static function can_create( mixed $input = null ): bool {
		$type = get_post_type_object( self::post_type( $input ) );

		return null !== $type && current_user_can( $type->cap->create_posts );
	}

	/**
	 * Creates a draft or pending post.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_post( mixed $input = null ): array|WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$type    = self::post_type( $input );
		$content = isset( $input['content'] ) && is_string( $input['content'] ) ? $input['content'] : '';

		$error = Write_Guard::check_content( $content ) ?? self::check_terms( $type, $input );
		if ( null !== $error ) {
			return $error;
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $type,
					'post_status'  => self::draft_status( $input ),
					'post_title'   => (string) ( $input['title'] ?? '' ),
					'post_content' => $content,
					'post_excerpt' => (string) ( $input['excerpt'] ?? '' ),
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::assign_terms( $post_id, $input );
		Write_Guard::mark( $post_id );

		return self::detail( get_post( $post_id ) );
	}

	/**
	 * Updates a post that is not live yet.
	 *
	 * Published, scheduled and private posts are refused, because changing them
	 * changes what visitors see. That goes through `publish_post()` instead.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_post( mixed $input = null ): array|WP_Error {
		$input = is_array( $input ) ? $input : array();
		$post  = self::find( $input );
		if ( ! $post instanceof WP_Post ) {
			return self::not_found();
		}

		if ( in_array( $post->post_status, self::LIVE_STATUSES, true ) ) {
			return new WP_Error(
				'jcore_taika_post_is_live',
				__( 'This post is already visible to visitors. Use publish-post to change it, so the change is confirmed first.', 'jcore-taika' ),
				array( 'status' => 409 )
			);
		}

		$error = self::check_write( $post, $input );
		if ( null !== $error ) {
			return $error;
		}

		$changes = self::content_changes( $input );
		if ( isset( $input['status'] ) ) {
			$changes['post_status'] = self::draft_status( $input );
		}

		return self::save( $post, $changes, $input );
	}

	/**
	 * Permission for publish-post: edit this post and publish its type.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return bool
	 */
	public static function can_publish( mixed $input = null ): bool {
		$post = self::find( $input );
		if ( null === $post ) {
			return true;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		$type = get_post_type_object( $post->post_type );

		return null !== $type && current_user_can( $type->cap->publish_posts );
	}

	/**
	 * Publishes a post, or applies changes to one that is already live.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish_post( mixed $input = null ): array|WP_Error {
		$input = is_array( $input ) ? $input : array();
		$post  = self::find( $input );
		if ( ! $post instanceof WP_Post ) {
			return self::not_found();
		}

		$error = self::check_write( $post, $input );
		if ( null !== $error ) {
			return $error;
		}

		$changes = self::content_changes( $input );

		if ( in_array( $post->post_status, self::LIVE_STATUSES, true ) ) {
			if ( array() === $changes && ! isset( $input['categories'] ) && ! isset( $input['tags'] ) ) {
				return new WP_Error(
					'jcore_taika_nothing_to_publish',
					__( 'The post is already live and no changes were given.', 'jcore-taika' ),
					array( 'status' => 400 )
				);
			}
		} else {
			// A post dated in the future becomes scheduled rather than published.
			$changes['post_status'] = 'publish';
		}

		return self::save( $post, $changes, $input );
	}

	/**
	 * Runs the checks every write to an existing post shares.
	 *
	 * @param WP_Post              $post  Post about to be written.
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return WP_Error|null
	 */
	private static function check_write( WP_Post $post, array $input ): ?WP_Error {
		$error = Write_Guard::check_version( $post, $input['expected_version'] ?? null )
			?? Write_Guard::check_lock( $post )
			?? self::check_terms( $post->post_type, $input );

		if ( null === $error && isset( $input['content'] ) && is_string( $input['content'] ) ) {
			$error = Write_Guard::check_content( $input['content'], $post );
		}

		return $error;
	}

	/**
	 * Saves changes to an existing post, then its terms and the request marker.
	 *
	 * @param WP_Post              $post    Post to update.
	 * @param array<string, mixed> $changes `wp_update_post()` fields, without the ID.
	 * @param array<string, mixed> $input   Ability input, for terms.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function save( WP_Post $post, array $changes, array $input ): array|WP_Error {
		if ( array() !== $changes ) {
			$changes['ID'] = $post->ID;

			$result = wp_update_post( wp_slash( $changes ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		self::assign_terms( $post->ID, $input );
		Write_Guard::mark( $post->ID );

		return self::detail( get_post( $post->ID ) );
	}

	/**
	 * Maps the title, content and excerpt inputs to post fields.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return array<string, string>
	 */
	private static function content_changes( array $input ): array {
		$fields  = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		);
		$changes = array();

		foreach ( $fields as $key => $field ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				$changes[ $field ] = $input[ $key ];
			}
		}

		return $changes;
	}

	/**
	 * Refuses terms on a type without them, or that the user may not assign.
	 *
	 * @param string               $post_type Post type being written.
	 * @param array<string, mixed> $input     Ability input.
	 *
	 * @return WP_Error|null
	 */
	private static function check_terms( string $post_type, array $input ): ?WP_Error {
		$taxonomies = array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		);

		foreach ( $taxonomies as $key => $taxonomy ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$object = get_taxonomy( $taxonomy );
			if ( false === $object || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return new WP_Error(
					'jcore_taika_terms_not_supported',
					/* translators: %s: input field name, categories or tags. */
					sprintf( __( 'This post type does not use %s.', 'jcore-taika' ), $key ),
					array( 'status' => 400 )
				);
			}

			if ( ! current_user_can( $object->cap->assign_terms ) ) {
				return new WP_Error(
					'jcore_taika_forbidden_terms',
					/* translators: %s: input field name, categories or tags. */
					sprintf( __( 'You are not allowed to set %s.', 'jcore-taika' ), $key ),
					array( 'status' => 403 )
				);
			}
		}

		return null;
	}

	/**
	 * Sets categories (by ID) and tags (by name) when the input has them.
	 *
	 * Tags are names so the model can add a new one; categories are IDs from
	 * list-terms so it cannot invent categories.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $input   Ability input, already checked by `check_terms()`.
	 *
	 * @return void
	 */
	private static function assign_terms( int $post_id, array $input ): void {
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'absint', $input['categories'] ), 'category' );
		}

		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', $input['tags'] ), 'post_tag' );
		}
	}

	/**
	 * Finds the post named by `id`, limited to the supported post types.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return WP_Post|null
	 */
	private static function find( mixed $input ): ?WP_Post {
		if ( ! is_array( $input ) || ! isset( $input['id'] ) ) {
			return null;
		}

		// Read-only abilities are called over REST with GET, so the ID arrives
		// as a query-string value: "42", not 42.
		$id = filter_var( $input['id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id ) {
			return null;
		}

		$post = get_post( $id );

		return $post instanceof WP_Post && in_array( $post->post_type, self::POST_TYPES, true ) ? $post : null;
	}

	/**
	 * Reads the requested post type, falling back to `post`.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return string
	 */
	private static function post_type( mixed $input ): string {
		$type = is_array( $input ) ? ( $input['post_type'] ?? 'post' ) : 'post';

		return in_array( $type, self::POST_TYPES, true ) ? $type : 'post';
	}

	/**
	 * Reads the requested draft status, falling back to `draft`.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return string
	 */
	private static function draft_status( array $input ): string {
		$status = $input['status'] ?? 'draft';

		return in_array( $status, self::DRAFT_STATUSES, true ) ? $status : 'draft';
	}

	/**
	 * The error returned for a missing post or an unsupported post type.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error(
			'jcore_taika_post_not_found',
			__( 'No post or page with that ID. Use list-posts to find it.', 'jcore-taika' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * The short form of a post used in lists.
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary( WP_Post $post ): array {
		$author = get_userdata( (int) $post->post_author );

		return array(
			'id'           => $post->ID,
			'type'         => $post->post_type,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'author'       => $author ? $author->display_name : '',
			// WordPress leaves this at zero on a new draft until its first update.
			'modified_gmt' => '0000-00-00 00:00:00' === $post->post_modified_gmt ? null : $post->post_modified_gmt,
			'link'         => (string) get_permalink( $post ),
		);
	}

	/**
	 * The full form of a post, returned by reads and writes.
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return array<string, mixed>
	 */
	private static function detail( WP_Post $post ): array {
		$detail = self::summary( $post ) + array(
			'version'    => Write_Guard::version( $post ),
			'content'    => $post->post_content,
			'excerpt'    => $post->post_excerpt,
			'has_blocks' => has_blocks( $post->post_content ),
		);

		if ( is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			$detail['categories'] = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'ids' ) );
		}

		if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
			$detail['tags'] = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		}

		return $detail;
	}
}
