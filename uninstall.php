<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * @package Jcore\Taika
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's options on the current site.
 *
 * Names are spelled out rather than read from the classes so this file stands
 * alone, as uninstall.php runs without the plugin loaded. Add every option,
 * transient, table and meta key the plugin creates.
 *
 * @return void
 */
function jcore_taika_uninstall_site(): void {
	delete_post_meta_by_key( '_jcore_taika_request_id' );
}

if ( is_multisite() ) {
	$jcore_taika_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $jcore_taika_site_ids as $jcore_taika_site_id ) {
		switch_to_blog( (int) $jcore_taika_site_id );
		jcore_taika_uninstall_site();
		restore_current_blog();
	}
} else {
	jcore_taika_uninstall_site();
}
