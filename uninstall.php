<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the app credentials and the stored OAuth connection. No meta box
 * fields exist yet — update this as those are added.
 *
 * @package LinkedInCrosspost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'lcp_client_id', 'lcp_client_secret', 'lcp_access_token', 'lcp_token_expires', 'lcp_member_sub', 'lcp_member_name' ) as $option ) {
	delete_option( $option );
}
