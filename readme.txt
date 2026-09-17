=== LinkedIn Crosspost ===
Contributors: martingude
Tested up to: 7.1
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a LinkedIn post automatically when a blog post goes live.

== Description ==

Cross-posts a blog post to your personal LinkedIn profile the moment you
publish it: the square image and short text you wrote for it in the editor's
meta box, plus a link back to the post. Nothing is generated for you — same
content you'd post by hand, just posted automatically instead.

* **Opt-out, not opt-in.** "Share on LinkedIn" defaults on for every post;
  switch it off before publishing to skip one.
* **Personal profile.** Posts as the connected LinkedIn member via a one-time
  OAuth connection (Settings → LinkedIn Connection).
* **Nothing generated for you.** The image and text are whatever you put in
  the meta box.

Self-contained: no dependency on any theme. Sibling of
[linkedin-shares](https://github.com/ma6/linkedin-shares), which imports
LinkedIn's data export the other direction.

== Stored data ==

Site options: `lcp_client_id`, `lcp_client_secret` (your LinkedIn Developer
App credentials), `lcp_access_token`, `lcp_token_expires`, `lcp_member_sub`,
`lcp_member_name` (the OAuth connection) — deleting the plugin removes all
six. Per post: `_lcp_image_id`, `_lcp_text`, `_lcp_share_enabled` — real
content, left in place when the plugin is deleted, same as `linkedin-shares`
leaves its imported drafts alone.

== Known limits ==

* Personal-profile access tokens from LinkedIn expire (~60 days) without a
  separate LinkedIn approval for long-lived refresh; reconnecting periodically
  is required and the plugin warns in wp-admin before the token lapses.
* Posts to a personal profile only — no organization page support.
* No publish-time posting yet (issue #4) — the meta box saves the fields, but
  nothing is sent to LinkedIn until the publisher ships.

== Changelog ==

= 0.3.0 =
* Editor meta box on posts ("LinkedIn Crosspost"): pick or upload a square
  image, write the short text to post, and a "Share on LinkedIn" toggle that
  defaults on (opt-out).

= 0.2.0 =
* Settings → LinkedIn Connection: enter your LinkedIn app's Client ID/Secret,
  connect via OAuth (OpenID Connect + Share on LinkedIn), see the connected
  member and token expiry, disconnect. Admin notice warns before the token
  lapses.

= 0.1.0 =
* Repository scaffold. No functionality yet.
