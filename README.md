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

> **Status:** early scaffold — the OAuth connection, the meta box, and the
> publish-time API call are each tracked as their own issue in this repo and
> not built yet.

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

## Documentation

- [`AGENTS.md`](AGENTS.md) — how the plugin is built and the rules that govern
  changes. Canonical; `CLAUDE.md` points here.
- [`readme.txt`](readme.txt) — WordPress-format readme: option list, hooks,
  known limits, changelog.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — a one-person project: no support,
  issues opened by the maintainer only, no pull requests.

## Licence

GPL-2.0-or-later — see [`LICENSE`](LICENSE).
