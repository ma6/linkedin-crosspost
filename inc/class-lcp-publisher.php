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
 * Hooks the publish transition and talks to LinkedIn's Assets + UGC Posts
 * API.
 */
final class LCP_Publisher {

	const REGISTER_UPLOAD_URL = 'https://api.linkedin.com/v2/assets?action=registerUpload';
	const UGC_POSTS_URL       = 'https://api.linkedin.com/v2/ugcPosts';
	const RECIPE_FEEDSHARE    = 'urn:li:digitalmediaRecipe:feedshare-image';
	const CRON_HOOK           = 'lcp_crosspost_event';
	const DELAY               = MINUTE_IN_SECONDS;
	const RUN_NOW_ACTION      = 'lcp_run_now';

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
	}

	/**
	 * "Post to LinkedIn now" button handler — runs the crosspost immediately
	 * instead of waiting on the queued wp-cron event, and cancels that event
	 * so it doesn't also fire later (run_crosspost() is idempotent either
	 * way, this is just tidiness). Exists because wp-cron is page-load
	 * pseudo-cron and can silently never fire on some hosts (see #5).
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'linkedin-crosspost' ) );
		}
		check_admin_referer( self::RUN_NOW_ACTION . '_' . $post_id );

		$queued = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );
		if ( $queued ) {
			wp_unschedule_event( $queued, self::CRON_HOOK, array( $post_id ) );
		}

		self::run_crosspost( $post_id );

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

		wp_schedule_single_event( time() + self::DELAY, self::CRON_HOOK, array( $post->ID ) );
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
			return;
		}
		if ( ! LCP_Metabox::share_enabled( $post_id ) ) {
			return;
		}
		if ( get_post_meta( $post_id, '_lcp_linkedin_urn', true ) ) {
			return;
		}
		if ( ! LCP_OAuth::is_connected() ) {
			self::record_error( $post_id, __( 'LinkedIn is not connected.', 'linkedin-crosspost' ) );
			return;
		}

		$result = self::post_to_linkedin( $post );
		if ( is_wp_error( $result ) ) {
			self::record_error( $post_id, $result->get_error_message() );
			return;
		}

		update_post_meta( $post_id, '_lcp_linkedin_urn', $result );
		delete_post_meta( $post_id, '_lcp_crosspost_error' );
	}

	/**
	 * Build and send the UGC post.
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

		$image_id = (int) get_post_meta( $post->ID, '_lcp_image_id', true );
		$asset    = null;
		if ( $image_id > 0 ) {
			$asset = self::upload_image( $access_token, $author_urn, $image_id );
			if ( is_wp_error( $asset ) ) {
				return $asset;
			}
		}

		$text       = (string) get_post_meta( $post->ID, '_lcp_text', true );
		$link       = (string) get_permalink( $post );
		$commentary = trim( $text . ( '' !== $text ? "\n\n" : '' ) . $link );

		$share_content = array(
			'shareCommentary'    => array( 'text' => $commentary ),
			'shareMediaCategory' => $asset ? 'IMAGE' : 'NONE',
		);
		if ( $asset ) {
			$share_content['media'] = array(
				array(
					'status' => 'READY',
					'media'  => $asset,
				),
			);
		}

		$body = array(
			'author'          => $author_urn,
			'lifecycleState'  => 'PUBLISHED',
			'specificContent' => array(
				'com.linkedin.ugc.ShareContent' => $share_content,
			),
			'visibility'      => array(
				'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
			),
		);

		$response = wp_remote_post(
			self::UGC_POSTS_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization'             => 'Bearer ' . $access_token,
					'Content-Type'              => 'application/json',
					'X-Restli-Protocol-Version' => '2.0.0',
				),
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
	 * Register + upload the crosspost image — center-cropped to a square
	 * first, since LinkedIn expects a square feedshare image and the source
	 * can be any aspect ratio — as a LinkedIn digital media asset.
	 *
	 * @param string $access_token Bearer token.
	 * @param string $author_urn   `urn:li:person:{id}`.
	 * @param int    $image_id     Attachment ID.
	 * @return string|WP_Error Asset URN, or an error.
	 */
	private static function upload_image( string $access_token, string $author_urn, int $image_id ) {
		$path = get_attached_file( $image_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'lcp_image_missing', __( 'The crosspost image file could not be found on disk.', 'linkedin-crosspost' ) );
		}

		$square = self::square_crop( $path );
		if ( is_wp_error( $square ) ) {
			return $square;
		}

		$register = wp_remote_post(
			self::REGISTER_UPLOAD_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'registerUploadRequest' => array(
							'recipes'              => array( self::RECIPE_FEEDSHARE ),
							'owner'                => $author_urn,
							'serviceRelationships' => array(
								array(
									'relationshipType' => 'OWNER',
									'identifier'       => 'urn:li:userGeneratedContent',
								),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $register ) ) {
			if ( $square !== $path ) {
				wp_delete_file( $square );
			}
			return $register;
		}

		$reg_body   = json_decode( (string) wp_remote_retrieve_body( $register ), true );
		$upload_url = $reg_body['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? null;
		$asset_urn  = $reg_body['value']['asset'] ?? null;

		if ( ! is_string( $upload_url ) || ! is_string( $asset_urn ) ) {
			if ( $square !== $path ) {
				wp_delete_file( $square );
			}
			return new WP_Error( 'lcp_register_failed', __( 'LinkedIn did not return an upload URL for the image.', 'linkedin-crosspost' ) );
		}

		$bytes = file_get_contents( $square ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $square !== $path ) {
			wp_delete_file( $square );
		}
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

		return $asset_urn;
	}

	/**
	 * Center-crop an image to a square, saved to a temp file. The source
	 * attachment is left untouched; only this temp copy is uploaded to
	 * LinkedIn.
	 *
	 * @param string $path Source image path.
	 * @return string|WP_Error Path to a square file — the temp crop, or the
	 *                         original $path if it was already square — or
	 *                         an error.
	 */
	private static function square_crop( string $path ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$size = $editor->get_size();
		if ( ! $size || $size['width'] === $size['height'] ) {
			return $path;
		}

		$side = min( $size['width'], $size['height'] );
		$x    = (int) ( ( $size['width'] - $side ) / 2 );
		$y    = (int) ( ( $size['height'] - $side ) / 2 );

		$cropped = $editor->crop( $x, $y, $side, $side );
		if ( is_wp_error( $cropped ) ) {
			return $cropped;
		}

		$ext      = pathinfo( $path, PATHINFO_EXTENSION );
		$filetype = wp_check_filetype( $path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/jpeg';

		$tmp = wp_tempnam( 'lcp-square.' . ( $ext ?: 'jpg' ) );

		$saved = $editor->save( $tmp, $mime );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return (string) $saved['path'];
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
}
