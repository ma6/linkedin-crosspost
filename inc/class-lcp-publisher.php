<?php
/**
 * Publish-time crosspost: create a LinkedIn UGC post when a post is
 * published, using the meta box's image and text.
 *
 * @package LinkedInCrosspost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the publish transition and talks to LinkedIn's Images + Posts API.
 *
 * Not the older /v2/assets + /v2/ugcPosts pair — LinkedIn's own docs
 * (learn.microsoft.com/.../shares/images-api) say outright "The Images API
 * replaces the Assets API", and /v2/ugcPosts is the same generation. Found
 * live: the old pair silently failed to post an image (no image API docs
 * were checked before building #4 — an assumption that turned out wrong).
 * Every call here needs a `LinkedIn-Version: YYYYMM` header, which the old
 * pair never required.
 */
final class LCP_Publisher {

	const IMAGES_API_URL  = 'https://api.linkedin.com/rest/images?action=initializeUpload';
	const POSTS_API_URL   = 'https://api.linkedin.com/rest/posts';
	const API_VERSION     = '202608'; // YYYYMM — August 2026. Bump occasionally; LinkedIn sunsets old monikers.
	const CRON_HOOK       = 'lcp_crosspost_event';
	const DELAY           = MINUTE_IN_SECONDS;
	const RUN_NOW_ACTION  = 'lcp_run_now';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'schedule_crosspost' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_crosspost' ) );
		add_action( 'admin_post_' . self::RUN_NOW_ACTION, array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'error_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'run_now_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'debug_trace_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'runtime_check_notice' ) );
	}

	/**
	 * Unconditional, every-admin-page proof that this exact file version is
	 * the code actually executing right now — not just the file on disk.
	 * `Version:` in the Plugins list is read straight from the file header
	 * via a raw file read, bypassing PHP entirely; it can show the right
	 * number even while PHP's opcode cache is still serving a stale
	 * compiled version of the actual logic. If this notice is genuinely
	 * never visible anywhere in wp-admin, that's the opcache theory
	 * confirmed. Temporary — remove once #5 is confirmed fixed.
	 *
	 * @return void
	 */
	public static function runtime_check_notice(): void {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( 'LCP runtime check: LCP_Publisher is executing version ' . LCP_VERSION . ' right now (' . self::API_VERSION . ').' )
		);
	}

	/**
	 * "Post to LinkedIn now" button handler — runs the crosspost immediately
	 * instead of waiting on the queued wp-cron event, and cancels that event
	 * so it doesn't also fire later (run_crosspost() is idempotent either
	 * way, this is just tidiness). Exists because wp-cron is page-load
	 * pseudo-cron and can silently never fire on some hosts (see #5).
	 *
	 * Also saves the meta box's image/text/toggle from this button's own
	 * form fields before posting — confirmed live that without this, the
	 * button posted whatever was last *saved*, not what was currently
	 * typed, if nothing had triggered an actual save in between.
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		self::checkpoint( $post_id, 'handle_run_now: entered, post_id=' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			self::checkpoint( $post_id, 'handle_run_now: capability check FAILED' );
			wp_die( esc_html__( 'You are not allowed to do that.', 'linkedin-crosspost' ) );
		}
		check_admin_referer( self::RUN_NOW_ACTION . '_' . $post_id );
		self::checkpoint( $post_id, 'handle_run_now: capability + nonce OK' );

		LCP_Metabox::persist(
			$post_id,
			isset( $_POST['lcp_image_id'] ) ? (string) wp_unslash( $_POST['lcp_image_id'] ) : '0',
			isset( $_POST['lcp_text'] ) ? (string) wp_unslash( $_POST['lcp_text'] ) : '',
			isset( $_POST['lcp_share_enabled'] ) && '1' === wp_unslash( $_POST['lcp_share_enabled'] )
		);
		self::checkpoint( $post_id, 'handle_run_now: persist() done' );

		$queued = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );
		if ( $queued ) {
			wp_unschedule_event( $queued, self::CRON_HOOK, array( $post_id ) );
		}

		// A fresh manual post supersedes any earlier failure.
		delete_post_meta( $post_id, '_lcp_crosspost_error' );

		self::checkpoint( $post_id, 'handle_run_now: calling run_crosspost()' );
		self::run_crosspost( $post_id );
		self::checkpoint( $post_id, 'handle_run_now: run_crosspost() returned' );

		$status    = get_post_meta( $post_id, '_lcp_linkedin_urn', true ) ? 'posted' : 'failed';
		$edit_url  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		wp_safe_redirect( add_query_arg( 'lcp_run_status', $status, $edit_url ) );
		exit;
	}

	/**
	 * React to a status change by queuing the actual crosspost a minute out
	 * — never doing it inline here.
	 *
	 * The block editor saves a "publish" in two separate requests: a REST
	 * call first, then (because this plugin's meta box predates the
	 * block-editor meta APIs) a second classic form submission that's what
	 * actually writes _lcp_image_id/_lcp_text for *this* publish. This hook
	 * fires during the first request, before that second one has happened —
	 * acting on the meta immediately here would crosspost whatever was saved
	 * the *previous* time, not what's in the box right now. Queuing a
	 * one-off wp-cron event and reading the meta fresh when it fires sidesteps
	 * the exact request-ordering rather than depending on it. This also
	 * covers a wp-cron-triggered scheduled publish just as well — the delay
	 * only adds a minute, and that meta was never in question there.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public static function schedule_crosspost( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( LCP_Metabox::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( get_post_meta( $post->ID, '_lcp_linkedin_urn', true ) ) {
			return; // Already crossposted — never double-post on a republish.
		}
		if ( wp_next_scheduled( self::CRON_HOOK, array( $post->ID ) ) ) {
			return; // Already queued.
		}

		$scheduled = wp_schedule_single_event( time() + self::DELAY, self::CRON_HOOK, array( $post->ID ), true );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			// wp_schedule_single_event() failing is itself the failure worth
			// surfacing — something on this host (a caching/security plugin
			// filtering pre_schedule_event, wp-cron disabled with no real
			// replacement, ...) is blocking scheduling outright, and that's
			// very different from "scheduled fine, wp-cron just hasn't run
			// yet" — don't let it look identical to that in the UI.
			self::record_error(
				$post->ID,
				is_wp_error( $scheduled )
					? $scheduled->get_error_message()
					: __( 'wp_schedule_single_event() returned false — something on this site is blocking wp-cron scheduling.', 'linkedin-crosspost' )
			);
		}
	}

	/**
	 * The actual crosspost, run a minute after the publish transition —
	 * re-checks everything fresh, since state (the toggle, the connection,
	 * whether it's still published) may have changed in that minute.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function run_crosspost( int $post_id ): void {
		self::checkpoint( $post_id, 'run_crosspost: entered' );
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::checkpoint( $post_id, 'run_crosspost: STOPPED — not published (status=' . ( $post ? $post->post_status : 'no post object' ) . ')' );
			self::record_error( $post_id, __( 'Skipped: the post was no longer published when the crosspost ran.', 'linkedin-crosspost' ) );
			return;
		}
		if ( ! LCP_Metabox::share_enabled( $post_id ) ) {
			self::checkpoint( $post_id, 'run_crosspost: STOPPED — sharing disabled' );
			self::record_error( $post_id, __( 'Skipped: sharing was turned off when the crosspost ran.', 'linkedin-crosspost' ) );
			return;
		}
		if ( get_post_meta( $post_id, '_lcp_linkedin_urn', true ) ) {
			self::checkpoint( $post_id, 'run_crosspost: STOPPED — _lcp_linkedin_urn already set to "' . get_post_meta( $post_id, '_lcp_linkedin_urn', true ) . '"' );
			return; // Already posted — nothing wrong, nothing to report.
		}
		if ( ! LCP_OAuth::is_connected() ) {
			self::checkpoint( $post_id, 'run_crosspost: STOPPED — LCP_OAuth::is_connected() is false' );
			self::record_error( $post_id, __( 'LinkedIn is not connected.', 'linkedin-crosspost' ) );
			return;
		}
		self::checkpoint( $post_id, 'run_crosspost: all guards passed, about to call post_to_linkedin()' );

		// Capture every outbound call to LinkedIn via core's own
		// http_api_debug hook and store it unconditionally, regardless of
		// what this code decides happened — repeated live failures showed
		// neither success nor a recorded error, meaning this plugin's own
		// success/failure interpretation can't be trusted blind right now.
		// This bypasses that entirely: raw HTTP code + a body snippet for
		// every LinkedIn call this attempt made, so what actually happened
		// is visible even if the logic below is still missing a case.
		$trace   = array();
		$capture = static function ( $response, $type, $class, $args, $url ) use ( &$trace ) {
			if ( ! is_string( $url ) || ! str_contains( $url, 'linkedin.com' ) ) {
				return;
			}
			$trace[] = array(
				'url'  => $url,
				'code' => is_wp_error( $response )
					? 'WP_Error: ' . $response->get_error_message()
					: (string) wp_remote_retrieve_response_code( $response ),
				'body' => is_wp_error( $response )
					? ''
					: substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 300 ),
			);
		};
		add_action( 'http_api_debug', $capture, 10, 5 );

		// This runs unsupervised via wp-cron — nobody is watching a PHP
		// error log. A PHP error anywhere below (post_to_linkedin(),
		// upload_image(), square_crop()'s WP_Image_Editor calls, ...) would
		// otherwise abort silently: the event still leaves the schedule
		// (wp-cron marks it done regardless), but neither the success path
		// nor record_error() below it ever runs, so the meta box is left
		// showing "Not queued." forever with no explanation. \Throwable
		// catches PHP's Error hierarchy (TypeError etc.) as well as
		// exceptions, so this is the actual safety net, not just style.
		try {
			$result = self::post_to_linkedin( $post );
		} catch ( \Throwable $e ) {
			remove_action( 'http_api_debug', $capture, 10 );
			self::record_debug_trace( $post_id, $trace );
			self::record_error(
				$post_id,
				sprintf(
					/* translators: %s: the underlying PHP error message. */
					__( 'Unexpected PHP error while posting: %s', 'linkedin-crosspost' ),
					$e->getMessage()
				)
			);
			return;
		}
		remove_action( 'http_api_debug', $capture, 10 );
		self::record_debug_trace( $post_id, $trace );

		if ( is_wp_error( $result ) ) {
			self::record_error( $post_id, $result->get_error_message() );
			return;
		}

		update_post_meta( $post_id, '_lcp_linkedin_urn', $result );
		delete_post_meta( $post_id, '_lcp_crosspost_error' );
	}

	/**
	 * Build and send the post.
	 *
	 * @param WP_Post $post The post being published.
	 * @return string|WP_Error The created share's URN, or an error.
	 */
	private static function post_to_linkedin( WP_Post $post ) {
		$access_token = (string) get_option( 'lcp_access_token', '' );
		$author_sub   = (string) get_option( 'lcp_member_sub', '' );
		if ( '' === $access_token || '' === $author_sub ) {
			return new WP_Error( 'lcp_not_connected', __( 'LinkedIn is not connected.', 'linkedin-crosspost' ) );
		}
		$author_urn = 'urn:li:person:' . $author_sub;

		$image_id  = (int) get_post_meta( $post->ID, '_lcp_image_id', true );
		$image_urn = null;
		if ( $image_id > 0 ) {
			$image_urn = self::upload_image( $access_token, $author_urn, $image_id );
			if ( is_wp_error( $image_urn ) ) {
				return $image_urn;
			}
		}

		$text       = (string) get_post_meta( $post->ID, '_lcp_text', true );
		$link       = (string) get_permalink( $post );
		$commentary = trim( $text . ( '' !== $text ? "\n\n" : '' ) . $link );

		$body = array(
			'author'                    => $author_urn,
			'commentary'                => $commentary,
			'visibility'                => 'PUBLIC',
			'distribution'              => array(
				'feedDistribution'               => 'MAIN_FEED',
				'targetEntities'                  => array(),
				'thirdPartyDistributionChannels'  => array(),
			),
			'lifecycleState'            => 'PUBLISHED',
			'isReshareDisabledByAuthor' => false,
		);
		if ( $image_urn ) {
			$body['content'] = array(
				'media' => array(
					'id' => $image_urn,
				),
			);
		}

		$response = wp_remote_post(
			self::POSTS_API_URL,
			array(
				'timeout' => 30,
				'headers' => self::api_headers( $access_token ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'lcp_post_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body. */
					__( 'LinkedIn rejected the post (HTTP %1$d): %2$s', 'linkedin-crosspost' ),
					$code,
					wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) )
				)
			);
		}

		$urn = wp_remote_retrieve_header( $response, 'x-restli-id' );
		return is_string( $urn ) && '' !== $urn ? $urn : 'posted';
	}

	/**
	 * Register + upload the crosspost image, exactly as chosen — no
	 * cropping. Martin picks the image himself (see AGENTS.md
	 * non-negotiables); LinkedIn will letterbox/crop a non-square image on
	 * its own end for display, same as any other non-square LinkedIn post.
	 *
	 * @param string $access_token Bearer token.
	 * @param string $author_urn   `urn:li:person:{id}`.
	 * @param int    $image_id     Attachment ID.
	 * @return string|WP_Error `urn:li:image:...`, or an error.
	 */
	private static function upload_image( string $access_token, string $author_urn, int $image_id ) {
		$path = get_attached_file( $image_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'lcp_image_missing', __( 'The crosspost image file could not be found on disk.', 'linkedin-crosspost' ) );
		}

		$register = wp_remote_post(
			self::IMAGES_API_URL,
			array(
				'timeout' => 30,
				'headers' => self::api_headers( $access_token ),
				'body'    => wp_json_encode(
					array(
						'initializeUploadRequest' => array(
							'owner' => $author_urn,
						),
					)
				),
			)
		);

		if ( is_wp_error( $register ) ) {
			return $register;
		}

		$register_code = (int) wp_remote_retrieve_response_code( $register );
		if ( $register_code < 200 || $register_code >= 300 ) {
			return new WP_Error(
				'lcp_register_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body. */
					__( 'LinkedIn rejected the image upload request (HTTP %1$d): %2$s', 'linkedin-crosspost' ),
					$register_code,
					wp_strip_all_tags( (string) wp_remote_retrieve_body( $register ) )
				)
			);
		}

		$reg_body   = json_decode( (string) wp_remote_retrieve_body( $register ), true );
		$upload_url = $reg_body['value']['uploadUrl'] ?? null;
		$image_urn  = $reg_body['value']['image'] ?? null;

		if ( ! is_string( $upload_url ) || ! is_string( $image_urn ) ) {
			return new WP_Error( 'lcp_register_failed', __( 'LinkedIn did not return an upload URL for the image.', 'linkedin-crosspost' ) );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return new WP_Error( 'lcp_image_read_failed', __( 'The crosspost image could not be read.', 'linkedin-crosspost' ) );
		}

		$upload = wp_remote_request(
			$upload_url,
			array(
				'method'  => 'PUT',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'body'    => $bytes,
			)
		);

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$upload_code = (int) wp_remote_retrieve_response_code( $upload );
		if ( $upload_code < 200 || $upload_code >= 300 ) {
			return new WP_Error(
				'lcp_upload_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Uploading the image to LinkedIn failed (HTTP %d).', 'linkedin-crosspost' ),
					$upload_code
				)
			);
		}

		return $image_urn;
	}

	/**
	 * Headers every /rest/* call needs.
	 *
	 * @param string $access_token Bearer token.
	 * @return array<string, string>
	 */
	private static function api_headers( string $access_token ): array {
		return array(
			'Authorization'             => 'Bearer ' . $access_token,
			'Content-Type'              => 'application/json',
			'X-Restli-Protocol-Version' => '2.0.0',
			'LinkedIn-Version'          => self::API_VERSION,
		);
	}

	/**
	 * Remember a failure where Martin will see it, instead of the publish
	 * silently doing nothing.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function record_error( int $post_id, string $message ): void {
		update_post_meta( $post_id, '_lcp_crosspost_error', $message );
	}

	/**
	 * Store the raw HTTP trace of the most recent attempt — every LinkedIn
	 * call made, its status code, and a body snippet — unconditionally,
	 * regardless of whether this code thinks it succeeded or failed. A
	 * temporary diagnostic while tracking down repeated live failures that
	 * showed neither a success nor a recorded error.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $trace   Rows of ['url' => ..., 'code' => ..., 'body' => ...].
	 * @return void
	 */
	private static function record_debug_trace( int $post_id, array $trace ): void {
		update_post_meta( $post_id, '_lcp_debug_trace', wp_json_encode( $trace ) );
	}

	/**
	 * Append one step to a per-post execution breadcrumb trail — temporary,
	 * to see exactly how far a run got before going quiet. Keeps the last
	 * 30 steps.
	 *
	 * @param int    $post_id Post ID (0 is fine — still records against
	 *                        that as a marker something ran before a valid
	 *                        post ID was even known).
	 * @param string $label   What just happened.
	 * @return void
	 */
	private static function checkpoint( int $post_id, string $label ): void {
		$steps   = get_post_meta( $post_id, '_lcp_debug_steps', true );
		$steps   = is_array( $steps ) ? $steps : array();
		$steps[] = gmdate( 'H:i:s' ) . ' — ' . $label;
		$steps   = array_slice( $steps, -30 );
		update_post_meta( $post_id, '_lcp_debug_steps', $steps );
	}

	/**
	 * Show the most recent crosspost failure on that post's edit screen,
	 * until it's cleared by a successful crosspost.
	 *
	 * @return void
	 */
	public static function error_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$error = get_post_meta( $post_id, '_lcp_crosspost_error', true );
		if ( ! $error ) {
			return;
		}
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
			esc_html__( 'LinkedIn crosspost failed:', 'linkedin-crosspost' ),
			esc_html( (string) $error )
		);
	}

	/**
	 * One-time success notice after "Post to LinkedIn now" (?lcp_run_status=
	 * posted). The "failed" case needs no separate notice — error_notice()
	 * already shows the message run_crosspost() just recorded.
	 *
	 * @return void
	 */
	public static function run_now_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$status = isset( $_GET['lcp_run_status'] ) ? sanitize_key( wp_unslash( $_GET['lcp_run_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'posted' !== $status ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Posted to LinkedIn.', 'linkedin-crosspost' )
		);
	}

	/**
	 * Show the execution breadcrumb trail and the raw HTTP trace of the
	 * most recent crosspost attempt — unconditionally, whether or not
	 * either one has anything in it, so an empty result is as visible as a
	 * populated one. Temporary, for tracking down live failures that show
	 * neither success nor a recorded error. Remove once #5 is confirmed
	 * fixed.
	 *
	 * @return void
	 */
	public static function debug_trace_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'LinkedIn crosspost debug (temporary):', 'linkedin-crosspost' ) . '</strong></p>';

		$steps = get_post_meta( $post_id, '_lcp_debug_steps', true );
		echo '<p>' . esc_html__( 'Execution steps:', 'linkedin-crosspost' ) . '</p>';
		if ( is_array( $steps ) && ! empty( $steps ) ) {
			echo '<ol>';
			foreach ( $steps as $step ) {
				printf( '<li><code>%s</code></li>', esc_html( (string) $step ) );
			}
			echo '</ol>';
		} else {
			echo '<p>' . esc_html__( '(none recorded for this post — handle_run_now()/run_crosspost() never ran at all)', 'linkedin-crosspost' ) . '</p>';
		}

		$raw   = get_post_meta( $post_id, '_lcp_debug_trace', true );
		$trace = $raw ? json_decode( (string) $raw, true ) : null;
		echo '<p>' . esc_html__( 'LinkedIn HTTP calls:', 'linkedin-crosspost' ) . '</p>';
		if ( is_array( $trace ) && ! empty( $trace ) ) {
			foreach ( $trace as $row ) {
				printf(
					'<p><code>%s</code><br>%s: %s</p>',
					esc_html( (string) ( $row['url'] ?? '' ) ),
					esc_html__( 'Status', 'linkedin-crosspost' ),
					esc_html( (string) ( $row['code'] ?? '' ) . ' ' . ( $row['body'] ?? '' ) )
				);
			}
		} else {
			echo '<p>' . esc_html__( '(none — either no attempt ran far enough to make one, or none were made)', 'linkedin-crosspost' ) . '</p>';
		}

		echo '</div>';
	}
}
