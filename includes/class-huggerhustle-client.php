<?php
/**
 * The one place that talks to HuggerHustle.
 *
 * Every request goes through `request()`, so the key header, the timeout, the
 * error envelope and the cache live in one place. The API answers
 * `{ error: { code, message, fields? } }` for anything that is not 2xx, and
 * `code` is a closed list — the message is written for a person, so it is what
 * the screen shows.
 *
 * @package HuggerHustle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A thin client over `https://huggerhustle.com/api/v1`.
 */
class HuggerHustle_Client {

	/** Reads are cached this long: one screen load must not spend the key's 60 requests a minute. */
	const CACHE_TTL = 60;

	/** A read must not hold a page render; a write must not time out mid-flight. */
	const TIMEOUT_READ  = 10;
	const TIMEOUT_WRITE = 20;

	/**
	 * The API's base URL, without a trailing slash.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * The owner's API key, or "" when none is saved.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * @param string $url Base URL, e.g. `https://huggerhustle.com`.
	 * @param string $key `hh_…`, or "".
	 */
	public function __construct( $url, $key ) {
		$this->url = untrailingslashit( (string) $url );
		$this->key = trim( (string) $key );
	}

	/**
	 * Whether a key is saved. With no key nothing is requested at all.
	 */
	public function is_connected() {
		return '' !== $this->key;
	}

	/**
	 * This account's email balance.
	 *
	 * @return array|WP_Error
	 */
	public function balance() {
		return $this->request( 'GET', '/balance' );
	}

	/**
	 * This account's campaigns, newest first.
	 *
	 * @return array|WP_Error
	 */
	public function campaigns() {
		return $this->request( 'GET', '/campaigns?limit=100' );
	}

	/**
	 * One campaign with its counts.
	 *
	 * @param string $campaign_id Campaign id.
	 * @return array|WP_Error
	 */
	public function campaign( $campaign_id ) {
		return $this->request( 'GET', '/campaigns/' . rawurlencode( $campaign_id ) );
	}

	/**
	 * A campaign's businesses, newest first.
	 *
	 * @param string $campaign_id Campaign id.
	 * @param string $status      Optional status filter, e.g. `draft`.
	 * @return array|WP_Error
	 */
	public function prospects( $campaign_id, $status = '' ) {
		$path = '/campaigns/' . rawurlencode( $campaign_id ) . '/prospects?limit=100';
		if ( '' !== $status ) {
			$path .= '&status=' . rawurlencode( $status );
		}
		return $this->request( 'GET', $path );
	}

	/**
	 * One business with the introduction written for it.
	 *
	 * @param string $campaign_id Campaign id.
	 * @param string $prospect_id Business id.
	 * @return array|WP_Error
	 */
	public function prospect( $campaign_id, $prospect_id ) {
		return $this->request(
			'GET',
			'/campaigns/' . rawurlencode( $campaign_id ) . '/prospects/' . rawurlencode( $prospect_id )
		);
	}

	/**
	 * Approve a business, which also authorizes the campaign to send.
	 *
	 * @param string $campaign_id Campaign id.
	 * @param string $prospect_id Business id.
	 * @return array|WP_Error
	 */
	public function approve( $campaign_id, $prospect_id ) {
		return $this->request(
			'PATCH',
			'/campaigns/' . rawurlencode( $campaign_id ) . '/prospects/' . rawurlencode( $prospect_id ),
			array( 'approved' => true ),
			true
		);
	}

	/**
	 * Start a campaign from this site.
	 *
	 * `businessName` is not decoration: sending is refused until it is set, and
	 * no endpoint can set it after creation.
	 *
	 * @param array $body See `CreateCampaignInputSchema`.
	 * @return array|WP_Error
	 */
	public function create_campaign( array $body ) {
		return $this->request( 'POST', '/campaigns', $body, true );
	}

	/**
	 * One request, with the key, the timeout, the cache and the error envelope.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Path below `/api/v1`, query string included.
	 * @param array|null $body Body for a write.
	 * @param bool   $write   Skip the cache and use the write timeout.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null, $write = false ) {
		if ( ! $this->is_connected() ) {
			return new WP_Error(
				'huggerhustle_no_key',
				__( 'Add your HuggerHustle API key first.', 'huggerhustle' )
			);
		}

		$cache_key = 'huggerhustle_' . md5( $method . ' ' . $path );
		if ( ! $write ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$args = array(
			'method'  => $method,
			'timeout' => $write ? self::TIMEOUT_WRITE : self::TIMEOUT_READ,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->key,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->url . '/api/v1' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'huggerhustle_unreachable',
				sprintf(
					/* translators: %s: the transport error, e.g. "cURL error 28: timed out". */
					__( 'Could not reach HuggerHustle: %s', 'huggerhustle' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $json ) ) {
			return $this->error_from( $status, $json, $response );
		}

		if ( ! $write ) {
			set_transient( $cache_key, $json, self::CACHE_TTL );
		}
		return $json;
	}

	/**
	 * The API's own words, as a WP_Error.
	 *
	 * The message is what a person reads, so it is passed through untouched; the
	 * `code` becomes the WP_Error code so a caller can branch on it.
	 *
	 * @param int    $status   HTTP status.
	 * @param mixed  $json     Decoded body, if it parsed.
	 * @param array  $response The raw response, for Retry-After.
	 * @return WP_Error
	 */
	private function error_from( $status, $json, $response ) {
		$code    = 'huggerhustle_http_' . $status;
		$message = '';

		if ( is_array( $json ) && isset( $json['error'] ) && is_array( $json['error'] ) ) {
			$error = $json['error'];
			if ( isset( $error['code'] ) && is_string( $error['code'] ) ) {
				$code = 'huggerhustle_' . $error['code'];
			}
			if ( isset( $error['message'] ) && is_string( $error['message'] ) ) {
				$message = $error['message'];
			}
			if ( isset( $error['fields'] ) && is_array( $error['fields'] ) ) {
				$message .= ' ' . implode( ' ', array_map( 'strval', $error['fields'] ) );
			}
		}

		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'HuggerHustle answered with HTTP %d.', 'huggerhustle' ),
				$status
			);
		}

		if ( 429 === $status ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $retry > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: seconds to wait. */
					__( 'Try again in %d seconds.', 'huggerhustle' ),
					$retry
				);
			}
		}

		return new WP_Error( $code, $message );
	}
}
