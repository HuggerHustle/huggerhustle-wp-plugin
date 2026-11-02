<?php
/**
 * Plugin Name: HuggerHustle — local site address
 * Description: Sends a `.test` site address (the one `tests/e2e-live.mjs` expects) to a local HuggerHustle app, because the app refuses a localhost website and wp-env defines WP_HOME as a constant so the site address cannot be changed any other way.
 *
 * wp-env mounts `.wp-env.json`'s `mappings`, so this file lives in the
 * WordPress here and nowhere else. It never ships: `scripts/package.mjs` zips
 * the plugin's own files, and a `mu-plugins` directory is not one of them.
 *
 * With `FAKE_SEARCHES=1` on the app, a `.test` website plays research's steps
 * with made-up businesses, so a campaign started from here costs nothing and
 * never calls a real search provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'huggerhustle_site_url',
	static function ( $url ) {
		// Only where WordPress itself says this is a throwaway site.
		if ( 'local' !== wp_get_environment_type() ) {
			return $url;
		}

		// `home_url( '/' )` always ends in a slash, and the tests compare against
		// the unfiltered value, so the replacement has to keep that shape.
		$replacement = defined( 'HUGGERHUSTLE_LOCAL_SITE_URL' )
			? HUGGERHUSTLE_LOCAL_SITE_URL
			: 'https://demo.test';

		return trailingslashit( $replacement );
	}
);
