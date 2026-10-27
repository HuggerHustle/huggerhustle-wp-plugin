<?php
/**
 * Where the key and the API address live.
 *
 * Both are plain options: one row each, read on every request, and `autoload`
 * is fine because the plugin is useless without them. Nothing here is secret in
 * the cryptographic sense — the key is a bearer token the owner can revoke at
 * /api-keys — but it must not be printed back in full, so the screen shows only
 * its first characters.
 *
 * @package HuggerHustle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings: the key, the API address, and the screen that edits them.
 */
class HuggerHustle_Settings {

	const OPTION_API_KEY = 'huggerhustle_api_key';
	const OPTION_API_URL = 'huggerhustle_api_url';

	const DEFAULT_API_URL = 'https://huggerhustle.com';

	/** The key's own prefix, so a mistyped paste is refused before any request. */
	const KEY_PREFIX = 'hh_';

	/** Register the option and its sanitizer. */
	public static function register() {
		register_setting(
			'huggerhustle',
			self::OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_key' ),
				'default'           => '',
			)
		);
		register_setting(
			'huggerhustle',
			self::OPTION_API_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_url' ),
				'default'           => self::DEFAULT_API_URL,
			)
		);
	}

	/**
	 * A key starts with `hh_` and is otherwise opaque.
	 *
	 * Refusing a wrong paste here means the first screen load does not spend a
	 * request on a key that could never work.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_key( $value ) {
		$key = trim( (string) $value );
		if ( '' === $key ) {
			return '';
		}
		if ( 0 !== strpos( $key, self::KEY_PREFIX ) ) {
			add_settings_error(
				self::OPTION_API_KEY,
				'huggerhustle_key_prefix',
				sprintf(
					/* translators: %s: the expected key prefix. */
					__( 'An API key starts with %s. Copy it from your HuggerHustle account page at Account → API keys.', 'huggerhustle' ),
					self::KEY_PREFIX
				)
			);
			return (string) get_option( self::OPTION_API_KEY, '' );
		}
		delete_transient( 'huggerhustle_' . md5( 'GET /balance' ) );
		return $key;
	}

	/**
	 * The API address, defaulting to production and refusing anything that is
	 * not http(s).
	 *
	 * A self-hosted or local install needs this: the plugin's own end-to-end test
	 * points it at `http://localhost:3000`.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		$url = untrailingslashit( trim( (string) $value ) );
		if ( '' === $url ) {
			return self::DEFAULT_API_URL;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			add_settings_error(
				self::OPTION_API_URL,
				'huggerhustle_url_scheme',
				__( 'The address must start with https://', 'huggerhustle' )
			);
			return self::DEFAULT_API_URL;
		}
		return $url;
	}

	/**
	 * The key as the screen may show it: a prefix and the last four characters,
	 * never the whole token.
	 */
	public static function masked_key() {
		$key = (string) get_option( self::OPTION_API_KEY, '' );
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) <= 12 ) {
			return self::KEY_PREFIX . '…';
		}
		return substr( $key, 0, 6 ) . '…' . substr( $key, -4 );
	}

	/**
	 * The link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=huggerhustle-settings' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'huggerhustle' ) . '</a>'
		);
		return $links;
	}
}
