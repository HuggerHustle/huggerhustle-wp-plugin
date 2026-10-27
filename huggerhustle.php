<?php
/**
 * Plugin Name:       HuggerHustle
 * Plugin URI:        https://huggerhustle.com
 * Description:       Find the businesses that should be your customers, read the introduction written for each of them, and approve the ones you want sent — from your own site's dashboard.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            HuggerHustle
 * Author URI:        https://huggerhustle.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       huggerhustle
 *
 * @package HuggerHustle
 */

defined( 'ABSPATH' ) || exit;

define( 'HUGGERHUSTLE_VERSION', '0.1.0' );
define( 'HUGGERHUSTLE_PLUGIN_FILE', __FILE__ );
define( 'HUGGERHUSTLE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HUGGERHUSTLE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once HUGGERHUSTLE_PLUGIN_DIR . 'includes/class-huggerhustle-client.php';
require_once HUGGERHUSTLE_PLUGIN_DIR . 'includes/class-huggerhustle-settings.php';
require_once HUGGERHUSTLE_PLUGIN_DIR . 'includes/class-huggerhustle-admin.php';

/**
 * Nothing here calls HuggerHustle until a key is saved.
 *
 * WordPress.org Guideline 7 requires that an external service is described in
 * the readme and that nothing contacts it before the user asks. The client is
 * built lazily for the same reason: with no key, no request is made at all.
 */
function huggerhustle_client() {
	static $client = null;
	if ( null === $client ) {
		$client = new HuggerHustle_Client(
			(string) get_option( HuggerHustle_Settings::OPTION_API_URL, HuggerHustle_Settings::DEFAULT_API_URL ),
			(string) get_option( HuggerHustle_Settings::OPTION_API_KEY, '' )
		);
	}
	return $client;
}

add_action( 'admin_menu', array( 'HuggerHustle_Admin', 'menu' ) );
add_action( 'admin_init', array( 'HuggerHustle_Settings', 'register' ) );
add_action( 'admin_post_huggerhustle_approve', array( 'HuggerHustle_Admin', 'handle_approve' ) );
add_action( 'admin_post_huggerhustle_start', array( 'HuggerHustle_Admin', 'handle_start' ) );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( 'HuggerHustle_Settings', 'action_links' ) );
