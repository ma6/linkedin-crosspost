<?php
/**
 * Plugin Name:       LinkedIn Crosspost
 * Plugin URI:        https://github.com/ma6/linkedin-crosspost
 * Description:       Publish a LinkedIn post automatically when a blog post goes live. Uses the image and short text you write for it in the editor, plus a link back to the post — posted to your personal LinkedIn profile via the LinkedIn API.
 * Version:           0.10.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Martin Gude
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linkedin-crosspost
 * Update URI:        false
 *
 * Self-contained by design — no dependency on any theme, so the whole
 * `plugins/linkedin-crosspost/` folder can be lifted into its own repository
 * unchanged, same as its sibling `linkedin-shares`.
 *
 * @package LinkedInCrosspost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCP_VERSION', '0.10.2' );
define( 'LCP_FILE', __FILE__ );
define( 'LCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LCP_URL', plugin_dir_url( __FILE__ ) );

require_once LCP_DIR . 'inc/class-lcp-oauth.php';
require_once LCP_DIR . 'inc/class-lcp-settings.php';
require_once LCP_DIR . 'inc/class-lcp-metabox.php';
require_once LCP_DIR . 'inc/class-lcp-publisher.php';

add_action(
	'plugins_loaded',
	static function () {
		LCP_OAuth::init();
		LCP_Settings::init();
		LCP_Metabox::init();
		LCP_Publisher::init();
	}
);
