# LinkedIn Crosspost

A WordPress plugin that publishes a LinkedIn post automatically when one of
your blog posts goes live.

It uses the square image and short text you write for that post in the
editor's meta box, plus a link back to the post, and publishes it to your
personal LinkedIn profile via the LinkedIn API. It doesn't generate the image
or the text for you — same content you'd post by hand, just posted for you.
The "Share on LinkedIn" toggle defaults on; switch it off per-post to opt out.

This is the sibling of [`linkedin-shares`](https://github.com/ma6/linkedin-shares),
which imports LinkedIn's data export the other direction.

> **Status:** the OAuth connection and the editor meta box are built. The
> publish-time API call is still tracked as its own issue in this repo.

## Install

Download `linkedin-crosspost.zip` from the
[latest release](https://github.com/ma6/linkedin-crosspost/releases/latest)
once one exists, then **Plugins → Add New → Upload Plugin**. Requires
WordPress 6.5+ and PHP 8.0+.

To build the ZIP from a checkout instead:

```bash
git clone https://github.com/ma6/linkedin-crosspost.git
zip -r linkedin-crosspost.zip linkedin-crosspost -x '.git/*'
```

## Set up your own LinkedIn app

Every install brings its own LinkedIn Developer App — the plugin never ships
a shared Client ID/Secret (see `AGENTS.md`). Before connecting under
**Settings → LinkedIn Connection**:

1. Open [developer.linkedin.com/apps](https://www.linkedin.com/developers/apps)
   → **Create app**.
2. LinkedIn requires every app to be owned by a **LinkedIn Page**, even
   though this app only ever posts to a personal profile — the Page is just
   the app's administrative owner, unrelated to what it posts to later. If
   you don't already have one, click **"+ Create a new LinkedIn Page"** right
   there and make a minimal one (e.g. your blog's name) — nobody sees it
   otherwise. Fill in an app name and a square logo (`assets/app-logo.png` in
   this repo works as a placeholder), agree to the terms, **Create app**.
3. Under the app's **Products** tab, add **"Sign In with LinkedIn using
   OpenID Connect"** and **"Share on LinkedIn"**.
4. Under **Auth**, copy the **Client ID** and **Client Secret**, and add this
   site's callback under "Authorized redirect URLs" (Settings → LinkedIn
   Connection shows the exact URL once the plugin is active):
   ```
   https://<your-domain>/wp-admin/admin-post.php?action=lcp_oauth_callback
   ```
5. In WordPress, go to **Settings → LinkedIn Connection**, paste the Client
   ID/Secret, save, then **Connect with LinkedIn**.

## Documentation

- [`AGENTS.md`](AGENTS.md) — how the plugin is built and the rules that govern
  changes. Canonical; `CLAUDE.md` points here.
- [`readme.txt`](readme.txt) — WordPress-format readme: option list, hooks,
  known limits, changelog.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — a one-person project: no support,
  issues opened by the maintainer only, no pull requests.

## Licence

GPL-2.0-or-later — see [`LICENSE`](LICENSE).
