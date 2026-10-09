<?php
/**
 * Media ability: add an image or file from a URL.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika\Abilities;

use Jcore\Taika\Write_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute and permission callbacks for upload-media.
 */
final class Media {

	/**
	 * Permission: may the user upload, and edit the post it is attached to?
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return bool
	 */
	public static function can_upload( mixed $input = null ): bool {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;

		return 0 === $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Downloads a public HTTPS file into the media library.
	 *
	 * `download_url()` fetches with `wp_safe_remote_get()`, which refuses
	 * local and private addresses, and `media_handle_sideload()` applies the
	 * site's allowed file types, as an upload from the Media screen would.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload_media( mixed $input = null ): array|WP_Error {
		$input = is_array( $input ) ? $input : array();
		$url   = isset( $input['url'] ) && is_string( $input['url'] ) ? $input['url'] : '';

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || false === wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'jcore_taika_invalid_url',
				__( 'Give a public https:// URL.', 'jcore-taika' ),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		if ( filesize( $tmp ) > wp_max_upload_size() ) {
			wp_delete_file( $tmp );

			return new WP_Error(
				'jcore_taika_file_too_large',
				__( 'The file is larger than this site accepts.', 'jcore-taika' ),
				array( 'status' => 413 )
			);
		}

		$file = array(
			'name'     => sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ),
			'tmp_name' => $tmp,
		);

		$post_id       = (int) ( $input['post_id'] ?? 0 );
		$title         = isset( $input['title'] ) && is_string( $input['title'] ) ? $input['title'] : null;
		$attachment_id = media_handle_sideload( $file, $post_id, $title );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			return $attachment_id;
		}

		if ( isset( $input['alt_text'] ) && is_string( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}

		Write_Guard::mark( $attachment_id );

		return array(
			'id'        => $attachment_id,
			'url'       => (string) wp_get_attachment_url( $attachment_id ),
			'mime_type' => (string) get_post_mime_type( $attachment_id ),
		);
	}
}
