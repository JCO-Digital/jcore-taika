<?php
/**
 * Safety checks shared by every ability that writes content.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses writes that would overwrite someone's work or break block markup,
 * and tags written posts with the gateway's request ID.
 *
 * These checks live in the plugin rather than the gateway so they hold for
 * every client, including one that talks to the site directly.
 */
final class Write_Guard {

	/**
	 * Hidden post meta holding the request ID of the last write through Taika.
	 */
	public const REQUEST_ID_META = '_jcore_taika_request_id';

	/**
	 * Header the gateway sends with every call, read as a `$_SERVER` key.
	 */
	private const REQUEST_ID_HEADER = 'HTTP_X_TAIKA_REQUEST_ID';

	/**
	 * The block delimiter pattern WordPress core's block parser uses.
	 *
	 * Copied from `WP_Block_Parser::next_token()` so the balance check agrees
	 * with how the editor will read the content.
	 */
	private const BLOCK_DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Refuses the write when the post changed after the caller last read it.
	 *
	 * @param WP_Post $post     Post about to be written.
	 * @param mixed   $expected The `modified_gmt` value the caller read.
	 *
	 * @return WP_Error|null
	 */
	public static function check_modified( WP_Post $post, mixed $expected ): ?WP_Error {
		if ( ! is_string( $expected ) || '' === $expected ) {
			return new WP_Error(
				'jcore_taika_missing_expected_modified',
				__( 'Pass expected_modified with the modified_gmt value from get-post, so changes made by others are not overwritten.', 'jcore-taika' ),
				array( 'status' => 400 )
			);
		}

		if ( $post->post_modified_gmt !== $expected ) {
			return new WP_Error(
				'jcore_taika_post_changed',
				__( 'The post changed after it was read. Fetch it again, reapply the changes and retry.', 'jcore-taika' ),
				array(
					'status'       => 409,
					'modified_gmt' => $post->post_modified_gmt,
				)
			);
		}

		return null;
	}

	/**
	 * Refuses the write while someone else has the post open in the editor.
	 *
	 * @param WP_Post $post Post about to be written.
	 *
	 * @return WP_Error|null
	 */
	public static function check_lock( WP_Post $post ): ?WP_Error {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$locked_by = wp_check_post_lock( $post->ID );
		if ( false === $locked_by ) {
			return null;
		}

		$user = get_userdata( $locked_by );

		return new WP_Error(
			'jcore_taika_post_locked',
			sprintf(
				/* translators: %s: display name of the user editing the post. */
				__( '%s is editing this post right now. Try again when they have finished.', 'jcore-taika' ),
				$user ? $user->display_name : __( 'Another user', 'jcore-taika' )
			),
			array( 'status' => 423 )
		);
	}

	/**
	 * Refuses content that would drop or break the post's block markup.
	 *
	 * @param string       $content  Content about to be saved.
	 * @param WP_Post|null $existing The post being updated, or null for a new post.
	 *
	 * @return WP_Error|null
	 */
	public static function check_content( string $content, ?WP_Post $existing = null ): ?WP_Error {
		if (
			null !== $existing
			&& has_blocks( $existing->post_content )
			&& '' !== trim( $content )
			&& ! has_blocks( $content )
		) {
			return new WP_Error(
				'jcore_taika_blocks_removed',
				__( 'The post uses blocks but the new content has no block markup. Keep the <!-- wp:... --> comments when editing.', 'jcore-taika' ),
				array( 'status' => 400 )
			);
		}

		$problem = self::find_block_problem( $content );
		if ( null !== $problem ) {
			return new WP_Error(
				'jcore_taika_invalid_blocks',
				sprintf(
					/* translators: %s: description of the markup problem. */
					__( 'The block markup is broken: %s', 'jcore-taika' ),
					$problem
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * Stores the gateway's request ID on a post, so an audit log entry can be
	 * traced to the content it changed.
	 *
	 * @param int $post_id Post or attachment ID.
	 *
	 * @return void
	 */
	public static function mark( int $post_id ): void {
		$request_id = self::request_id();
		if ( '' !== $request_id ) {
			update_post_meta( $post_id, self::REQUEST_ID_META, $request_id );
		}
	}

	/**
	 * Returns the request ID the gateway sent, or an empty string.
	 *
	 * @return string
	 */
	public static function request_id(): string {
		if ( ! isset( $_SERVER[ self::REQUEST_ID_HEADER ] ) ) {
			return '';
		}

		$raw = sanitize_text_field( wp_unslash( $_SERVER[ self::REQUEST_ID_HEADER ] ) );

		return substr( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $raw ), 0, 64 );
	}

	/**
	 * Walks the block delimiters and reports the first unbalanced or invalid one.
	 *
	 * @param string $content Post content.
	 *
	 * @return string|null Description of the problem, or null when the markup is sound.
	 */
	private static function find_block_problem( string $content ): ?string {
		if ( ! preg_match_all( self::BLOCK_DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}

		$open = array();

		foreach ( $matches as $match ) {
			$name = ( $match['namespace'] ?? 'core/' ) . $match['name'];

			if ( isset( $match['attrs'] ) && null === json_decode( $match['attrs'] ) ) {
				/* translators: %s: block name. */
				return sprintf( __( 'the attributes of a %s block are not valid JSON.', 'jcore-taika' ), $name );
			}

			if ( isset( $match['void'] ) ) {
				continue;
			}

			if ( isset( $match['closer'] ) ) {
				$expected = array_pop( $open );
				if ( $expected !== $name ) {
					return null === $expected
						/* translators: %s: block name. */
						? sprintf( __( 'a closing %s comment has no opening comment.', 'jcore-taika' ), $name )
						/* translators: 1: block name that should close, 2: block name that closed. */
						: sprintf( __( 'expected the %1$s block to close, found a closing %2$s comment.', 'jcore-taika' ), $expected, $name );
				}
				continue;
			}

			$open[] = $name;
		}

		if ( array() !== $open ) {
			/* translators: %s: block name. */
			return sprintf( __( 'the %s block is never closed.', 'jcore-taika' ), end( $open ) );
		}

		return null;
	}
}
