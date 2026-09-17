<?php
/**
 * Settings screen: Settings → LinkedIn Connection.
 *
 * @package LinkedInCrosspost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the app-credential options and renders the connection screen.
 */
final class LCP_Settings {

	const PAGE  = 'lcp-settings';
	const GROUP = 'lcp_settings';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( LCP_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	/**
	 * Add the options page under Settings.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'LinkedIn Connection', 'linkedin-crosspost' ),
			__( 'LinkedIn Connection', 'linkedin-crosspost' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the app-credential settings.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			'lcp_client_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			'lcp_client_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_secret' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'lcp_app',
			__( 'LinkedIn app', 'linkedin-crosspost' ),
			array( __CLASS__, 'section_app' ),
			self::PAGE
		);
		add_settings_field(
			'lcp_client_id',
			__( 'Client ID', 'linkedin-crosspost' ),
			array( __CLASS__, 'field_client_id' ),
			self::PAGE,
			'lcp_app'
		);
		add_settings_field(
			'lcp_client_secret',
			__( 'Client Secret', 'linkedin-crosspost' ),
			array( __CLASS__, 'field_client_secret' ),
			self::PAGE,
			'lcp_app'
		);
	}

	/**
	 * Render the page: app credentials, then connection status and the
	 * connect/disconnect button.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'LinkedIn Connection', 'linkedin-crosspost' ) . '</h1>';

		self::status_notice();

		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button( __( 'Save app credentials', 'linkedin-crosspost' ) );
		echo '</form>';

		echo '<hr>';
		self::render_connection();

		echo '<hr>';
		self::render_debug_log();

		echo '</div>';
	}

	/**
	 * Plain-text debug log, read straight from disk — deliberately not an
	 * admin_notices box. Confirmed live that wp-admin notices from this
	 * plugin (including one with no gating at all beyond "is this
	 * wp-admin") never rendered here, while WP core's own "Post published."
	 * notice did — something about how this notice reaches the screen, not
	 * whether notices work at all. A plain textarea sidesteps that entirely.
	 * Temporary; remove this section once #5 is confirmed fixed.
	 *
	 * @return void
	 */
	private static function render_debug_log(): void {
		if ( ! class_exists( 'LCP_Publisher' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Debug log (temporary)', 'linkedin-crosspost' ) . '</h2>';
		$log = LCP_Publisher::read_log();
		if ( '' === $log ) {
			echo '<p>' . esc_html__( 'Empty — nothing logged yet. Try "Post to LinkedIn now" on a post, then reload this page.', 'linkedin-crosspost' ) . '</p>';
			return;
		}
		printf(
			'<textarea readonly rows="24" class="large-text code" style="font-family:monospace;white-space:pre;">%s</textarea>',
			esc_textarea( $log )
		);
	}

	/**
	 * One-time notice from an OAuth redirect (?lcp_status=...).
	 *
	 * @return void
	 */
	private static function status_notice(): void {
		$status = isset( $_GET['lcp_status'] ) ? sanitize_key( wp_unslash( $_GET['lcp_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $status ) {
			return;
		}

		$messages = array(
			'connected'         => array( 'success', __( 'Connected to LinkedIn.', 'linkedin-crosspost' ) ),
			'disconnected'      => array( 'info', __( 'Disconnected from LinkedIn.', 'linkedin-crosspost' ) ),
			'missing_client_id' => array( 'error', __( 'Save a Client ID first.', 'linkedin-crosspost' ) ),
			'bad_state'         => array( 'error', __( 'That authorization request expired or was invalid. Try connecting again.', 'linkedin-crosspost' ) ),
			'denied'            => array( 'error', __( 'LinkedIn authorization was cancelled or denied.', 'linkedin-crosspost' ) ),
			'no_code'           => array( 'error', __( 'LinkedIn did not send an authorization code.', 'linkedin-crosspost' ) ),
			'token_failed'      => array( 'error', __( 'Could not exchange the authorization code for a token. Check the Client ID/Secret and try again.', 'linkedin-crosspost' ) ),
		);

		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}

		list( $type, $text ) = $messages[ $status ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * App section intro: where to get the credentials, and the redirect URL
	 * to register on LinkedIn's side.
	 *
	 * @return void
	 */
	public static function section_app(): void {
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: %s: LinkedIn Developers URL. */
				__( 'From a LinkedIn app at <a href="%s" target="_blank" rel="noreferrer noopener">developer.linkedin.com</a> with the "Sign In with LinkedIn using OpenID Connect" and "Share on LinkedIn" products added.', 'linkedin-crosspost' ),
				'https://www.linkedin.com/developers/apps'
			),
			array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
		) . '</p>';
		printf(
			'<p>%s <code>%s</code></p>',
			esc_html__( 'Authorized redirect URL to register on that app:', 'linkedin-crosspost' ),
			esc_html( LCP_OAuth::redirect_uri() )
		);
	}

	/**
	 * Client ID field.
	 *
	 * @return void
	 */
	public static function field_client_id(): void {
		printf(
			'<input type="text" name="lcp_client_id" value="%s" class="regular-text">',
			esc_attr( (string) get_option( 'lcp_client_id', '' ) )
		);
	}

	/**
	 * Client Secret field — password input, never echoed back once saved.
	 *
	 * @return void
	 */
	public static function field_client_secret(): void {
		$has_value = '' !== (string) get_option( 'lcp_client_secret', '' );
		printf(
			'<input type="password" name="lcp_client_secret" value="" class="regular-text" autocomplete="off" placeholder="%s">',
			esc_attr( $has_value ? __( '(unchanged)', 'linkedin-crosspost' ) : '' )
		);
		if ( $has_value ) {
			echo '<p class="description">' . esc_html__( 'A secret is saved. Leave blank to keep it, or enter a new one to replace it.', 'linkedin-crosspost' ) . '</p>';
		}
	}

	/**
	 * Keep the existing secret when the field is submitted blank, so saving
	 * the Client ID alone doesn't wipe it.
	 *
	 * @param mixed $value Raw posted value.
	 * @return string
	 */
	public static function sanitize_secret( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( 'lcp_client_secret', '' );
		}
		return $value;
	}

	/**
	 * Connection status + connect/disconnect button.
	 *
	 * @return void
	 */
	private static function render_connection(): void {
		echo '<h2>' . esc_html__( 'Connection', 'linkedin-crosspost' ) . '</h2>';

		if ( LCP_OAuth::is_connected() ) {
			$name    = (string) get_option( 'lcp_member_name', '' );
			$expires = (int) get_option( 'lcp_token_expires', 0 );

			echo '<p>' . esc_html(
				'' !== $name
					? sprintf(
						/* translators: %s: connected member's LinkedIn display name. */
						__( 'Connected as %s.', 'linkedin-crosspost' ),
						$name
					)
					: __( 'Connected.', 'linkedin-crosspost' )
			) . '</p>';

			if ( $expires > 0 ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: formatted expiry date/time. */
							__( 'Access expires %s.', 'linkedin-crosspost' ),
							wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $expires )
						)
					)
				);
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lcp_oauth_disconnect' );
			echo '<input type="hidden" name="action" value="lcp_oauth_disconnect">';
			submit_button( __( 'Disconnect', 'linkedin-crosspost' ), 'secondary' );
			echo '</form>';
		} else {
			echo '<p>' . esc_html__( 'Not connected.', 'linkedin-crosspost' ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lcp_oauth_connect' );
			echo '<input type="hidden" name="action" value="lcp_oauth_connect">';
			submit_button( __( 'Connect with LinkedIn', 'linkedin-crosspost' ), 'primary' );
			echo '</form>';
		}
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		$own = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
				esc_html__( 'Settings', 'linkedin-crosspost' )
			),
		);
		return array_merge( $own, $links );
	}
}
