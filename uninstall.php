<?php
/**
 * Complete plugin cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cleanup = static function (): void {
	$keys = get_option( 'tt5_caldav_cache_keys', array() );
	if ( is_array( $keys ) ) {
		foreach ( $keys as $key ) {
			delete_transient( (string) $key );
		}
	}
	delete_option( 'tt5_caldav_cache_keys' );
	$discovery_keys = get_option( 'tt5_caldav_discovery_keys', array() );
	if ( is_array( $discovery_keys ) ) {
		foreach ( $discovery_keys as $key ) {
			delete_transient( (string) $key );
		}
	}
	delete_option( 'tt5_caldav_discovery_keys' );
	delete_option( 'tt5_caldav_calendars' );
	delete_option( 'tt5_caldav_version' );
};

if ( is_multisite() ) {
	$offset = 0;
	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$cleanup();
			restore_current_blog();
		}
		$offset += count( $site_ids );
	} while ( count( $site_ids ) === 100 );
} else {
	$cleanup();
}
