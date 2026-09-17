=== LinkedIn Crosspost ===
Contributors: martingude
Tested up to: 7.1
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
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

Not yet applicable — no settings or meta exist until the OAuth connection and
meta box ship. This section will list every option and meta key once they do.

== Known limits ==

* Personal-profile access tokens from LinkedIn expire (~60 days) without a
  separate LinkedIn approval for long-lived refresh; reconnecting periodically
  is required and the plugin warns before the token lapses.
* Posts to a personal profile only — no organization page support.

== Changelog ==

= 0.1.0 =
* Repository scaffold. No functionality yet.
