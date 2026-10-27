<?php
/**
 * Uninstall: the two options this plugin writes, and nothing else.
 *
 * HuggerHustle holds every campaign, business and introduction; this site keeps
 * only the API key and the address it talks to. Removing the plugin must not
 * leave a live credential behind.
 *
 * @package HuggerHustle
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'huggerhustle_api_key' );
delete_option( 'huggerhustle_api_url' );

// The cached reads go too: a leftover transient can name a campaign that no
// longer exists, and a fresh install would show it for a minute.
global $wpdb;
// The cache keys are hashes of request paths, so they cannot be listed; deleting
// by prefix is the only way to leave no stale read behind.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_huggerhustle_%' OR option_name LIKE '_transient_timeout_huggerhustle_%'"
);
