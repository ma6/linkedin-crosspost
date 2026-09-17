# AGENTS.md — LinkedIn Crosspost

A standalone WordPress plugin, structured like its sibling
[`linkedin-shares`](https://github.com/ma6/linkedin-shares) — that one
imports LinkedIn shares into WordPress; this one goes the other direction.
**No dependency on any theme** — keep it that way.

## Workflow

- **Issue-first.** Every change starts as a GitHub issue, written as a user
  story, before any code. Every commit that answers it names the ticket in its
  subject — `[#N] type(scope): summary` — and the commit that *finishes* the
  issue ends its body with `Closes #N`. Check `gh issue list` and `git log`
  before starting.
- **Work on `main`.** Commit straight to `main`; branch only when something
  genuinely cannot run there, and delete that branch (local **and** `origin`)
  the moment it lands. `git fetch` before every push, then rebase — linear
  history, no merge commits.
- **Commit trailer.** End every commit message with
  `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- **Version bump in the same commit.** When a change ships, bump `LCP_VERSION`
  (in `linkedin-crosspost.php`), the plugin header `Version:`, and
  `readme.txt` (`Stable tag` + a changelog entry) together.

## What it is

When you publish a post and haven't opted out, it posts to your **personal**
LinkedIn profile via the LinkedIn API about a minute later: the image
and short text you wrote for that
post in the editor, plus a link back to it. It does not generate the image
or the text — you write those yourself, the same way you already do by hand.

## Shape

```
linkedin-crosspost.php      bootstrap: constants, requires, plugins_loaded
inc/class-lcp-settings.php    Settings → LinkedIn Connection: app credentials, connection status  [done, #2]
inc/class-lcp-oauth.php       3-legged OAuth (w_member_social + openid), token storage, expiry warning  [done, #2]
inc/class-lcp-metabox.php     editor meta box: image, short text, "Share on LinkedIn" toggle  [done, #3]
inc/class-lcp-publisher.php   transition_post_status queues a 1-minute-out wp-cron job: /rest/images initializeUpload + /rest/posts  [done, #4]
```

Each file lands against its own issue — check `gh issue list` in this repo
for what's open and what order they're meant to land in.

## Non-negotiables

- **You write the content.** No AI-generated text or image — this plugin only
  ever posts what's in the meta box. Don't add a "generate for me" shortcut
  without being asked.
- **Opt-out, not opt-in.** The "Share on LinkedIn" toggle defaults **on** —
  every published post is cross-posted unless it was switched off before
  publishing.
- **Personal profile only.** Posts as the connected LinkedIn member, never an
  organization page, unless a future issue explicitly asks for that.
- **Token expiry is visible, never silent.** LinkedIn's personal-profile
  access tokens expire (~60 days) without a separate LinkedIn approval for
  long-lived refresh. An admin notice must warn before it lapses, and a
  publish that can't reach LinkedIn must say so somewhere Martin will see it —
  a cross-post that silently stops working defeats the point of "automatic".
- **No hard theme dependency.** wp-admin styling only; lean on core
  `.widefat`/`.button` so admin dark schemes keep working, same as
  `linkedin-shares`.
- **Escaping at every boundary**, **capabilities + nonces** on every
  settings/meta write — same standard as `linkedin-shares`.
- **Never crosspost inline on `transition_post_status`.** The block editor
  saves a "publish" as two separate requests — a REST call, then a classic
  form resubmit that's what actually writes this meta box's fields for *this*
  publish. `transition_post_status` fires during the first request, before
  the second one has run, so acting on the meta immediately there would post
  whatever was saved the *previous* time. `LCP_Publisher::schedule_crosspost()`
  only queues a wp-cron event a minute out; `run_crosspost()` re-reads
  everything fresh when it fires. Also covers a wp-cron-triggered scheduled
  publish for free — the extra minute costs nothing there.
- **No silent returns in `schedule_crosspost()`/`run_crosspost()`.**
  `wp_schedule_single_event()`'s return is checked (`$wp_error = true`, so a
  failure is a real `WP_Error`, not a bare `false`) and recorded as an error
  if it fails — a caching/security plugin filtering `pre_schedule_event`, or
  wp-cron disabled outright, are real possibilities on some hosts. Every
  early-return branch in `run_crosspost()` records why, except "already
  posted" (not a problem). Add a reason to any new early return.
- **`run_crosspost()`'s posting attempt runs inside `try { } catch
  ( \Throwable $e )`.** This runs unsupervised via wp-cron — a PHP error
  (not just an exception) anywhere in `post_to_linkedin()`/`upload_image()`
  would otherwise abort silently, leaving nothing recorded. `\Throwable`
  (not `\Exception`) catches PHP's Error hierarchy too (TypeError etc.),
  which is the realistic failure class for a runtime bug. Keep any new code
  in the posting path inside this same try block.
- **`handle_run_now()` ("Post to LinkedIn now") saves its own live copy of
  the meta box fields before posting — never trust the database alone.**
  `LCP_Metabox::persist()` exists for this; `metabox.js` syncs the current
  image/text/toggle into the button's own hidden fields right before it
  submits. Without this, the button posts whatever was last *saved*, not
  what's currently typed, if nothing triggered a separate save first.
- **That button's markup must never be wrapped in a `<form>`, and its
  JS-built form must submit via the native `.submit()`, never
  `$form.trigger('submit')`.** This meta box can be rendered inside the
  block editor's own `<form>` for classic meta-box compatibility; a form
  nested inside another is invalid HTML and the browser silently drops the
  inner one. The button carries its data as `data-*` attributes;
  `metabox.js` builds a standalone `<form>` on click, appends it to
  `document.body`, and calls `$form.get(0).submit()` — jQuery's
  `trigger('submit')` only fires the JS event, which the block editor's own
  document-level submit guard (against an accidental SPA navigation) swallows
  silently.
- **No server-side image cropping, and no inline cropper either.** Martin
  doesn't want a blind center crop; he wants to choose the crop himself. #6
  originally proposed an inline cropper matching the theme's homepage hero
  photo picker (`onygo/inc/customizer.php`'s `WP_Customize_Cropped_Image_
  Control`) — but that control only wires up inside `customize_register`;
  outside the Customizer (this meta box is a plain post-edit screen) you'd
  have to hand-roll its underlying `wp.media.controller.Cropper` JS
  yourself, undocumented, with no way for me to test it in a real browser.
  Decided against that risk after today's JS debugging. Instead:
  `LCP_Metabox::render()` checks the chosen image's stored dimensions and,
  if it isn't square, shows a hint linking straight to that attachment's own
  "Edit Image" screen (WordPress's existing crop tool) — `metabox.js`
  updates the same hint live when a new image is picked, using
  `attachment.width`/`.height` from the picker's own selection. The image
  still uploads to LinkedIn exactly as chosen, unmodified.
- **Use LinkedIn's `/rest/images` + `/rest/posts` API, never `/v2/assets` +
  `/v2/ugcPosts`** (LinkedIn's own docs: "The Images API replaces the Assets
  API" — learn.microsoft.com/en-us/linkedin/marketing/community-management/
  shares/images-api). The `/rest/*` pair needs a `LinkedIn-Version: YYYYMM`
  header (`API_VERSION` constant — bump occasionally; LinkedIn sunsets old
  monikers) and a flat body shape (`content.media.id` with a bare
  `urn:li:image:...`), unlike the old pair's nested
  `specificContent.com.linkedin.ugc.ShareContent` /
  `urn:li:digitalmediaAsset:...`. The image-upload PUT (to the URL from
  `initializeUpload`) also needs an explicit `Content-Type` header set to
  the file's real mime type — without it LinkedIn rejects it with a bare
  HTTP 400 (an HTML page, no JSON detail). Re-check that docs page before
  touching this file again; don't assume either version from memory.
- **`admin_notices` output from this plugin does not render on onygo.org,
  full stop** — confirmed by elimination (an unconditional notice with zero
  gating never appeared, while WP core's own "Post published." notice did,
  ruling out both site-wide notice suppression and a stale opcache). The
  cause was never identified and isn't worth chasing. **Never rely on
  `admin_notices` for anything Martin needs to see** — surface it inside the
  "LinkedIn Crosspost" meta box (`LCP_Metabox::render_status()`) or directly
  in `LCP_Settings::render()`'s own output instead; both are plain
  `add_meta_box()`/page-render output, confirmed reliable.
- **LinkedIn's duplicate-content detection is real** and will reject
  near-identical test posts with HTTP 422 "Duplicate post is detected" — not
  a bug, LinkedIn compares against the member's recent posts. Use genuinely
  different text per live test.

## Before calling a change done

1. `php -l` clean on every file.
2. Whatever the issue touched, actually exercise it in wp-admin (OAuth
   connect/disconnect, meta box save, a real publish) — don't just eyeball the
   diff.
3. Any lasting decision is written down in the same commit — start a
   `DECISIONS.md` if the reasoning outgrows the commit body.
