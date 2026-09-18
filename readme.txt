=== LinkedIn Crosspost ===
Contributors: martingude
Tested up to: 7.1
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.13.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a LinkedIn post automatically when a blog post goes live.

== Description ==

Cross-posts a blog post to your personal LinkedIn profile about a minute
after you publish it: the image and short text you wrote for it in the
editor's meta box, exactly as chosen — nothing is cropped or otherwise
altered — plus a link back to the post. Nothing is generated for you —
same content you'd post by hand, just posted automatically instead.

* **Opt-out, not opt-in.** "Share on LinkedIn" defaults on for every post;
  switch it off before publishing to skip one.
* **Personal profile.** Posts as the connected LinkedIn member via a one-time
  OAuth connection (Settings → LinkedIn Connection).
* **Nothing generated for you.** The image and text are whatever you put in
  the meta box.
* **Optional link tracking.** Append UTM parameters (Google Analytics) or
  your own custom parameters (Matomo, etc.) to the shared link, site-wide.

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
  does not re-post it automatically; "Post to LinkedIn now" in the meta box
  triggers one manually.
* A failure (not connected, expired token, LinkedIn API error) shows
  directly inside the "LinkedIn Crosspost" meta box on that post's edit
  screen — it does not retry automatically.
* The crosspost is queued about a minute after publishing, not instantly —
  the block editor saves this plugin's meta box in a second request just
  after the publish request itself, so acting immediately would post
  whatever the box held the *previous* time, not what's in it now. The delay
  also relies on wp-cron actually running, same as a scheduled publish does.
* The image is uploaded exactly as chosen — no cropping. Pick (or prepare,
  e.g. via the Media Library's own "Edit Image" tool) a square image
  yourself for the best result on LinkedIn.

== Changelog ==

= 0.13.1 =
* Fix: the 0.13.0 UTM defaults (linkedin/social/crosspost) never actually
  showed up — neither pre-filled in the Settings form nor applied during a
  real wp-cron crosspost — because they were read with
  `get_option( $option, '' )`. WordPress only honors `register_setting()`'s
  registered default when `get_option()` is called with no default argument
  at all, and even then only within a request where `admin_init` already
  fired (wp-cron never fires it). Caught by testing on a local site rather
  than just eyeballing the diff. Now resolved by hand in
  `LCP_Settings::option_or_default()`, independent of request context.

= 0.13.0 =
* New: Settings → LinkedIn Connection has a "Link tracking" section —
  utm_source/utm_medium/utm_campaign fields (pre-filled with linkedin/
  social/crosspost, editable) plus a free-text field for anything else
  (Matomo's own pk_*/mtm_* param names, utm_content, etc.). Applied
  site-wide to the link on every crossposted post; blank fields are
  omitted, so an unconfigured site behaves exactly as before. Closes #7.

= 0.12.0 =
* Picking a non-square image now shows a hint with a link straight to that
  image's "Edit Image" tool (the Media Library's own crop tool) instead of
  silently uploading it as-is. Deliberately not an inline cropper — see
  AGENTS.md for why (the one WordPress ships, `WP_Customize_Cropped_Image_
  Control`, is Customizer-only and hand-rolling its underlying JS was judged
  not worth the risk after today's debugging).

= 0.11.0 =
* Confirmed live, with an image: the full flow now works end to end
  (image upload + post creation both return success from LinkedIn). #5 is
  closed.
* Cleanup: removed all the temporary debug tooling (debug log file,
  execution breadcrumb trail, runtime-version notice, raw HTTP trace
  notice) that was added while tracking the bug down.
* Also moved the token-expiry warning off `admin_notices` (confirmed
  useless on this site, same as the crosspost error notices already fixed
  in 0.10.7) onto Settings → LinkedIn Connection's own page output, where
  it's guaranteed to actually be seen.

= 0.10.8 =
* The debug log showed exactly why an image post failed: the actual file
  upload PUT (after a successful initializeUpload) was rejected with a bare
  HTTP 400 — an HTML page, not a JSON API error, and no Content-Type header
  was being sent at all. Now sends the image's real mime type on that PUT.
  Text-only posting is confirmed working end to end via the same debug log.

= 0.10.7 =
* Found it, via the new debug log: the plugin works correctly end to end —
  LinkedIn was rejecting a specific test post with HTTP 422 "Duplicate post
  is detected" (too many near-identical test posts today), which this
  plugin was correctly detecting and recording the whole time. The real
  remaining issue was that this plugin's admin_notices never render on this
  site at all (confirmed: even an unconditional one didn't show, while WP
  core's own notices do). Fixed properly, not just for debugging: a
  crosspost failure now shows directly inside the "LinkedIn Crosspost" meta
  box itself, which — unlike the separate admin_notices — is confirmed to
  always render.

= 0.10.6 =
* Temporary diagnostic: wp-admin notices from this plugin never rendered on
  this site — not even an unconditional one with no gating at all — while
  WP core's own "Post published." notice was confirmed working. Rather than
  keep guessing why, the debug output (execution checkpoints + LinkedIn
  HTTP trace) now also writes to a plain-text log file in the uploads
  directory, shown in a read-only textarea on Settings → LinkedIn
  Connection — sidesteps the notice-rendering question entirely. (The log
  lives in uploads/, not the plugin's own folder — that folder wasn't
  writable at runtime, likely the same hardening Really Simple Security
  applies on many hosts.)

= 0.10.4 =
* Temporary diagnostic: the runtime check (0.10.3) confirmed current code
  is genuinely executing — ruling out a stale opcache — yet the debug trace
  from 0.10.2 still never appeared, meaning run_crosspost() itself was
  never being reached, or was returning before recording anything. The
  meta box now shows a full execution breadcrumb trail — every significant
  step handle_run_now() and run_crosspost() take, in order, with the exact
  guard that stopped it if one did — unconditionally, so a completely empty
  trail is just as visible as a full one. Remove once #5 is confirmed fixed.

= 0.10.3 =
* Temporary diagnostic: an unconditional notice on every wp-admin page
  proving which exact version is actually executing at runtime — the
  Plugins list's version number is read straight from the file header via
  a raw file read, bypassing PHP, and can look current even while PHP's
  opcode cache is still serving stale compiled logic from an earlier
  version. Added because the 0.10.2 debug trace never appeared at all, not
  even as an empty trace, which a stale opcache would fully explain.
  Remove once #5 is confirmed fixed.

= 0.10.2 =
* Temporary diagnostic: the meta box now shows a raw HTTP trace of the last
  crosspost attempt (every LinkedIn call made, status code, body snippet),
  unconditionally — regardless of what this plugin's own success/failure
  logic decides happened. Added because a live post with no image kept
  showing neither success nor a recorded error even after the 0.10.1 fix,
  and this plugin's own reporting couldn't be trusted blind any further.
  Remove once #5 is confirmed fixed.

= 0.10.1 =
* Fixed: the 0.10.0 API migration used the wrong LinkedIn-Version header —
  202508 (August 2025) instead of 202608 (August 2026) — which LinkedIn
  almost certainly rejects outright, breaking every post, including the
  text-only ones that worked before that migration. Corrected to 202608.

= 0.10.0 =
* Found the actual reason images never posted, with no error: this plugin
  was built against LinkedIn's older /v2/assets + /v2/ugcPosts API, which
  LinkedIn's own docs say is replaced by /rest/images + /rest/posts — text
  posting happened to still work on the old pair, image posting silently
  didn't. Rewritten against the current API: a new required
  `LinkedIn-Version` header, and a different request/response shape
  throughout. Image posting is expected to actually work now.

= 0.9.1 =
* Removed the automatic center-crop. Martin wants to choose the crop
  himself (e.g. via the Media Library's own "Edit Image" tool) rather than
  have this plugin blindly center-crop — and it also removed one whole
  class of PHP errors (WP_Image_Editor) from the posting path while the
  real cause of the silent-failure bug (#5) was still being tracked down.
  An interactive crop step is tracked separately as #6. The image now
  uploads to LinkedIn byte-for-byte as chosen.

= 0.9.0 =
* A queued crosspost that had a PHP error partway through (anywhere in the
  image upload/crop or the LinkedIn API call) could leave the meta box
  showing "Not queued." forever with nothing to explain why — the event
  runs via wp-cron with nobody watching a PHP error log, so it vanished
  silently. The whole posting attempt now runs inside a try/catch that
  records any PHP error as a visible notice instead.

= 0.8.2 =
* Fixed: "Post to LinkedIn now" went back to doing nothing at all after the
  0.8.1 fix — its dynamically-built form was submitted via jQuery's
  `trigger('submit')`, which only fires a JS event and stops there if
  anything on the page (almost certainly the block editor's own guard
  against an accidental full-page navigation) calls preventDefault() on it.
  Now calls the form's native submit() method, which doesn't dispatch an
  event at all and so can't be intercepted.

= 0.8.1 =
* Fixed: "Post to LinkedIn now" did nothing at all once browser/page caching
  was ruled out. It was wrapped in its own `<form>`, nested inside the block
  editor's own form for classic meta-box compatibility — nested forms are
  invalid HTML, and the browser silently drops the inner one, leaving the
  button with no form to submit. It's no longer inside any `<form>`; a
  click now builds a standalone form via JS, appended directly to `<body>`.

= 0.8.0 =
* Fixed: "Post to LinkedIn now" could post an empty post (just the link)
  despite the image/text fields showing filled in — it was posting whatever
  was last *saved* to the database, not what was currently typed, and
  nothing had triggered an actual save in between. The button now saves its
  own live copy of the image/text/toggle right before posting, so it always
  posts exactly what's in the box at the moment you click it.

= 0.7.1 =
* Fixed: the crosspost status in the meta box (queued/not queued/posted)
  could show nothing at all, even on a published post, because it was gated
  on the post_status the block editor's meta-box-compat rendering passed in
  — which can lag the real status by one render. The status display no
  longer depends on it.

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
