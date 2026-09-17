=== LinkedIn Crosspost ===
Contributors: martingude
Tested up to: 7.1
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a LinkedIn post automatically when a blog post goes live.

== Description ==

Cross-posts a blog post to your personal LinkedIn profile about a minute
after you publish it: the image and short text you wrote for it in the
editor's meta box (the image is center-cropped to a square automatically —
pick any source image), plus a link back to the post. Nothing is generated
for you — same content you'd post by hand, just posted automatically
instead.

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
six. Per post: `_lcp_image_id`, `_lcp_text`, `_lcp_share_enabled`,
`_lcp_linkedin_urn` (set once crossposted — blocks a second post on a
republish), `_lcp_crosspost_error` (cleared on the next success) — real
content, left in place when the plugin is deleted, same as `linkedin-shares`
leaves its imported drafts alone.

== Known limits ==

* Personal-profile access tokens from LinkedIn expire (~60 days) without a
  separate LinkedIn approval for long-lived refresh; reconnecting periodically
  is required and the plugin warns in wp-admin before the token lapses.
* Posts to a personal profile only — no organization page support.
* A crosspost happens once per post, the first time it transitions to
  Published (immediate or scheduled). Editing an already-published post again
  does not re-post it; there is no manual "post again" button.
* A failure (not connected, expired token, LinkedIn API error) shows as a
  notice on that post's edit screen — it does not retry automatically.
* The crosspost is queued about a minute after publishing, not instantly —
  the block editor saves this plugin's meta box in a second request just
  after the publish request itself, so acting immediately would post
  whatever the box held the *previous* time, not what's in it now. The delay
  also relies on wp-cron actually running, same as a scheduled publish does.
* The square crop is always centered; there's no way to choose which part of
  a non-square image is kept.

== Changelog ==

= 0.7.0 =
* A third live test on onygo.org still queued nothing, with no error notice
  either — meaning it wasn't reaching a place that could report a problem.
  The scheduling call's own return value is now checked and surfaced as an
  error notice immediately if it fails, and every other reason the delayed
  job might not post (no longer published, sharing turned off in the
  meantime) is now recorded too, instead of returning silently. This should
  finally pin down what onygo.org is actually doing to it.

= 0.6.0 =
* The meta box now shows crosspost status on a published post: already
  posted, queued for a specific time, or not queued — and a "Post to
  LinkedIn now" button that runs it immediately instead of waiting on
  wp-cron. Also doubles as the retry that a failed crosspost didn't have.

= 0.5.0 =
* Fixed: publishing from the block editor could crosspost with an empty
  image/text, because the meta box's second save request lands after the
  publish transition fires. The crosspost is now queued a minute out via
  wp-cron instead of running inline, so it always reads the meta that was
  actually saved for this publish.
* The crosspost image is now center-cropped to a square automatically before
  upload (any source aspect ratio); the meta box preview shows the crop.

= 0.4.0 =
* Publishing a post (immediate or scheduled, with the toggle on and LinkedIn
  connected) now creates the LinkedIn UGC post: the meta-box image (uploaded
  via LinkedIn's Assets API) and short text, plus a link back to the post.
  Never posts twice for the same post. A failure surfaces as a notice on the
  post's edit screen instead of failing silently.

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
