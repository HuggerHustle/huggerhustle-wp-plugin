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

	/** The business-detail fields a `PATCH /campaigns/{id}` accepts. */
	const DETAIL_FIELDS = array( 'businessName', 'whatYouDo', 'currentOffer', 'idealBuyer', 'geography', 'objective', 'meetingEmail' );

	/** The actions the campaign control may send, and nothing else. */
	const ACTIONS = array( 'stop', 'resume', 'retry', 'continue-anyway' );

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
				self::render_controls( $selected );
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
		// The API nests these under `credits`, and they mean different things:
		// `trialEmailsLeft` is the introductions left, `available` the free
		// meetings. Showing one as the other would be a lie the owner acts on.
		$credits  = isset( $balance['credits'] ) && is_array( $balance['credits'] ) ? $balance['credits'] : array();
		$emails   = isset( $credits['trialEmailsLeft'] ) ? $credits['trialEmailsLeft'] : null;
		$meetings = isset( $credits['available'] ) ? $credits['available'] : null;
		$free     = ! empty( $credits['freeSends'] );

		if ( $free ) {
			$line = __( 'Sending is free on this account.', 'huggerhustle' );
		} elseif ( null === $emails ) {
			$line = __( 'Unlimited introductions on this plan.', 'huggerhustle' );
		} else {
			$line = sprintf(
				/* translators: %d: introductions left in the trial. */
				_n( '%d introduction left in your trial.', '%d introductions left in your trial.', (int) $emails, 'huggerhustle' ),
				(int) $emails
			);
		}
		if ( ! $free && null !== $meetings ) {
			$line .= ' ' . sprintf(
				/* translators: %d: free meetings left. */
				_n( '%d free meeting left.', '%d free meetings left.', (int) $meetings, 'huggerhustle' ),
				(int) $meetings
			);
		}

		echo '<p>' . esc_html( $line );
		echo ' <a href="' . esc_url( 'https://huggerhustle.com/billing' ) . '" target="_blank" rel="noopener noreferrer">'
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

		// One call for the whole page: approving them one by one costs one
		// request each against the key's 60 a minute.
		// The campaign's own ready count, not the number on this page: the list is
		// one page of the campaign, and the button approves the whole campaign.
		$counts  = isset( $campaign['counts'] ) && is_array( $campaign['counts'] ) ? $campaign['counts'] : array();
		$ready   = isset( $counts['ready'] ) ? (int) $counts['ready'] : count( $items );
		$shown   = count( $items );
		echo '<p>';
		self::approve_all_form( $campaign_id, $ready );
		echo ' <span class="description">';
		printf(
			/* translators: 1: how many businesses are listed on this page, 2: how many are ready in the campaign. */
			esc_html__( 'Approves every ready business in this campaign, not only the %1$d listed here (%2$d ready). Read as many introductions as you need first: they all go out with the next batch.', 'huggerhustle' ),
			(int) $shown,
			(int) $ready
		);
		echo '</span></p>';

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
	 * The controls for the campaign in view.
	 *
	 * One button or one small form each, because the API offers each as one
	 * call: an owner who works in `wp-admin` should not have to open a second
	 * tab to stop a campaign or repair the details research could not read.
	 *
	 * @param array $campaign The campaign in view.
	 */
	private static function render_controls( array $campaign ) {
		$id     = (string) $campaign['id'];
		$status = isset( $campaign['status'] ) ? (string) $campaign['status'] : '';

		echo '<h2>' . esc_html__( 'This campaign', 'huggerhustle' ) . '</h2>';
		echo '<p class="huggerhustle-controls">';
		if ( 'researching' === $status || 'active' === $status ) {
			self::act_form( $id, 'stop', __( 'Stop this campaign', 'huggerhustle' ) );
		} elseif ( 'paused' === $status ) {
			self::act_form( $id, 'resume', __( 'Resume this campaign', 'huggerhustle' ) );
		} elseif ( 'error' === $status ) {
			self::act_form( $id, 'retry', __( 'Try again', 'huggerhustle' ) );
		}
		echo '</p>';

		if ( 'needs-details' === $status ) {
			self::details_form( $id );
		}
		self::sources_form( $id );
	}

	/**
	 * Stop, resume or retry: one POST with a nonce.
	 *
	 * @param string $campaign_id Campaign id.
	 * @param string $do          One of `self::ACTIONS`.
	 * @param string $label       Button text.
	 */
	private static function act_form( $campaign_id, $do, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<?php wp_nonce_field( 'huggerhustle_act' ); ?>
			<input type="hidden" name="action" value="huggerhustle_act" />
			<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign_id ); ?>" />
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>" />
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * The form that repairs a campaign held at `needs-details`.
	 *
	 * Every field is optional and only what is filled is sent: the API merges
	 * over what it already stores, so a blank input keeps the saved value
	 * instead of wiping it. The name starts from this site's title because
	 * sending is refused until a business name is set, and that is the one
	 * value WordPress already knows.
	 *
	 * @param string $campaign_id Campaign id.
	 */
	private static function details_form( $campaign_id ) {
		$fields = array(
			'businessName' => array( __( 'Your business name', 'huggerhustle' ), (string) get_bloginfo( 'name' ) ),
			'whatYouDo'    => array( __( 'What you do, in one line', 'huggerhustle' ), '' ),
			'idealBuyer'   => array( __( 'Who pays you: one kind of business', 'huggerhustle' ), '' ),
			'currentOffer' => array( __( 'What you offer today', 'huggerhustle' ), '' ),
			'geography'    => array( __( 'Where you work', 'huggerhustle' ), '' ),
			'objective'    => array( __( 'The work you want more of', 'huggerhustle' ), '' ),
			'meetingEmail' => array( __( 'Where a meeting invitation should go', 'huggerhustle' ), '' ),
		);
		?>
		<h3><?php esc_html_e( 'What research could not read', 'huggerhustle' ); ?></h3>
		<p><?php esc_html_e( 'Fill what you can and leave the rest blank: anything already saved is kept, and saving reads your business again with these details.', 'huggerhustle' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'huggerhustle_details' ); ?>
			<input type="hidden" name="action" value="huggerhustle_details" />
			<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign_id ); ?>" />
			<table class="form-table" role="presentation"><tbody>
			<?php foreach ( $fields as $name => $field ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( 'hh_' . $name ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
					<td><input class="regular-text" type="text" id="<?php echo esc_attr( 'hh_' . $name ); ?>" name="<?php echo esc_attr( 'changes[' . $name . ']' ); ?>" value="<?php echo esc_attr( $field[1] ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php submit_button( __( 'Save and read again', 'huggerhustle' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * The form that replaces what a campaign reads.
	 *
	 * Behind a disclosure, and it says what it destroys before the button: the
	 * API drops every business not yet sent and starts research over, which is
	 * too much to do by accident.
	 *
	 * @param string $campaign_id Campaign id.
	 */
	private static function sources_form( $campaign_id ) {
		?>
		<details class="huggerhustle-sources" style="margin:12px 0">
			<summary><?php esc_html_e( 'Change what this campaign reads', 'huggerhustle' ); ?></summary>
			<p><strong><?php esc_html_e( 'This starts the campaign over.', 'huggerhustle' ); ?></strong>
				<?php esc_html_e( 'Every business it found and you have not approved is dropped, and research reads your business again. Businesses already approved or sent stay.', 'huggerhustle' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'huggerhustle_sources' ); ?>
				<input type="hidden" name="action" value="huggerhustle_sources" />
				<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign_id ); ?>" />
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><label for="hh_sources_website"><?php esc_html_e( 'Website', 'huggerhustle' ); ?></label></th>
						<td><input class="regular-text" type="url" id="hh_sources_website" name="website" value="<?php echo esc_attr( self::site_url() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hh_sources_explanation"><?php esc_html_e( 'What you sell, in your own words', 'huggerhustle' ); ?></label></th>
						<td><textarea class="large-text" rows="4" id="hh_sources_explanation" name="explanation"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'This site\'s pages', 'huggerhustle' ); ?></th>
						<td><label><input type="checkbox" name="send_pages" value="1" checked="checked" />
							<?php
							printf(
								/* translators: %d: how many pages are sent. */
								esc_html__( 'Send up to %d of this site\'s published pages with it', 'huggerhustle' ),
								(int) self::MAX_PAGES
							);
							?>
						</label></td>
					</tr>
				</tbody></table>
				<?php submit_button( __( 'Use these sources', 'huggerhustle' ), 'secondary', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}

	/**
	 * Approve every listed business: one POST, one request.
	 *
	 * @param string $campaign_id Campaign id.
	 * @param int    $count       How many are ready.
	 */
	private static function approve_all_form( $campaign_id, $count ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<?php wp_nonce_field( 'huggerhustle_approve_all' ); ?>
			<input type="hidden" name="action" value="huggerhustle_approve_all" />
			<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign_id ); ?>" />
			<button type="submit" class="button"><?php
				printf(
					/* translators: %d: how many businesses are ready. */
					esc_html( _n( 'Approve all %d ready business', 'Approve all %d ready businesses', (int) $count, 'huggerhustle' ) ),
					(int) $count
				);
			?></button>
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

	/** Approve every business that is ready, in one request. */
	public static function handle_approve_all() {
		self::require_capability();
		check_admin_referer( 'huggerhustle_approve_all' );
		$campaign = isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : '';

		$result = huggerhustle_client()->approve_all( $campaign );
		if ( is_wp_error( $result ) ) {
			self::redirect( $campaign, $result->get_error_message() );
		}

		$count = isset( $result['approved'] ) ? (int) $result['approved'] : 0;
		if ( $count < 1 ) {
			self::redirect( $campaign, __( 'Nothing was ready to approve.', 'huggerhustle' ) );
		}
		self::redirect(
			$campaign,
			sprintf(
				/* translators: %d: how many businesses were approved. */
				_n( '%d business approved. It goes out with the next batch.', '%d businesses approved. They go out with the next batch.', $count, 'huggerhustle' ),
				$count
			)
		);
	}

	/**
	 * Repair a campaign held on missing details.
	 *
	 * Only the filled inputs are sent: the API merges them over what it stores,
	 * so leaving a field blank keeps the saved value rather than clearing it.
	 */
	public static function handle_details() {
		self::require_capability();
		check_admin_referer( 'huggerhustle_details' );
		$campaign = isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : '';
		// Sanitised on the way in, then only the fields the API knows are kept:
		// an unknown key would be a 422 the owner cannot do anything about.
		$posted = isset( $_POST['changes'] ) && is_array( $_POST['changes'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['changes'] ) )
			: array();

		$changes = array();
		foreach ( self::DETAIL_FIELDS as $field ) {
			$value = isset( $posted[ $field ] ) && is_string( $posted[ $field ] ) ? trim( $posted[ $field ] ) : '';
			if ( '' !== $value ) {
				$changes[ $field ] = $value;
			}
		}

		if ( empty( $changes ) ) {
			self::redirect( $campaign, __( 'Nothing was filled in, so nothing changed.', 'huggerhustle' ) );
		}

		if ( isset( $changes['meetingEmail'] ) ) {
			$email = sanitize_email( $changes['meetingEmail'] );
			if ( ! is_email( $email ) ) {
				self::redirect( $campaign, __( 'That meeting email is not a valid address.', 'huggerhustle' ) );
			}
			$changes['meetingEmail'] = $email;
		}

		$result = huggerhustle_client()->update_details( $campaign, $changes );
		if ( is_wp_error( $result ) ) {
			self::redirect( $campaign, $result->get_error_message() );
		}
		self::redirect( $campaign, __( 'Saved. We read your business again with these details.', 'huggerhustle' ) );
	}

	/** Replace what a campaign reads: its website, its description, its pages. */
	public static function handle_sources() {
		self::require_capability();
		check_admin_referer( 'huggerhustle_sources' );
		$campaign = isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : '';

		$posted      = isset( $_POST['website'] ) ? esc_url_raw( sanitize_url( wp_unslash( $_POST['website'] ) ) ) : '';
		$explanation = isset( $_POST['explanation'] ) ? sanitize_textarea_field( wp_unslash( $_POST['explanation'] ) ) : '';
		$send_pages  = ! empty( $_POST['send_pages'] );

		// A website the owner cleared is sent cleared: the form already prefills
		// this site's address, so an empty input means "no website", and putting
		// it back here would make a description-only reset impossible.
		$result = huggerhustle_client()->set_sources(
			$campaign,
			array(
				'website'     => $posted,
				'explanation' => $explanation,
				'documents'   => $send_pages ? self::published_pages() : array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			self::redirect( $campaign, $result->get_error_message() );
		}
		self::redirect( $campaign, __( 'Sources saved. Research starts over with them.', 'huggerhustle' ) );
	}

	/** Stop, resume or retry the campaign in view. */
	public static function handle_act() {
		self::require_capability();
		check_admin_referer( 'huggerhustle_act' );
		$campaign = isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : '';
		$do       = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( ! in_array( $do, self::ACTIONS, true ) ) {
			self::redirect( $campaign, __( 'That is not something this screen does.', 'huggerhustle' ) );
		}

		$result = huggerhustle_client()->act( $campaign, $do );
		if ( is_wp_error( $result ) ) {
			self::redirect( $campaign, $result->get_error_message() );
		}
		$status = isset( $result['status'] ) ? (string) $result['status'] : '';
		self::redirect(
			$campaign,
			sprintf(
				/* translators: %s: the campaign's new status, e.g. "paused". */
				__( 'The campaign is now %s.', 'huggerhustle' ),
				$status
			)
		);
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
