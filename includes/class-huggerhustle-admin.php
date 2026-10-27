<?php
/**
 * The dashboard: what this account is doing, and the one action that needs a
 * person — approving a business whose introduction you have read.
 *
 * Everything shown comes from the API, redacted server-side before it is sent:
 * an unsent business's address and draft never reach this screen, so nothing
 * here can leak one.
 *
 * @package HuggerHustle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The admin screens and their two write actions.
 */
class HuggerHustle_Admin {

	const CAPABILITY = 'manage_options';
	const MENU       = 'huggerhustle';
	const SETTINGS   = 'huggerhustle-settings';

	/** How many pages become a document when a campaign starts from this site. */
	const MAX_PAGES = 5;

	/** The API's own ceiling for one document, in characters. */
	const MAX_DOCUMENT_CHARS = 100000;

	/**
	 * This site's own address, as the campaign's website.
	 *
	 * Filterable so a local install can point the campaign at something public:
	 * HuggerHustle refuses `localhost`, `.local` and `.test` outside development
	 * (`lib/research/sources.ts`), and that refusal is right for real owners.
	 *
	 * @return string
	 */
	public static function site_url() {
		$url = apply_filters( 'huggerhustle_site_url', home_url( '/' ) );
		return is_string( $url ) ? $url : home_url( '/' );
	}

	/** Register the menu and its pages. */
	public static function menu() {
		add_menu_page(
			__( 'HuggerHustle', 'huggerhustle' ),
			__( 'HuggerHustle', 'huggerhustle' ),
			self::CAPABILITY,
			self::MENU,
			array( __CLASS__, 'render' ),
			'dashicons-email-alt',
			26
		);
		add_submenu_page(
			self::MENU,
			__( 'HuggerHustle settings', 'huggerhustle' ),
			__( 'Settings', 'huggerhustle' ),
			self::CAPABILITY,
			self::SETTINGS,
			array( __CLASS__, 'render_settings' )
		);
	}

	/** The dashboard. */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'huggerhustle' ) );
		}

		$client = huggerhustle_client();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HuggerHustle', 'huggerhustle' ) . '</h1>';
		self::notice();

		if ( ! $client->is_connected() ) {
			echo '<p>' . esc_html__( 'Connect this site to your HuggerHustle account to start.', 'huggerhustle' ) . '</p>';
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . self::SETTINGS ) ),
				esc_html__( 'Add your API key', 'huggerhustle' )
			);
			echo '</div>';
			return;
		}

		self::render_balance( $client );

		$campaigns = $client->campaigns();
		if ( is_wp_error( $campaigns ) ) {
			self::render_error( $campaigns );
			echo '</div>';
			return;
		}

		$listed = isset( $campaigns['items'] ) && is_array( $campaigns['items'] ) ? $campaigns['items'] : array();
		$selected = self::selected_campaign( $listed );

		if ( empty( $listed ) ) {
			self::render_start( false );
		} else {
			self::render_campaigns( $listed, $selected );
			if ( $selected ) {
				self::render_prospects( $client, $selected );
			}
		}

		echo '</div>';
	}

	/**
	 * This site's campaign, or the one the screen was asked for, or the newest.
	 *
	 * Matching `home_url()` against each campaign's `website` is why the API
	 * carries that field: an owner with several campaigns should land on the one
	 * that belongs to this site.
	 *
	 * @param array $campaigns Listed campaigns.
	 * @return array|null
	 */
	private static function selected_campaign( array $campaigns ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which campaign to show, not changing anything.
		$wanted = isset( $_GET['campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign'] ) ) : '';
		$site   = untrailingslashit( self::site_url() );

		foreach ( $campaigns as $campaign ) {
			if ( ! is_array( $campaign ) ) {
				continue;
			}
			if ( '' !== $wanted && isset( $campaign['id'] ) && $wanted === $campaign['id'] ) {
				return $campaign;
			}
		}
		foreach ( $campaigns as $campaign ) {
			if ( is_array( $campaign ) && isset( $campaign['website'] )
				&& untrailingslashit( (string) $campaign['website'] ) === $site ) {
				return $campaign;
			}
		}
		return isset( $campaigns[0] ) && is_array( $campaigns[0] ) ? $campaigns[0] : null;
	}

	/**
	 * @param HuggerHustle_Client $client The client.
	 */
	private static function render_balance( HuggerHustle_Client $client ) {
		$balance = $client->balance();
		if ( is_wp_error( $balance ) ) {
			self::render_error( $balance );
			return;
		}
		$available = isset( $balance['available'] ) && null !== $balance['available']
			? (int) $balance['available']
			: null;
		echo '<p>';
		if ( null === $available ) {
			echo esc_html__( 'Your email balance is unlimited on this plan.', 'huggerhustle' );
		} else {
			printf(
				/* translators: %d: number of introductions left. */
				esc_html( _n( '%d introduction left.', '%d introductions left.', $available, 'huggerhustle' ) ),
				(int) $available
			);
		}
		echo ' <a href="https://huggerhustle.com/balance" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Your balance', 'huggerhustle' )
			. '</a></p>';
	}

	/**
	 * @param array      $campaigns Listed campaigns.
	 * @param array|null $selected  The one in view.
	 */
	private static function render_campaigns( array $campaigns, $selected ) {
		echo '<h2>' . esc_html__( 'Your campaigns', 'huggerhustle' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Campaign', 'huggerhustle' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'huggerhustle' ) . '</th>';
		echo '<th>' . esc_html__( 'Found', 'huggerhustle' ) . '</th>';
		echo '<th>' . esc_html__( 'Ready', 'huggerhustle' ) . '</th>';
		echo '<th>' . esc_html__( 'Approved', 'huggerhustle' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $campaigns as $campaign ) {
			if ( ! is_array( $campaign ) || ! isset( $campaign['id'] ) ) {
				continue;
			}
			$id       = (string) $campaign['id'];
			$counts   = isset( $campaign['counts'] ) && is_array( $campaign['counts'] ) ? $campaign['counts'] : array();
			$is_now   = is_array( $selected ) && isset( $selected['id'] ) && $selected['id'] === $id;
			$url      = add_query_arg(
				array(
					'page'     => self::MENU,
					'campaign' => $id,
				),
				admin_url( 'admin.php' )
			);
			$name     = isset( $campaign['name'] ) ? (string) $campaign['name'] : $id;
			echo '<tr' . ( $is_now ? ' class="huggerhustle-current"' : '' ) . '>';
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></td>';
			echo '<td>' . esc_html( isset( $campaign['status'] ) ? (string) $campaign['status'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $counts['found'] ) ? (string) (int) $counts['found'] : '0' ) . '</td>';
			echo '<td>' . esc_html( isset( $counts['ready'] ) ? (string) (int) $counts['ready'] : '0' ) . '</td>';
			echo '<td>' . esc_html( isset( $counts['approved'] ) ? (string) (int) $counts['approved'] : '0' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The businesses waiting for a person, with the introduction written for each.
	 *
	 * @param HuggerHustle_Client $client   The client.
	 * @param array               $campaign The campaign in view.
	 */
	private static function render_prospects( HuggerHustle_Client $client, array $campaign ) {
		$campaign_id = (string) $campaign['id'];
		$prospects   = $client->prospects( $campaign_id, 'draft' );
		if ( is_wp_error( $prospects ) ) {
			self::render_error( $prospects );
			return;
		}
		$items = isset( $prospects['items'] ) && is_array( $prospects['items'] ) ? $prospects['items'] : array();

		echo '<h2>' . esc_html__( 'Ready for your approval', 'huggerhustle' ) . '</h2>';
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'Nothing is waiting. HuggerHustle keeps looking and this list fills as businesses are found.', 'huggerhustle' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Business', 'huggerhustle' ) . '</th>';
		echo '<th>' . esc_html__( 'Why it fits', 'huggerhustle' ) . '</th>';
		echo '<th>' . esc_html__( 'The introduction', 'huggerhustle' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'huggerhustle' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				continue;
			}
			$prospect_id = (string) $item['id'];
			$company     = isset( $item['company'] ) ? (string) $item['company'] : '';
			$line        = isset( $item['line'] ) ? (string) $item['line'] : '';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $company ) . '</strong></td>';
			echo '<td>' . esc_html( $line ) . '</td>';
			echo '<td>';
			self::draft_cell( $client, $campaign_id, $prospect_id );
			echo '</td><td>';
			self::approve_form( $campaign_id, $prospect_id );
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The introduction, fetched one business at a time.
	 *
	 * The list endpoint deliberately carries no draft; asking for one page of
	 * them costs a request per business. One row, one request, cached a minute.
	 *
	 * @param HuggerHustle_Client $client      The client.
	 * @param string              $campaign_id Campaign id.
	 * @param string              $prospect_id Business id.
	 * @return string Escaped HTML.
	 */
	private static function draft_cell( HuggerHustle_Client $client, $campaign_id, $prospect_id ) {
		$detail = $client->prospect( $campaign_id, $prospect_id );
		if ( is_wp_error( $detail ) ) {
			echo '<em>' . esc_html( $detail->get_error_message() ) . '</em>';
			return;
		}
		$draft = isset( $detail['draft'] ) && is_array( $detail['draft'] ) ? $detail['draft'] : array();
		if ( empty( $draft ) || empty( $draft['text'] ) ) {
			echo '<em>' . esc_html__( 'No introduction yet.', 'huggerhustle' ) . '</em>';
			return;
		}
		?>
		<details>
			<summary><?php echo esc_html( isset( $draft['subject'] ) ? (string) $draft['subject'] : '' ); ?></summary>
			<pre style="white-space:pre-wrap;margin:0"><?php echo esc_html( (string) $draft['text'] ); ?></pre>
		</details>
		<?php
	}

	/**
	 * The approve button: a POST with a nonce, never a GET.
	 *
	 * @param string $campaign_id Campaign id.
	 * @param string $prospect_id Business id.
	 * @return string Escaped HTML.
	 */
	private static function approve_form( $campaign_id, $prospect_id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'huggerhustle_approve' ); ?>
			<input type="hidden" name="action" value="huggerhustle_approve" />
			<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign_id ); ?>" />
			<input type="hidden" name="prospect" value="<?php echo esc_attr( $prospect_id ); ?>" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve', 'huggerhustle' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Start a campaign from this site: its address, its name, and its pages.
	 *
	 * The pages are sent as documents because HuggerHustle reads what the owner
	 * says about their business before it reads the web — and a page that is
	 * behind a firewall, a page builder or bot protection may not be readable
	 * from outside, while its own text is right here.
	 *
	 * @param bool $has_campaigns Whether the account already has campaigns.
	 */
	private static function render_start( $has_campaigns ) {
		?>
		<h2><?php echo esc_html( $has_campaigns ? __( 'Start another campaign', 'huggerhustle' ) : __( 'Start your first campaign', 'huggerhustle' ) ); ?></h2>
		<p><?php esc_html_e( 'HuggerHustle reads this site, finds businesses that could use what you sell, and writes the introduction for each one. Nothing is sent until you approve it here.', 'huggerhustle' ); ?></p>
		<p><code><?php echo esc_html( self::site_url() ); ?></code></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'huggerhustle_start' ); ?>
			<input type="hidden" name="action" value="huggerhustle_start" />
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Find businesses for this site', 'huggerhustle' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * What a campaign started here sends.
	 *
	 * Its own method because it is the part worth testing: which pages, and what
	 * their text becomes. The handler is only a nonce and a redirect around it.
	 *
	 * @return array
	 */
	public static function campaign_payload() {
		return array(
			'website'      => self::site_url(),
			'businessName' => (string) get_bloginfo( 'name' ),
			'mode'         => 'review',
			'documents'    => self::published_pages(),
		);
	}

	/** Start a campaign from this site's own content. */
	public static function handle_start() {
		self::require_capability();
		check_admin_referer( 'huggerhustle_start' );
		$client = huggerhustle_client();

		$result = $client->create_campaign( self::campaign_payload() );

		if ( is_wp_error( $result ) ) {
			self::redirect( '', $result->get_error_message() );
		}
		$id = isset( $result['id'] ) ? (string) $result['id'] : '';
		self::redirect( $id, __( 'Campaign started. It fills as businesses are found.', 'huggerhustle' ) );
	}

	/** Approve one business. */
	public static function handle_approve() {
		// The nonce comes before anything is read: an action built from the input
		// it protects would be a circular check.
		self::require_capability();
		check_admin_referer( 'huggerhustle_approve' );
		$campaign = isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : '';
		$prospect = isset( $_POST['prospect'] ) ? sanitize_text_field( wp_unslash( $_POST['prospect'] ) ) : '';

		$result = huggerhustle_client()->approve( $campaign, $prospect );
		if ( is_wp_error( $result ) ) {
			self::redirect( $campaign, $result->get_error_message() );
		}
		self::redirect( $campaign, __( 'Approved. It goes out with the next batch.', 'huggerhustle' ) );
	}

	/** Capability, or die. The nonce is checked inline by each handler. */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'huggerhustle' ), 403 );
		}
	}

	/**
	 * Back to the dashboard with a message, keeping the campaign in view.
	 *
	 * @param string $campaign_id Campaign id, or "".
	 * @param string $message     What to show.
	 */
	private static function redirect( $campaign_id, $message ) {
		$url = add_query_arg(
			array(
				'page'                => self::MENU,
				'campaign'            => $campaign_id,
				'huggerhustle_notice' => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** The message the last action left, if any. */
	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a message to print, not changing anything.
		$raw = isset( $_GET['huggerhustle_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['huggerhustle_notice'] ) ) : '';
		if ( '' === $raw ) {
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html( rawurldecode( $raw ) )
		);
	}

	/**
	 * Up to five published pages' text, for the campaign's documents.
	 *
	 * A page's rendered content still carries shortcodes and block markup, so it
	 * is reduced to text: the model reads words, and a document full of markup
	 * costs tokens for nothing.
	 *
	 * @return array
	 */
	private static function published_pages() {
		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'product' ),
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_PAGES,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);

		$documents = array();
		foreach ( $pages as $page ) {
			$text = wp_strip_all_tags( strip_shortcodes( (string) $page->post_content ) );
			$text = trim( preg_replace( '/[ \t]+/', ' ', str_replace( "\r", '', $text ) ) );
			$text = preg_replace( "/\n{3,}/", "\n\n", $text );
			$text = trim( (string) $text );
			if ( '' === $text ) {
				continue;
			}
			$documents[] = array(
				'name'    => (string) $page->post_title,
				'content' => mb_substr( $text, 0, self::MAX_DOCUMENT_CHARS ),
			);
		}
		return $documents;
	}

	/**
	 * @param WP_Error $error The thing to show.
	 */
	private static function render_error( WP_Error $error ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $error->get_error_message() )
		);
	}

	/** The settings screen. */
	public static function render_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'huggerhustle' ) );
		}
		$masked = HuggerHustle_Settings::masked_key();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HuggerHustle settings', 'huggerhustle' ) . '</h1>';
		settings_errors();
		echo '<p>';
		if ( '' === $masked ) {
			esc_html_e( 'Create a key on your HuggerHustle account page (Account → API keys), then paste it here.', 'huggerhustle' );
		} else {
			printf(
				/* translators: %s: the masked key, e.g. "hh_abc…wxyz". */
				esc_html__( 'Connected with key %s.', 'huggerhustle' ),
				'<code>' . esc_html( $masked ) . '</code>'
			);
		}
		echo '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'huggerhustle' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="huggerhustle_api_key">' . esc_html__( 'API key', 'huggerhustle' ) . '</label></th>';
		echo '<td><input name="' . esc_attr( HuggerHustle_Settings::OPTION_API_KEY ) . '" id="huggerhustle_api_key" type="password" class="regular-text" value="" autocomplete="off" placeholder="' . esc_attr( $masked ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key. Each key can be revoked from your account page.', 'huggerhustle' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="huggerhustle_api_url">' . esc_html__( 'HuggerHustle address', 'huggerhustle' ) . '</label></th>';
		echo '<td><input name="' . esc_attr( HuggerHustle_Settings::OPTION_API_URL ) . '" id="huggerhustle_api_url" type="url" class="regular-text" value="' . esc_attr( (string) get_option( HuggerHustle_Settings::OPTION_API_URL, HuggerHustle_Settings::DEFAULT_API_URL ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave this unless you run your own HuggerHustle server.', 'huggerhustle' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}
}
