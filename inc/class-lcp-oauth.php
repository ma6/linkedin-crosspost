<?php
/**
 * LinkedIn OAuth: connect, callback, disconnect.
 *
 * @package LinkedInCrosspost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three-legged OAuth against a personal LinkedIn profile.
 */
final class LCP_OAuth {

	const AUTHORIZE_URL = 'https://www.linkedin.com/oauth/v2/authorization';
	const TOKEN_URL      = 'https://www.linkedin.com/oauth/v2/accessToken';
	const USERINFO_URL   = 'https://api.linkedin.com/v2/userinfo';
	const SCOPE          = 'openid profile w_member_social';
	const STATE_ACTION   = 'lcp_oauth_state';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_lcp_oauth_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_lcp_oauth_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_lcp_oauth_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * The redirect URI registered on the LinkedIn app. Must match exactly —
	 * changing the route means re-registering it there too.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=lcp_oauth_callback' );
	}

	/**
	 * Whether a token is currently stored.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return '' !== (string) get_option( 'lcp_access_token', '' );
	}

	/**
	 * Step 1 — send the admin to LinkedIn's consent screen.
	 *
	 * @return void
	 */
	public static function handle_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'linkedin-crosspost' ) );
		}
		check_admin_referer( 'lcp_oauth_connect' );

		$client_id = (string) get_option( 'lcp_client_id', '' );
		if ( '' === $client_id ) {
			wp_safe_redirect( self::settings_url( 'missing_client_id' ) );
			exit;
		}

		$args = array(
			'response_type' => 'code',
			'client_id'     => $client_id,
			'redirect_uri'  => self::redirect_uri(),
			'state'         => wp_create_nonce( self::STATE_ACTION ),
			'scope'         => self::SCOPE,
		);

		// Deliberate external redirect to LinkedIn — the URL is built entirely
		// from our own constants and stored option, not from request input, so
		// wp_safe_redirect()'s same-host restriction doesn't apply here.
		wp_redirect( self::AUTHORIZE_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Step 2 — LinkedIn redirects back here with a code (or an error).
	 *
	 * @return void
	 */
	public static function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'linkedin-crosspost' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, self::STATE_ACTION ) ) {
			wp_safe_redirect( self::settings_url( 'bad_state' ) );
			exit;
		}

		if ( isset( $_GET['error'] ) ) {
			wp_safe_redirect( self::settings_url( 'denied' ) );
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			wp_safe_redirect( self::settings_url( 'no_code' ) );
			exit;
		}

		$token = self::exchange_code( $code );
		if ( is_wp_error( $token ) ) {
			wp_safe_redirect( self::settings_url( 'token_failed' ) );
			exit;
		}

		$member = self::fetch_member( $token['access_token'] );

		update_option( 'lcp_access_token', $token['access_token'], false );
		update_option( 'lcp_token_expires', time() + $token['expires_in'], false );
		if ( ! is_wp_error( $member ) ) {
			update_option( 'lcp_member_sub', $member['sub'], false );
			update_option( 'lcp_member_name', $member['name'], false );
		}

		wp_safe_redirect( self::settings_url( 'connected' ) );
		exit;
	}

	/**
	 * Forget the stored token and member info. App credentials (Client
	 * ID/Secret) are kept so reconnecting doesn't need them re-entered.
	 *
	 * @return void
	 */
	public static function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'linkedin-crosspost' ) );
		}
		check_admin_referer( 'lcp_oauth_disconnect' );

		delete_option( 'lcp_access_token' );
		delete_option( 'lcp_token_expires' );
		delete_option( 'lcp_member_sub' );
		delete_option( 'lcp_member_name' );

		wp_safe_redirect( self::settings_url( 'disconnected' ) );
		exit;
	}

	/**
	 * Exchange an authorization code for an access token.
	 *
	 * @param string $code Authorization code from the callback.
	 * @return array{access_token:string,expires_in:int}|WP_Error
	 */
	private static function exchange_code( string $code ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => self::redirect_uri(),
					'client_id'     => (string) get_option( 'lcp_client_id', '' ),
					'client_secret' => (string) get_option( 'lcp_client_secret', '' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'lcp_token_failed', __( 'LinkedIn did not return an access token.', 'linkedin-crosspost' ) );
		}

		return array(
			'access_token' => (string) $body['access_token'],
			'expires_in'   => (int) ( $body['expires_in'] ?? 0 ),
		);
	}

	/**
	 * Look up the connected member's id and display name via OpenID Connect
	 * userinfo. `sub` is what a UGC post's author URN is built from
	 * (`urn:li:person:{sub}`) once the publisher (a later issue) needs it.
	 *
	 * @param string $access_token Bearer token.
	 * @return array{sub:string,name:string}|WP_Error
	 */
	private static function fetch_member( string $access_token ) {
		$response = wp_remote_get(
			self::USERINFO_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['sub'] ) ) {
			return new WP_Error( 'lcp_userinfo_failed', __( 'LinkedIn did not return member info.', 'linkedin-crosspost' ) );
		}

		return array(
			'sub'  => (string) $body['sub'],
			'name' => (string) ( $body['name'] ?? '' ),
		);
	}

	/**
	 * Settings URL carrying a one-time status for the admin notice.
	 *
	 * @param string $status Status token.
	 * @return string
	 */
	private static function settings_url( string $status ): string {
		return add_query_arg(
			'lcp_status',
			$status,
			admin_url( 'options-general.php?page=' . LCP_Settings::PAGE )
		);
	}

	/**
	 * Warn before the token lapses — a silent failure defeats the point of
	 * "automatic". Called directly from LCP_Settings::render_connection(),
	 * not hooked to admin_notices — this plugin's admin_notices output was
	 * confirmed, by elimination across several diagnostics, to never render
	 * on onygo.org, while the Settings page's own direct output always has.
	 *
	 * @return void
	 */
	public static function render_expiry_warning(): void {
		if ( ! self::is_connected() ) {
			return;
		}
		$expires = (int) get_option( 'lcp_token_expires', 0 );
		if ( 0 === $expires ) {
			return;
		}
		$remaining = $expires - time();
		if ( $remaining > 7 * DAY_IN_SECONDS ) {
			return;
		}

		$message = $remaining <= 0
			? __( 'Your LinkedIn connection has expired. Crossposting is paused until you reconnect.', 'linkedin-crosspost' )
			: sprintf(
				/* translators: %d: days until the LinkedIn token expires. */
				__( 'Your LinkedIn connection expires in %d day(s). Reconnect soon or crossposting will stop.', 'linkedin-crosspost' ),
				(int) ceil( $remaining / DAY_IN_SECONDS )
			);

		printf(
			'<p style="color:#d63638;">%s</p>',
			esc_html( $message )
		);
	}
}
