<?php
/**
 * Newsletter core: subscribers, double opt-in, unsubscribe, confirmation email.
 * Needs: config.php (.env loaded), database.php, settings.php, mailer.php
 */
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mailer.php';

const NEWSLETTER_CONFIRM_TTL = 172800;      // confirmation link lives 48 hours
const NEWSLETTER_RESEND_AFTER = 600;        // same address: no new confirmation email within 10 minutes
const NEWSLETTER_IP_LIMIT = 5;              // subscribe attempts per IP per hour
const NEWSLETTER_DAILY_CONFIRM_LIMIT = 200; // confirmation emails per day (protects the monthly sending quota)

function nl_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Create the tables on first use (only prepare/execute: the Database wrapper has no exec()). */
function newsletterEnsureSchema(Database $db): void {
  static $done = false;
  if ($done) {
    return;
  }
  $statements = [
    "CREATE TABLE IF NOT EXISTS subscribers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email VARCHAR(254) NOT NULL UNIQUE COLLATE NOCASE,
      token VARCHAR(64) NOT NULL UNIQUE,
      status VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','unsubscribed','bounced')),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      confirm_sent_at DATETIME,
      confirmed_at DATETIME,
      unsubscribed_at DATETIME
    )",
    "CREATE INDEX IF NOT EXISTS idx_subscribers_status ON subscribers(status)",
    "CREATE TABLE IF NOT EXISTS newsletter_queue (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      post_id INTEGER NOT NULL,
      subscriber_id INTEGER NOT NULL,
      status VARCHAR(10) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','failed')),
      send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      attempts INTEGER NOT NULL DEFAULT 0,
      sent_at DATETIME,
      error TEXT,
      UNIQUE(post_id, subscriber_id),
      FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
      FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE
    )",
    "CREATE INDEX IF NOT EXISTS idx_queue_status ON newsletter_queue(status, send_after)",
    "CREATE TABLE IF NOT EXISTS newsletter_hits (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      ip_hash VARCHAR(64) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_hits_ip ON newsletter_hits(ip_hash, created_at)",
  ];
  foreach ($statements as $sql) {
    $db->prepare($sql)->execute();
  }
  $done = true;
}

/** Newsletter settings with defaults (settings.json -> "newsletter"). */
function newsletterSettings(): array {
  $all = function_exists('loadSettings') ? loadSettings() : [];
  $n = is_array($all['newsletter'] ?? null) ? $all['newsletter'] : [];
  $siteName = (string)($all['site']['name'] ?? 'Blog');

  // required texts fall back to the default when they are left empty; the optional ones may be empty
  $text = fn(string $key, string $default): string => trim((string)($n[$key] ?? '')) !== '' ? trim((string)$n[$key]) : $default;

  return [
    'enabled'       => (bool)($n['enabled'] ?? true),
    'from_name'     => $text('from_name', $siteName),
    'reply_to'      => (string)($n['reply_to'] ?? ''),
    'form_text'     => $text('form_text', 'Get new posts in your inbox.'),
    'form_button'   => $text('form_button', 'Subscribe'),
    'form_note'     => (string)($n['form_note'] ?? 'No spam. Unsubscribe anytime.'),
    'intro'         => (string)($n['intro'] ?? ''),
    'outro'         => (string)($n['outro'] ?? ''),
    'delay_minutes' => max(0, (int)($n['delay_minutes'] ?? 10)),
    'site_name'     => $siteName,
    'blog_url'      => rtrim((string)($all['site']['blog_url'] ?? ''), '/'),
  ];
}

/** Absolute URL of the public newsletter endpoint. */
function newsletterUrl(array $params = []): string {
  $base = newsletterSettings()['blog_url'] . '/newsletter';
  return $params ? $base . '?' . http_build_query($params) : $base;
}

function newsletterClientIpHash(): string {
  // Behind Cloudflare the real visitor address is in CF-Connecting-IP
  $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
  return hash('sha256', $ip . '|' . mailEnv('EMAIL_API_TOKEN', 'openblog'));
}

function newsletterRateLimited(Database $db): bool {
  $hash = newsletterClientIpHash();

  $stmt = $db->prepare("SELECT COUNT(*) FROM newsletter_hits WHERE ip_hash = ? AND created_at > datetime('now', '-1 hour')");
  $stmt->execute([$hash]);
  if ((int)$stmt->fetchColumn() >= NEWSLETTER_IP_LIMIT) {
    return true;
  }

  $db->prepare("INSERT INTO newsletter_hits (ip_hash) VALUES (?)")->execute([$hash]);
  $db->prepare("DELETE FROM newsletter_hits WHERE created_at < datetime('now', '-1 day')")->execute();
  return false;
}

function newsletterConfirmEmailsToday(Database $db): int {
  $stmt = $db->prepare("SELECT COUNT(*) FROM subscribers WHERE confirm_sent_at > datetime('now', '-1 day')");
  $stmt->execute();
  return (int)$stmt->fetchColumn();
}

/**
 * Handle the subscribe form.
 * Returns a result key for the public page: 'check' | 'invalid' | 'ratelimit' | 'error' | 'disabled'.
 * 'check' is returned for every valid address (new, pending or already subscribed), so the form
 * can't be used to find out who is on the list.
 */
function newsletterSubscribe(Database $db, string $emailInput): string {
  if (!newsletterSettings()['enabled']) {
    return 'disabled';
  }

  $email = trim($emailInput);
  if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return 'invalid';
  }

  newsletterEnsureSchema($db);

  if (newsletterRateLimited($db)) {
    return 'ratelimit';
  }

  $stmt = $db->prepare("SELECT id, token, status, confirm_sent_at,
    CAST(strftime('%s','now') - strftime('%s', COALESCE(confirm_sent_at, '1970-01-01')) AS INTEGER) AS since_sent
    FROM subscribers WHERE email = ?");
  $stmt->execute([$email]);
  $sub = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($sub && in_array($sub['status'], ['confirmed', 'bounced'], true)) {
    return 'check'; // nothing to do, and nothing to reveal
  }
  if ($sub && $sub['status'] === 'pending' && (int)$sub['since_sent'] < NEWSLETTER_RESEND_AFTER) {
    return 'check'; // a confirmation email was just sent
  }
  if (newsletterConfirmEmailsToday($db) >= NEWSLETTER_DAILY_CONFIRM_LIMIT) {
    return 'ratelimit';
  }

  if ($sub) { // pending (older request) or unsubscribed: start over with a fresh token
    $token = bin2hex(random_bytes(24));
    $db->prepare("UPDATE subscribers SET token = ?, status = 'pending', unsubscribed_at = NULL WHERE id = ?")
       ->execute([$token, $sub['id']]);
    $subscriberId = (int)$sub['id'];
  } else {
    $token = bin2hex(random_bytes(24));
    $db->prepare("INSERT INTO subscribers (email, token) VALUES (?, ?)")->execute([$email, $token]);
    $subscriberId = (int)$db->lastInsertId();
  }

  try {
    newsletterSendConfirmation($email, $token);
    $db->prepare("UPDATE subscribers SET confirm_sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$subscriberId]);
  } catch (MailException $e) {
    error_log('Newsletter confirmation failed: ' . $e->getMessage());
    if ($e->isPermanent()) {
      return 'invalid'; // the mail server refuses this address
    }
    return 'error';
  }
  return 'check';
}

function newsletterFindByToken(Database $db, string $token): ?array {
  if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
    return null;
  }
  newsletterEnsureSchema($db);
  $stmt = $db->prepare("SELECT *,
    CAST(strftime('%s','now') - strftime('%s', COALESCE(confirm_sent_at, created_at)) AS INTEGER) AS age
    FROM subscribers WHERE token = ?");
  $stmt->execute([$token]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** 'confirmed' | 'expired' | 'invalid' */
function newsletterConfirm(Database $db, string $token): string {
  $sub = newsletterFindByToken($db, $token);
  if (!$sub) {
    return 'invalid';
  }
  if ($sub['status'] === 'confirmed') {
    return 'confirmed'; // clicking twice is fine
  }
  if ($sub['status'] !== 'pending') {
    return 'invalid';
  }
  if ((int)$sub['age'] > NEWSLETTER_CONFIRM_TTL) {
    return 'expired';
  }
  $db->prepare("UPDATE subscribers SET status = 'confirmed', confirmed_at = CURRENT_TIMESTAMP WHERE id = ?")
     ->execute([$sub['id']]);
  return 'confirmed';
}

/** 'unsubscribed' | 'invalid' */
function newsletterUnsubscribe(Database $db, string $token): string {
  $sub = newsletterFindByToken($db, $token);
  if (!$sub) {
    return 'invalid';
  }
  if ($sub['status'] !== 'unsubscribed') {
    $db->prepare("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$sub['id']]);
    // whatever was still waiting in the queue must not go out
    $db->prepare("DELETE FROM newsletter_queue WHERE subscriber_id = ? AND status = 'queued'")->execute([$sub['id']]);
  }
  return 'unsubscribed';
}

/** Headers that make Gmail/Yahoo show their own "Unsubscribe" link (RFC 2369 + RFC 8058). */
function newsletterUnsubscribeHeaders(string $token): array {
  return [
    'List-Unsubscribe' => '<' . newsletterUrl(['action' => 'unsubscribe', 'token' => $token]) . '>',
    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
  ];
}

/** The double opt-in email: short, plain, one button. */
function newsletterSendConfirmation(string $email, string $token): void {
  $n = newsletterSettings();
  $site = $n['site_name'];
  $link = newsletterUrl(['action' => 'confirm', 'token' => $token]);

  $text = "You're almost subscribed to updates from {$site}.\n\n"
        . "Confirm your subscription below to get future posts in your inbox.\n\n"
        . "{$link}\n\n"
        . "--\n"
        . "Don't want to subscribe to {$site}? Feel free to ignore this email.\n";

  $siteH = nl_h($site);
  $linkH = nl_h($link);
  $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px 16px;background:#ffffff;">'
    . '<div style="max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#222222;">'
    . "<p>You&rsquo;re almost subscribed to updates from <strong>{$siteH}</strong>.</p>"
    . '<p>Confirm your subscription below to get future posts in your inbox.</p>'
    . "<p style=\"margin:24px 0;\"><a href=\"{$linkH}\" style=\"display:inline-block;background:#2f6fed;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-size:15px;\">Confirm my subscription</a></p>"
    . '<hr style="border:0;border-top:1px solid #dddddd;margin:28px 0 14px;">'
    . "<p style=\"font-size:14px;color:#555555;\">Don&rsquo;t want to subscribe to {$siteH}? Feel free to ignore this email.</p>"
    . '</div></body></html>';

  sendMail([
    'from'      => mailEnv('EMAIL_FROM'),
    'from_name' => $n['from_name'],
    'to'        => $email,
    'reply_to'  => $n['reply_to'],
    'subject'   => 'Please confirm your subscription to ' . $site,
    'text'      => $text,
    'html'      => $html,
  ]);
}
