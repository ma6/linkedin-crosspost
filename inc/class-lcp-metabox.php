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
				'pickTitle'  => __( 'Choose an image', 'linkedin-crosspost' ),
				'pickButton' => __( 'Use this image', 'linkedin-crosspost' ),
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
			'<p><label><input type="checkbox" name="lcp_share_enabled" value="1" %s> %s</label></p>',
			checked( $enabled, true, false ),
			esc_html__( 'Share on LinkedIn when this post is published', 'linkedin-crosspost' )
		);

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
		echo '<p class="description">' . esc_html__( "Any size or shape — it's center-cropped to a square automatically when posted. The preview above shows roughly what survives that crop.", 'linkedin-crosspost' ) . '</p>';
		echo '</div>';

		echo '<p><strong>' . esc_html__( 'Short text', 'linkedin-crosspost' ) . '</strong></p>';
		printf(
			'<textarea name="lcp_text" rows="6" class="large-text" maxlength="%d" placeholder="%s">%s</textarea>',
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
	 * Save the three fields.
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

		$image_id = isset( $_POST['lcp_image_id'] ) ? absint( wp_unslash( $_POST['lcp_image_id'] ) ) : 0;
		if ( $image_id > 0 ) {
			update_post_meta( $post_id, '_lcp_image_id', $image_id );
		} else {
			delete_post_meta( $post_id, '_lcp_image_id' );
		}

		$text = isset( $_POST['lcp_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lcp_text'] ) ) : '';
		update_post_meta( $post_id, '_lcp_text', mb_substr( $text, 0, self::TEXT_LIMIT ) );

		update_post_meta( $post_id, '_lcp_share_enabled', isset( $_POST['lcp_share_enabled'] ) ? 1 : 0 );
	}
}
