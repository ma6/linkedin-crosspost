<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the app credentials, the stored OAuth connection, and any pending
 * crosspost cron jobs. Per-post meta (_lcp_image_id, _lcp_text, etc.) is real
 * content and is left in place, same as `linkedin-shares` leaves its
 * imported drafts alone.
 *
 * @package LinkedInCrosspost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'lcp_client_id', 'lcp_client_secret', 'lcp_access_token', 'lcp_token_expires', 'lcp_member_sub', 'lcp_member_name' ) as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'lcp_crosspost_event' );
