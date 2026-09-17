<?php
/**
 * Editor meta box: crosspost image, short text, and the share toggle.
 *
 * @package LinkedInCrosspost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "LinkedIn Crosspost" meta box on the post editor.
 */
final class LCP_Metabox {

	const POST_TYPE    = 'post';
	const NONCE_ACTION = 'lcp_metabox_save';
	const NONCE_FIELD  = 'lcp_metabox_nonce';
	const TEXT_LIMIT   = 3000; // LinkedIn's own UGC post character limit.

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Register the box on the post editor, in the sidebar next to Featured
	 * Image.
	 *
	 * @return void
	 */
	public static function add_box(): void {
		add_meta_box(
			'lcp_metabox',
			__( 'LinkedIn Crosspost', 'linkedin-crosspost' ),
			array( __CLASS__, 'render' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Enqueue the media-picker script, only on this post type's editor.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'lcp-metabox',
			LCP_URL . 'assets/metabox.js',
			array( 'jquery' ),
			LCP_VERSION,
			true
		);
		wp_localize_script(
			'lcp-metabox',
			'lcpMetabox',
			array(
				'pickTitle'   => __( 'Choose an image', 'linkedin-crosspost' ),
				'pickButton'  => __( 'Use this image', 'linkedin-crosspost' ),
				'editUrlBase' => admin_url( 'post.php?action=edit&post=' ),
			)
		);
	}

	/**
	 * Whether sharing is enabled for a post. Unset meta defaults to on —
	 * opt-out, not opt-in (see AGENTS.md non-negotiables).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function share_enabled( int $post_id ): bool {
		if ( ! metadata_exists( 'post', $post_id, '_lcp_share_enabled' ) ) {
			return true;
		}
		return (bool) get_post_meta( $post_id, '_lcp_share_enabled', true );
	}

	/**
	 * Render the box: toggle, image picker, short-text field.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$image_id = (int) get_post_meta( $post->ID, '_lcp_image_id', true );
		$text     = (string) get_post_meta( $post->ID, '_lcp_text', true );
		$enabled  = self::share_enabled( $post->ID );
		$preview  = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

		printf(
			'<p><label><input type="checkbox" name="lcp_share_enabled" id="lcp-share-enabled" value="1" %s> %s</label></p>',
			checked( $enabled, true, false ),
			esc_html__( 'Share on LinkedIn when this post is published', 'linkedin-crosspost' )
		);

		if ( $enabled ) {
			self::render_status( $post );
		}

		$is_square = true;
		if ( $image_id ) {
			$meta = wp_get_attachment_metadata( $image_id );
			if ( $meta && isset( $meta['width'], $meta['height'] ) && (int) $meta['width'] !== (int) $meta['height'] ) {
				$is_square = false;
			}
		}

		echo '<p><strong>' . esc_html__( 'Image', 'linkedin-crosspost' ) . '</strong></p>';
		echo '<div class="lcp-image-picker">';
		printf(
			'<img id="lcp-image-preview" src="%s" style="width:200px;height:200px;object-fit:cover;display:block;margin-bottom:6px;border:1px solid #444;%s" alt="">',
			esc_url( (string) $preview ),
			$preview ? '' : 'display:none;'
		);
		printf( '<input type="hidden" name="lcp_image_id" id="lcp-image-id" value="%d">', $image_id );
		echo '<p>';
		printf(
			'<button type="button" class="button" id="lcp-image-choose">%s</button> ',
			esc_html__( 'Choose Image', 'linkedin-crosspost' )
		);
		printf(
			'<button type="button" class="button" id="lcp-image-remove" style="%s">%s</button>',
			$image_id ? '' : 'display:none;',
			esc_html__( 'Remove', 'linkedin-crosspost' )
		);
		echo '</p>';
		// Not an inline cropper — deliberately reuses WordPress's own image
		// editor (the "Edit Image" tool on the attachment's edit screen)
		// rather than reimplementing cropping UI. Toggled + re-pointed by
		// metabox.js whenever a new image is picked; the values here are
		// just the correct starting state for whatever's already selected.
		printf(
			'<p id="lcp-crop-hint" style="color:#d63638;%s">%s <a href="%s" id="lcp-crop-hint-link" target="_blank" rel="noopener">%s</a></p>',
			( $image_id && ! $is_square ) ? '' : 'display:none;',
			esc_html__( "This image isn't square.", 'linkedin-crosspost' ),
			esc_url( admin_url( 'post.php?post=' . $image_id . '&action=edit' ) ),
			esc_html__( 'Edit Image to crop it ↗', 'linkedin-crosspost' )
		);
		echo '<p class="description">' . esc_html__( 'Posted exactly as chosen — nothing is cropped for you. Pick a square image yourself for the best result on LinkedIn; the preview above is a square crop of whatever you choose, just to help you judge that, not a preview of what gets sent.', 'linkedin-crosspost' ) . '</p>';
		echo '</div>';

		echo '<p><strong>' . esc_html__( 'Short text', 'linkedin-crosspost' ) . '</strong></p>';
		printf(
			'<textarea name="lcp_text" id="lcp-text" rows="6" class="large-text" maxlength="%d" placeholder="%s">%s</textarea>',
			(int) self::TEXT_LIMIT,
			esc_attr__( "What you'd post on LinkedIn about this…", 'linkedin-crosspost' ),
			esc_textarea( $text )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: LinkedIn's character limit for a post. */
					__( 'Posted as-is, with a link back to this post. LinkedIn allows up to %d characters.', 'linkedin-crosspost' ),
					self::TEXT_LIMIT
				)
			)
		);
	}

	/**
	 * Crosspost status: already posted, queued for a specific time, or not
	 * queued. A "Post to LinkedIn now" button bypasses the wp-cron wait —
	 * see #5.
	 *
	 * Deliberately does *not* gate this on $post->post_status: the WP_Post
	 * this callback receives can lag the real status by one render in the
	 * block editor's meta-box-compat pipeline (confirmed live — the sidebar
	 * said "Published" while this showed nothing, because the post_status
	 * check below was silently returning). Always show the status; a draft
	 * legitimately reads "Not queued." until it's actually published, and
	 * the button click re-fetches everything fresh anyway.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	private static function render_status( WP_Post $post ): void {
		if ( get_post_meta( $post->ID, '_lcp_linkedin_urn', true ) ) {
			echo '<p>' . esc_html__( 'Posted to LinkedIn.', 'linkedin-crosspost' ) . '</p>';
			return;
		}

		// Shown here, not as a separate admin_notice — confirmed live that
		// this plugin's admin_notices callbacks never render on this site
		// (even an unconditional one with no gating at all), while this
		// meta box's own output always has. Whatever the cause, this is the
		// pathway proven to actually reach the screen.
		$error = get_post_meta( $post->ID, '_lcp_crosspost_error', true );
		if ( $error ) {
			printf(
				'<p style="color:#d63638;">%s %s</p>',
				esc_html__( 'LinkedIn crosspost failed:', 'linkedin-crosspost' ),
				esc_html( (string) $error )
			);
		}

		$queued = wp_next_scheduled( LCP_Publisher::CRON_HOOK, array( $post->ID ) );
		if ( $queued ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: formatted queued time. */
						__( 'Queued to post at %s.', 'linkedin-crosspost' ),
						wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $queued )
					)
				)
			);
		} else {
			echo '<p class="description">' . esc_html__( 'Not queued.', 'linkedin-crosspost' ) . '</p>';
		}

		// No <form> element here at all. This meta box can end up rendered
		// inside the block editor's own <form> for classic meta-box compat
		// — confirmed live: a <form> here made the button do *nothing* on
		// click once caching was actually ruled out, because a form nested
		// inside another is invalid HTML and the browser silently drops it,
		// leaving the button with no form to submit. metabox.js instead
		// builds a standalone <form> on click, appended directly to <body>,
		// carrying live copies of the image/text/toggle (not whatever was
		// last saved — see the AGENTS.md entry on this).
		printf(
			'<button type="button" class="button button-secondary" id="lcp-run-now-button" data-post-id="%d" data-nonce="%s" data-url="%s">%s</button>',
			$post->ID,
			esc_attr( wp_create_nonce( LCP_Publisher::RUN_NOW_ACTION . '_' . $post->ID ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Post to LinkedIn now', 'linkedin-crosspost' )
		);
	}

	/**
	 * Save the three fields from the editor's own save request.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::persist(
			$post_id,
			isset( $_POST['lcp_image_id'] ) ? (string) wp_unslash( $_POST['lcp_image_id'] ) : '0',
			isset( $_POST['lcp_text'] ) ? (string) wp_unslash( $_POST['lcp_text'] ) : '',
			isset( $_POST['lcp_share_enabled'] ) // A real checkbox: present only when checked.
		);
	}

	/**
	 * Sanitize and store the three fields. Shared by save() (the editor's
	 * own save, where the checkbox's mere presence means "checked") and
	 * LCP_Publisher::handle_run_now() (whose own copies of these fields —
	 * synced live by metabox.js right before submit — carry an explicit
	 * '1'/'0' instead, since it isn't a real checkbox).
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $image_id_raw  Raw attachment ID.
	 * @param string $text_raw      Raw short-text value.
	 * @param bool   $share_enabled Whether sharing is on.
	 * @return void
	 */
	public static function persist( int $post_id, string $image_id_raw, string $text_raw, bool $share_enabled ): void {
		$image_id = absint( $image_id_raw );
		if ( $image_id > 0 ) {
			update_post_meta( $post_id, '_lcp_image_id', $image_id );
		} else {
			delete_post_meta( $post_id, '_lcp_image_id' );
		}

		$text = sanitize_textarea_field( $text_raw );
		update_post_meta( $post_id, '_lcp_text', mb_substr( $text, 0, self::TEXT_LIMIT ) );

		update_post_meta( $post_id, '_lcp_share_enabled', $share_enabled ? 1 : 0 );
	}
}
