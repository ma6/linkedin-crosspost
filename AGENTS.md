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
(center-cropped to a square automatically) and short text you wrote for that
post in the editor, plus a link back to it. It does not generate the image
or the text — you write those yourself, the same way you already do by hand.

## Shape

```
linkedin-crosspost.php      bootstrap: constants, requires, plugins_loaded
inc/class-lcp-settings.php    Settings → LinkedIn Connection: app credentials, connection status  [done, #2]
inc/class-lcp-oauth.php       3-legged OAuth (w_member_social + openid), token storage, expiry warning  [done, #2]
inc/class-lcp-metabox.php     editor meta box: square image, short text, "Share on LinkedIn" toggle  [done, #3]
inc/class-lcp-publisher.php   transition_post_status queues a 1-minute-out wp-cron job: square-crop + registerUpload + create the UGC post  [done, #4]
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

## Before calling a change done

1. `php -l` clean on every file.
2. Whatever the issue touched, actually exercise it in wp-admin (OAuth
   connect/disconnect, meta box save, a real publish) — don't just eyeball the
   diff.
3. Any lasting decision is written down in the same commit — start a
   `DECISIONS.md` if the reasoning outgrows the commit body.
