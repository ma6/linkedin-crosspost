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
  publish (confirmed by testing: an image/text-filled post published live and
  came through with neither, because `transition_post_status` fires during
  the first request, before the second one has run). `LCP_Publisher` queues
  the real work a minute out via wp-cron instead and re-reads everything
  fresh when it fires — don't "simplify" that back to doing it inline, the
  bug will come back.
- **wp-cron isn't trustworthy alone.** A second live test still posted
  nothing, silently — Site Health showed no cron-specific issue, so the exact
  cause on onygo.org is still open, but page-load pseudo-cron on a cached
  site is exactly the kind of thing that fails without ever surfacing an
  error. `LCP_Metabox` shows whether/when a crosspost is actually queued, and
  a "Post to LinkedIn now" button (`LCP_Publisher::handle_run_now()`) always
  works independent of wp-cron — keep both whenever this area changes.
- **No silent returns in `schedule_crosspost()`/`run_crosspost()`.** A third
  live test queued nothing and showed no error either, meaning even the
  scheduling call itself may be getting blocked (a caching/security plugin
  filtering `pre_schedule_event`, most likely) with nothing to show for it.
  `wp_schedule_single_event()`'s return is now checked (pass `$wp_error =
  true` to get the real WP_Error instead of a bare `false`), and every
  early-return branch in `run_crosspost()` records why — the only exception
  is "already posted", which isn't a problem. If you add a new early return
  to either function, record a reason; don't let it go quiet again.
- **`run_crosspost()` and its callers never read live form state — only the
  database.** A fourth live test showed why this matters: "Post to LinkedIn
  now" posted an empty post despite the image/text fields visibly showing
  filled in, because nothing had triggered an actual editor save between
  typing and clicking the button, so the DB still held the old (empty)
  values — the button was never wrong, the assumption that "visibly filled
  in" means "saved" was. `LCP_Metabox::persist()` now exists so
  `handle_run_now()` can save the button's own live-synced copy of the
  fields (see `metabox.js`) right before posting. Any future "do it now"
  action needs the same treatment — never assume the DB already matches
  what's on screen.
- **Never wrap anything in this meta box in a `<form>`.** A fifth live test:
  once caching was genuinely ruled out, "Post to LinkedIn now" did *nothing*
  on click — no request at all. It was in its own `<form>`, and this meta
  box can be rendered inside the block editor's own `<form>` for classic
  meta-box compatibility; a form nested inside another is invalid HTML, and
  the browser silently drops the inner one, leaving a `type="submit"`
  button with nothing to submit to. Fixed by never emitting a `<form>` here
  at all — the button carries its data as `data-*` attributes, and
  `metabox.js` builds a standalone `<form>` on click, appended directly to
  `document.body`. If this box ever needs another button that posts
  somewhere, follow the same pattern, not a nested form.
- **Submit a JS-built form with the native `.submit()`, never
  `$form.trigger('submit')`.** A sixth live test: the standalone-form fix
  above went right back to doing nothing at all. `trigger('submit')` only
  fires jQuery's event — the block editor almost certainly has a
  document-level 'submit' listener guarding against an accidental full-page
  navigation out of its SPA, and that's exactly the kind of thing that would
  catch and cancel a triggered event. `$form.get(0).submit()` calls the
  native DOM method, which by spec doesn't dispatch a 'submit' event at all,
  so nothing gets a chance to intercept it.
- **`run_crosspost()`'s posting attempt runs inside `try { } catch
  ( \Throwable $e )`.** A seventh live test: the queued job ran (it left the
  schedule — "Not queued." replaced "Queued to post at…") but recorded
  neither success nor an error, meaning a PHP error most likely killed
  execution partway through silently — this runs via wp-cron, unsupervised,
  no error log visible. `\Throwable` (not just `\Exception`) is deliberate:
  it also catches PHP's Error hierarchy (TypeError and friends), which is
  the actual failure class a runtime bug would surface as. Any code added
  to the posting path (post_to_linkedin(), upload_image()) is covered by
  this same catch — don't bypass it by calling something risky from outside
  that try block.
- **No server-side image cropping.** There was a center-crop step
  (`square_crop()`, `WP_Image_Editor`) — removed. Martin doesn't want a
  blind center crop; he wants to choose the crop himself, and would rather
  do that in the Media Library's own "Edit Image" tool (or a future
  interactive crop step — see #6) than have this plugin guess. The image is
  uploaded to LinkedIn exactly as chosen, unmodified. Don't reintroduce
  automatic cropping without that issue's interactive picker.
- **Use LinkedIn's `/rest/images` + `/rest/posts` API, never `/v2/assets` +
  `/v2/ugcPosts`.** #4 was built against the v2 pair from memory, without
  checking current docs first — text-only posting happened to still work
  (confirmed live), but image posting silently produced no image and no
  error. LinkedIn's own docs say outright "The Images API replaces the
  Assets API" (learn.microsoft.com/en-us/linkedin/marketing/
  community-management/shares/images-api). The `/rest/*` pair needs a
  `LinkedIn-Version: YYYYMM` header the v2 pair never did (`API_VERSION`
  constant — bump it occasionally; LinkedIn sunsets old monikers), and an
  entirely different request/response shape (flat `content.media.id` with a
  bare `urn:li:image:...`, not the nested `specificContent.com.linkedin.
  ugc.ShareContent` structure with `urn:li:digitalmediaAsset:...`). Before
  touching this file again, re-check that page for what's current — don't
  assume either version from memory.
- **This plugin's `admin_notices` output does not render on onygo.org, full
  stop.** Confirmed by elimination across several diagnostics: an
  unconditional notice with zero gating (not even a screen check) never
  appeared, while WP core's own "Post published." notice did, right after —
  so it isn't notices being suppressed site-wide, and it isn't a stale
  opcache (a runtime-version notice confirmed the current code is what's
  executing). The actual cause was never identified and isn't worth
  chasing further. **Never rely on `admin_notices` for anything this
  plugin needs Martin to actually see** — surface it inside the "LinkedIn
  Crosspost" meta box itself instead (confirmed reliable: it's plain
  `add_meta_box()` output, not a separate hook), or in the plain-text debug
  log if it's diagnostic rather than user-facing.
- **LinkedIn's duplicate-content detection is real and will reject
  near-identical test posts with HTTP 422 "Duplicate post is detected".**
  Not a bug — LinkedIn compares new post content against the member's
  recent posts. Don't chase a 422 with that message as if it were another
  API-shape problem; it means exactly what it says. Use genuinely different
  text for each live test.
- **The image upload PUT (to the URL from `/rest/images?action=
  initializeUpload`) needs an explicit `Content-Type` header set to the
  file's real mime type.** Confirmed live via the debug log: without it,
  LinkedIn rejected the PUT with a bare HTTP 400 (an HTML page, not a JSON
  API error — no useful detail in the body). The older Assets API's own
  docs show a plain `curl --upload-file` with no Content-Type and that
  apparently works there, but the newer Images API's upload URL looks
  structurally different (`dms-uploads/sp/v2/...` vs the old
  `dms-uploads/{id}/...`) and doesn't behave the same way. Text-only
  posting (no image) was confirmed working end to end (HTTP 201) via the
  same debug log before this fix.

## Before calling a change done

1. `php -l` clean on every file.
2. Whatever the issue touched, actually exercise it in wp-admin (OAuth
   connect/disconnect, meta box save, a real publish) — don't just eyeball the
   diff.
3. Any lasting decision is written down in the same commit — start a
   `DECISIONS.md` if the reasoning outgrows the commit body.
