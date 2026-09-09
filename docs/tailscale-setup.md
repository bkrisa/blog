# Protecting the dashboard with Tailscale

The dashboard (`app/dashboard/`) has no login form. Instead, it's protected
by two independent layers, both described below:

1. **Network-level:** your web server only accepts requests to `/dashboard/`
   that arrive over your [Tailscale](https://tailscale.com) network.
2. **Application-level:** `tailscale_auth.php` double-checks, from inside
   PHP, that the request genuinely comes from an authenticated Tailscale
   peer, using `tailscale whois`.

Neither layer relies on a password, a session, or anything that could be
guessed, brute-forced, or leaked in a database breach. Access is granted
purely by "is this device on my Tailscale network" — which can't be forged
from the public internet, because Tailscale connections are authenticated
via WireGuard before a single HTTP request is even possible.

You don't have to use this. If you'd rather protect the dashboard with, say,
HTTP Basic Auth, everything in this guide is isolated to
`tailscale_auth.php` and your web server config — swap those out and the
rest of the codebase doesn't care.

## 1. Install Tailscale

On your server:

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up
```

This prints a login URL — open it in a browser and sign in with the account
you want to administer the tailnet with. Do the same on any device you want
to use to access the dashboard from (laptop, phone).

## 2. Enable MagicDNS and HTTPS Certificates (optional but recommended)

In the [Tailscale admin console](https://login.tailscale.com/admin/dns), on
the **DNS** tab:

- Enable **MagicDNS** — this gives your server a stable hostname like
  `yourserver.your-tailnet.ts.net` instead of a bare IP.
- Enable **HTTPS Certificates**, if you want a real, browser-trusted
  certificate for that hostname (see the HTTP-only note below for why you
  might skip this).

### A note on choosing a hostname

Whatever hostname you pick becomes visible in the public
[Certificate Transparency logs](https://certificate.transparency.dev/) *if*
you request a real TLS certificate for it (via `tailscale cert`, step 4).
This doesn't grant anyone access — Tailscale hostnames aren't reachable
without being an authenticated peer — but it does reveal that the hostname
exists. Avoid names like `admin` or `dashboard`; a neutral, unrelated word
works just as well and gives away nothing about what it's for.

```bash
sudo tailscale set --hostname=your-chosen-name
```

## 3. Decide: HTTPS or plain HTTP for the dashboard server block

Because Tailscale already encrypts everything at the network layer (via
WireGuard), a second layer of TLS on top is optional, not required for
actual confidentiality. Two options:

**Option A — plain HTTP (simpler, avoids any public certificate)**

Skip `tailscale cert` entirely. Your browser will show "Not Secure", which
is cosmetic only — the connection is still fully encrypted by Tailscale
itself. This also means the hostname never appears in Certificate
Transparency logs, since no public certificate is ever requested for it.

**Option B — real HTTPS via `tailscale cert`**

```bash
sudo tailscale cert your-chosen-name.your-tailnet.ts.net
```

This generates a real, Let's Encrypt-backed certificate at
`/var/lib/tailscale/certs/`. It expires after 90 days and does not
auto-renew — set up a cron job to re-run the command monthly if you go this
route.

The rest of this guide assumes **Option A** (plain HTTP), since it's simpler
and has one less moving part to maintain. Adjust the Nginx block below if you
choose Option B.

## 4. Allow the `www-data` user to run `tailscale whois`

`tailscale_auth.php` needs to run `tailscale whois` to identify the caller,
but the `tailscaled` socket is root-only by default. Grant a narrow,
single-command sudo exception:

```bash
sudo visudo -f /etc/sudoers.d/tailscale-whois
```

Add this line (replace `www-data` if your PHP-FPM pool runs as a different
user — check with `grep -E '^user|^group' /etc/php/*/fpm/pool.d/*.conf`):

```
www-data ALL=(root) NOPASSWD: /usr/bin/tailscale whois *
```

Save, then make sure the file has the permissions `sudo` requires (it
silently ignores files that don't):

```bash
sudo chmod 0440 /etc/sudoers.d/tailscale-whois
sudo chown root:root /etc/sudoers.d/tailscale-whois
sudo visudo -c
```

Verify it works, as the same user PHP-FPM runs as:

```bash
sudo -u www-data sudo -n /usr/bin/tailscale whois --json 100.64.0.1
```

(the `-n` flag fails immediately instead of hanging if something's wrong —
you should get either a JSON response or a clear error, not a password
prompt)

## 5. Nginx configuration

Add a **separate server block** that only listens for your Tailscale
hostname, alongside your existing public server block:

```nginx
server {
  listen 80;
  server_name your-chosen-name.your-tailnet.ts.net;
  root /path/to/openblog/app;
  index index.php;

  location ^~ /dashboard/ {
    allow 100.64.0.0/10;
    deny all;

    try_files $uri $uri/ /dashboard/index.php$is_args$args;

    location ~ \.php$ {
      allow 100.64.0.0/10;
      deny all;

      fastcgi_split_path_info ^(.+\.php)(/.+)$;
      fastcgi_pass unix:/run/php/your-pool.sock;
      fastcgi_index index.php;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_param PATH_INFO $fastcgi_path_info;
    }
  }

  location ^~ /includes/ {
    deny all;
    return 403;
  }

  location ~ \.(db|bak|old|sql|sqlite|sqlite3|log|env|git|htaccess|ini|conf|json|md|sh|yml|yaml)$ {
    deny all;
    return 404;
  }

  location ~ /\.(?!well-known) {
    deny all;
    access_log off;
    log_not_found off;
  }
}
```

`100.64.0.0/10` is Tailscale's shared address range — the same one every
Tailscale user's devices get an address from. Publishing it changes nothing
about your security; it's already public knowledge, and it's meaningless
without a genuine, authenticated peer connection to back it up.

**If you installed the blog in a subfolder** (e.g. `example.com/blog`), point
`root` at your subfolder path directly (`/path/to/openblog/app`, same as
above) rather than trying to replicate the `/blog/` URL prefix here — there's
no need for the Tailscale-only server block to mirror your public URL
structure. Accessing `http://your-chosen-name.../dashboard/` is enough.

Reload once you're done:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## 6. Also block the dashboard on your public domain

This is the layer that actually keeps the dashboard off the public internet
— the Tailscale server block above only defines an *additional* entry point,
it doesn't remove the default one. In your **public** server block:

```nginx
location ^~ /dashboard/ {
  deny all;
  return 404;
}
```

## 7. (Optional) A convenience redirect from your public domain

If you'd like `https://example.com/dash` to redirect you to the dashboard
when you're on Tailscale (and fail harmlessly for anyone who isn't), add
this to your **public** server block:

```nginx
location = /dash {
  return 302 http://your-chosen-name.your-tailnet.ts.net/dashboard/;
}
```

This is not a security boundary by itself — anyone can trigger the redirect.
What matters is that only a device on your tailnet can actually *follow* it
to a working destination; everyone else's browser will simply fail to
resolve or reach that hostname.

## Verifying everything works

From a device **on** your tailnet:

```bash
curl -v http://your-chosen-name.your-tailnet.ts.net/dashboard/
```

Expect a `200` with rendered HTML.

From your public domain, on any device:

```bash
curl -v https://example.com/dashboard/
```

Expect a `404`.