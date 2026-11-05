# OpenBlog

A minimal, self-hosted, open-source blog platform built with PHP and SQLite.

Built by [@bkrisa12](https://x.com/bkrisa12)

No user accounts, no login form, no password database to secure. Publishing is done through a small dashboard that is protected at the network level — by default via Tailscale, so only your own devices can ever reach it.

## Features

- Posts with title, content (WYSIWYG editor with image upload), tags, and a custom URL slug
- Tags with a many-to-many relationship to posts
- Settings page in the dashboard - edit your site name, design, navigation and more in the browser, no SSH needed
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

Clone it anywhere on your server — it does **not** need to live inside your existing web root:

```
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

This is the single most important security rule of this project: **your web server's document root (or `alias`) must point at the `app/` subfolder, never at the repository root.** If it points at the repository root, files like `setup_db.php` become publicly downloadable.

See [`nginx.conf.example`](nginx.conf.example) for a working example, both for installing at your domain root (`example.com`) and in a subfolder (`example.com/blog`).

### 3. Create your `.env` file

Copy `.env.example` to `.env` in the repository root, and set the path to your SQLite database file:

```
DB_PATH=/opt/openblog/data/database.db
```

The `data/` folder is created automatically on first run if it doesn't exist.

### 4. Create your `settings.json`

Copy the example file and make it writable for the web server, so the dashboard can save changes to it:

```
cp app/settings.example.json app/settings.json
chown www-data app/settings.json
```

Replace `www-data` with the user your PHP-FPM pool runs as (check with `ps aux | grep php-fpm`).

You don't need to edit the file by hand: after step 6 you can change everything on the **Settings** page of the dashboard (`/dashboard/settings.php`). Just make sure `site.blog_url` is correct before you open the blog, since every internal link is built from it.

> `settings.json` is listed in `.gitignore`, so your own settings are never overwritten by `update.sh` or `git pull`. Only `settings.example.json` is tracked in the repository.

### 5. Initialize the database

Run `setup_db.php` once, from the command line (not through your web server, since it lives outside the web root by design):

```
php /opt/openblog/setup_db.php
```

This creates the SQLite file and the `posts`, `tags`, and `post_tags` tables.

### 6. Set up dashboard access

By default, the dashboard (`app/dashboard/`) is protected by two layers:

1. **Network-level**: your web server only allows requests from Tailscale's IP range (`100.64.0.0/10`) to reach `/dashboard/`.
2. **Application-level**: `tailscale_auth.php` calls `tailscale whois` to confirm the request genuinely comes from an authenticated Tailscale peer, not just a spoofed source IP.

See [`docs/tailscale-setup.md`](docs/tailscale-setup.md) for the full, step-by-step setup (Tailscale install, MagicDNS, sudoers configuration for the `tailscale whois` call, and the matching Nginx blocks).

**Don't want to use Tailscale?** The dashboard protection is intentionally isolated in `tailscale_auth.php` — you can replace `verifyTailscaleAccess()` with your own logic (e.g. HTTP Basic Auth via your web server config) without touching any other file.

### 7. (Optional) Automatic updates

If you'd like the blog to automatically pull the latest commits, add a cron job that runs `update.sh`:

```
crontab -e
```

```
*/15 * * * * /opt/openblog/update.sh >> /var/log/openblog-update.log 2>&1
```

This does a plain `git pull --ff-only` — it never force-overwrites local changes, and it never touches anything outside the repository folder.

## Settings

All site settings live in one file, `app/settings.json`. You can change them in two ways:

- **From the dashboard (recommended):** open `/dashboard/settings.php` (the **Settings** link on the dashboard), change what you need and click **Save settings**. The changes are written straight back to `settings.json`.
- **By hand:** edit `app/settings.json` over SSH. Both ways work, and they always edit the same file.

The settings page validates everything before saving: URLs must be `http(s)` addresses (navigation links may also be `/paths` or `mailto:`), colors must be hex values like `#188ddb`, and the font may only contain letters, numbers, spaces, commas and hyphens. If something is invalid, nothing is saved and the form tells you what to fix. Keys the form doesn't know about are kept untouched.

Each save is written atomically, and the previous version is kept as `settings.json.bak` when the web server may write to the `app/` folder (see [File permissions](#file-permissions)).

### `settings.json` reference

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
  "head": [
    { "html": "<script src=\"https://your-analytics-script.example.com/latest.js\" async></script>" }
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

| Field                                | Description                                                                                                                                                                                                                              |
|--------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `site.name`                          | The name of your blog, used in the page title and RSS feed.                                                                                                                                                                              |
| `site.base_url`                      | The root URL of your main website (used for reference).                                                                                                                                                                                  |
| `site.blog_url`                      | **The exact, full URL where this blog is installed.** This is the single source of truth for every internal link and asset path the blog generates — get this one right, and root-domain vs. subfolder installs both work automatically. |
| `site.logo`                          | Full URL to your logo/favicon image.                                                                                                                                                                                                     |
| `site.author_img`                    | Full URL to an author photo (used in the author bio, if your theme displays one).                                                                                                                                                        |
| `site.twitter_handle`                | Your X/Twitter handle, without the `@`, used for Twitter Card meta tags.                                                                                                                                                                 |
| `author.name` / `author.description` | Shown in the author bio under each post. HTML is allowed in the description (e.g. a link), but it is sanitized — only `<a>` tags survive, and `javascript:` links and inline event handlers are stripped.                                |
| `design.colors` / `design.fonts`     | Basic theming — these become CSS custom properties.                                                                                                                                                                                      |
| `head`                               | Optional list of raw HTML snippets added to the `<head>` of every page (e.g. an analytics script). Each entry is an object with an `html` key. Only add code you trust.                                                                  |
| `navigation`                         | The links shown in the site header.                                                                                                                                                                                                      |
| `social_links`                       | Shown in the footer, and used to populate the `twitter:site` meta tag when a `platform: "X"` entry is present.                                                                                                                           |

### File permissions

For the settings page to save, the web server user (usually `www-data`) must be able to write `app/settings.json`. There are two ways to set this up:

- **Only the file is writable (stricter):** `chown www-data app/settings.json`. The `app/` folder stays read-only for PHP, so a compromised script can't create new files in your web root. Saving works, but no `settings.json.bak` backup is made.
- **The `app/` folder is writable too:** saves are atomic (a temporary file is written and renamed over the original) and a `settings.json.bak` backup is created each time. This is more convenient, but gives PHP write access to the web root.

If the file isn't writable, the settings page shows an error instead of failing silently.

## Security notes

- Never point your web server's document root at the repository root — only at `app/`. This keeps `setup_db.php`, `.env`, and this README itself out of reach from the outside.
- The `data/` folder (your SQLite database) should also live outside `app/` wherever possible, so it's never directly downloadable even by mistake.
- The dashboard has no login form by design — it relies entirely on network-level access control. Make sure that layer is actually configured before you consider the dashboard "protected".
- Because there is no login session, the dashboard protects against cross-site request forgery (CSRF) by checking the browser's `Sec-Fetch-Site` / `Origin` headers (see `app/includes/same_origin.php`). Every dashboard script that changes data (`save_post.php`, `delete_post.php`, `upload.php`, `settings.php`) must call `requireSameOrigin()` right after `verifyTailscaleAccess()`. If you add your own dashboard scripts, do the same.
- Anyone who can reach the dashboard can change your settings, including the `head` snippets, which are inserted as raw HTML into every page. Treat access to your tailnet accordingly: don't share your devices with people you don't trust, and consider restricting access with Tailscale ACLs.
- `settings.json` (and `settings.json.bak`) sit inside `app/`, so your web server can serve them like any other file. Never store secrets such as API keys or passwords in them, and consider blocking them in your web server config.

## License

[MIT](LICENSE)
