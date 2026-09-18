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
 * replaces the Assets API", and /v2/ugcPosts is the same generation. Every
 * call here needs a `LinkedIn-Version: YYYYMM` header, which the old pair
 * never required.
 */
final class LCP_Publisher {

	const IMAGES_API_URL = 'https://api.linkedin.com/rest/images?action=initializeUpload';
	const POSTS_API_URL  = 'https://api.linkedin.com/rest/posts';
	const API_VERSION    = '202608'; // YYYYMM — August 2026. Bump occasionally; LinkedIn sunsets old monikers.
	const CRON_HOOK      = 'lcp_crosspost_event';
	const DELAY          = MINUTE_IN_SECONDS;
	const RUN_NOW_ACTION = 'lcp_run_now';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'schedule_crosspost' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_crosspost' ) );
		add_action( 'admin_post_' . self::RUN_NOW_ACTION, array( __CLASS__, 'handle_run_now' ) );
	}

	/**
	 * "Post to LinkedIn now" button handler — runs the crosspost immediately
	 * instead of waiting on the queued wp-cron event, and cancels that event
	 * so it doesn't also fire later (run_crosspost() is idempotent either
	 * way, this is just tidiness). Exists because wp-cron is page-load
	 * pseudo-cron and can silently never fire on some hosts.
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
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'linkedin-crosspost' ) );
		}
		check_admin_referer( self::RUN_NOW_ACTION . '_' . $post_id );

		LCP_Metabox::persist(
			$post_id,
			isset( $_POST['lcp_image_id'] ) ? (string) wp_unslash( $_POST['lcp_image_id'] ) : '0',
			isset( $_POST['lcp_text'] ) ? (string) wp_unslash( $_POST['lcp_text'] ) : '',
			isset( $_POST['lcp_share_enabled'] ) && '1' === wp_unslash( $_POST['lcp_share_enabled'] )
		);

		$queued = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );
		if ( $queued ) {
			wp_unschedule_event( $queued, self::CRON_HOOK, array( $post_id ) );
		}

		// A fresh manual post supersedes any earlier failure.
		delete_post_meta( $post_id, '_lcp_crosspost_error' );

		self::run_crosspost( $post_id );

		wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
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
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::record_error( $post_id, __( 'Skipped: the post was no longer published when the crosspost ran.', 'linkedin-crosspost' ) );
			return;
		}
		if ( ! LCP_Metabox::share_enabled( $post_id ) ) {
			self::record_error( $post_id, __( 'Skipped: sharing was turned off when the crosspost ran.', 'linkedin-crosspost' ) );
			return;
		}
		if ( get_post_meta( $post_id, '_lcp_linkedin_urn', true ) ) {
			return; // Already posted — nothing wrong, nothing to report.
		}
		if ( ! LCP_OAuth::is_connected() ) {
			self::record_error( $post_id, __( 'LinkedIn is not connected.', 'linkedin-crosspost' ) );
			return;
		}

		// This runs unsupervised via wp-cron — nobody is watching a PHP
		// error log. A PHP error anywhere below (post_to_linkedin(),
		// upload_image(), ...) would otherwise abort silently: the event
		// still leaves the schedule (wp-cron marks it done regardless), but
		// neither the success path nor record_error() below it ever runs.
		// \Throwable catches PHP's Error hierarchy (TypeError etc.) as well
		// as exceptions, so this is the actual safety net, not just style.
		try {
			$result = self::post_to_linkedin( $post );
		} catch ( \Throwable $e ) {
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
		$link       = LCP_Settings::tracked_link( (string) get_permalink( $post ) );
		$commentary = trim( $text . ( '' !== $text ? "\n\n" : '' ) . $link );

		$body = array(
			'author'                    => $author_urn,
			'commentary'                => $commentary,
			'visibility'                => 'PUBLIC',
			'distribution'              => array(
				'feedDistribution'                => 'MAIN_FEED',
				'targetEntities'                   => array(),
				'thirdPartyDistributionChannels'   => array(),
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

		$filetype = wp_check_filetype( $path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

		$upload = wp_remote_request(
			$upload_url,
			array(
				'method'  => 'PUT',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => $mime,
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
	 * silently doing nothing. Shown in the "LinkedIn Crosspost" meta box
	 * itself (LCP_Metabox::render_status()) — not as an admin_notice; this
	 * plugin's admin_notices output was confirmed, by elimination across
	 * several diagnostics, to never render on onygo.org, while the meta
	 * box's own output always has.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function record_error( int $post_id, string $message ): void {
		update_post_meta( $post_id, '_lcp_crosspost_error', $message );
	}
}
