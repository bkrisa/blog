# OpenBlog

A minimal, self-hosted, open-source blog platform built with PHP and SQLite.

Built by [@bkrisa12](https://x.com/bkrisa12)

No user accounts, no login form, no password database to secure. Publishing
is done through a small dashboard that is protected at the network level —
by default via [Tailscale](https://tailscale.com), so only your own devices
can ever reach it.

## Features

- Posts with title, content (WYSIWYG editor with image upload), tags, and a custom URL slug
- Tags with a many-to-many relationship to posts
- Automatic excerpt generation for previews and meta descriptions
- Automatic Open Graph / Twitter Card image (first image in the post, falling back to your site logo)
- Automatic image compression to WebP on upload
- Automatic heading IDs for anchor links inside posts
- RSS 2.0 feed
- XML sitemap
- `llms.txt` for AI/LLM crawlers, generated live from your current posts
- No database server required — everything lives in a single SQLite file
- No build step, no dependencies to install — plain PHP

## Requirements

- PHP 8.1+
- PHP extensions: `pdo_sqlite`, `mbstring`, `gd` (optional, enables automatic WebP conversion)
- A web server (Nginx or Apache) with PHP-FPM
- [Tailscale](https://tailscale.com) installed on your server, if you use the default dashboard protection method (see below)

## Installation

### 1. Clone the repository

Clone it anywhere on your server — it does **not** need to live inside your
existing web root:

```bash
git clone https://github.com/bkrisa/blog.git .
```

The repository looks like this:

```
/opt/blog/
├── app/              <- this is the ONLY folder your web server should ever see
│   ├── assets/
│   ├── dashboard/
│   ├── includes/
│   ├── tag/
│   ├── index.php
│   ├── post.php
│   ├── rss.php
│   ├── sitemap.php
│   ├── llms.txt.php
│   └── settings.json
├── setup_db.php
├── tailscale_auth.php
├── update.sh
└── README.md
```

### 2. Point your web server at `app/`, and only `app/`

This is the single most important security rule of this project: **your web
server's document root (or `alias`) must point at the `app/` subfolder, never
at the repository root.** If it points at the repository root, files like
`setup_db.php` become publicly downloadable.

See [`nginx.conf.example`](./nginx.conf.example) for a working example, both
for installing at your domain root (`example.com`) and in a subfolder
(`example.com/blog`).

### 3. Create your `.env` file

Copy `.env.example` to `.env` in the repository root, and set the path to
your SQLite database file:

```
DB_PATH=/opt/openblog/data/database.db
```

The `data/` folder is created automatically on first run if it doesn't exist.

### 4. Create your `settings.json`

Copy the example below into `app/settings.json` and adjust it to your site:

```json
{
  "site": {
    "name": "Your blog's name",
    "base_url": "https://example.com",
    "blog_url": "https://example.com/blog",
    "logo": "https://example.com/logo.webp",
    "author_img": "https://example.com/logo.webp",
    "twitter_handle": "yourhandle"
  },
  "author": {
    "name": "Your name",
    "description": "A short bio, HTML allowed (e.g. <a href=\"https://example.com/\">links</a>)."
  },
  "design": {
    "colors": {
      "main": "#188ddb",
      "heading": "#282828",
      "text": "#6d6d6d",
      "bg": "#ffffff"
    },
    "fonts": {
      "primary": "Arial"
    }
  },
  "scripts": [
    { "src": "https://your-analytics-script.example.com/latest.js", "async": true }
  ],
  "navigation": [
    { "label": "home", "url": "https://example.com" },
    { "label": "blog", "url": "https://example.com/blog" },
    { "label": "rss", "url": "https://example.com/blog/rss" }
  ],
  "social_links": [
    { "platform": "X", "url": "https://x.com/yourhandle" },
    { "platform": "github", "url": "https://github.com/yourhandle" }
  ]
}
```

| Field | Description |
|---|---|
| `site.name` | The name of your blog, used in the page title and RSS feed. |
| `site.base_url` | The root URL of your main website (used for reference). |
| `site.blog_url` | **The exact, full URL where this blog is installed.** This is the single source of truth for every internal link and asset path the blog generates — get this one right, and root-domain vs. subfolder installs both work automatically. |
| `site.logo` | Full URL to your logo/favicon image. |
| `site.author_img` | Full URL to an author photo (used in the author bio, if your theme displays one). |
| `site.twitter_handle` | Your X/Twitter handle, without the `@`, used for Twitter Card meta tags. |
| `author.name` / `author.description` | Shown in the author bio under each post. HTML is allowed in the description (e.g. a link), but it is sanitized — only `<a>` tags survive, and `javascript:` links and inline event handlers are stripped. |
| `design.colors` / `design.fonts` | Basic theming — these become CSS custom properties. |
| `scripts` | Optional array of external `<script>` tags to include site-wide (e.g. analytics). |
| `navigation` | The links shown in the site header. |
| `social_links` | Shown in the footer, and used to populate the `twitter:site` meta tag when a `platform: "X"` entry is present. |

### 5. Initialize the database

Run `setup_db.php` once, from the command line (not through your web server,
since it lives outside the web root by design):

```bash
php /opt/openblog/setup_db.php
```

This creates the SQLite file and the `posts`, `tags`, and `post_tags` tables.

### 6. Set up dashboard access

By default, the dashboard (`app/dashboard/`) is protected by two layers:

1. **Network-level**: your web server only allows requests from Tailscale's
   IP range (`100.64.0.0/10`) to reach `/dashboard/`.
2. **Application-level**: `tailscale_auth.php` calls `tailscale whois` to
   confirm the request genuinely comes from an authenticated Tailscale peer,
   not just a spoofed source IP.

See [`docs/tailscale-setup.md`](./docs/tailscale-setup.md) for the full,
step-by-step setup (Tailscale install, MagicDNS, sudoers configuration for
the `tailscale whois` call, and the matching Nginx blocks).

**Don't want to use Tailscale?** The dashboard protection is intentionally
isolated in `tailscale_auth.php` — you can replace `verifyTailscaleAccess()`
with your own logic (e.g. HTTP Basic Auth via your web server config) without
touching any other file.

### 7. (Optional) Automatic updates

If you'd like the blog to automatically pull the latest commits, add a cron
job that runs `update.sh`:

```bash
crontab -e
```

```
*/15 * * * * /opt/openblog/update.sh >> /var/log/openblog-update.log 2>&1
```

This does a plain `git pull --ff-only` — it never force-overwrites local
changes, and it never touches anything outside the repository folder.

## Security notes

- Never point your web server's document root at the repository root — only
  at `app/`. This keeps `setup_db.php`, `.env`, and this README itself out of
  reach from the outside.
- The `data/` folder (your SQLite database) should also live outside `app/`
  wherever possible, so it's never directly downloadable even by mistake.
- The dashboard has no login form by design — it relies entirely on network-
  level access control. Make sure that layer is actually configured before
  you consider the dashboard "protected".

## License

[MIT](./LICENSE)