<?php
/**
 * Render the dashboard as an admin, for the end-to-end test.
 *
 * Run with `wp eval-file` inside wp-env: the plugin is loaded, the options point
 * at the fake API, and this prints the screen the owner would see. A test
 * asserts on that HTML, so a PHP notice, a missing notice or an unrendered
 * section fails the run.
 *
 * @package HuggerHustle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

wp_set_current_user( 1 );
set_current_screen( 'toplevel_page_huggerhustle' );
$_GET['page'] = 'huggerhustle';
if ( isset( $args[0] ) ) {
	$_GET['campaign'] = $args[0];
}

HuggerHustle_Admin::render();
