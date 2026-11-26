<?php
/**
 * Sends the queued newsletter emails. Run it from cron every minute:
 *
 *   * * * * * php /srv/www/bkrisa.com/blog/app/scripts/send_queue.php >> /var/log/openblog-newsletter.log 2>&1
 *
 * Options: --limit=N   emails per run (default 50)
 */
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/newsletter_email.php';

const NEWSLETTER_MAX_ATTEMPTS = 5;

$limit = 50;
foreach ($argv as $arg) {
  if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
    $limit = max(1, (int)$m[1]);
  }
}

// only one run at a time
$lock = fopen(sys_get_temp_dir() . '/openblog-newsletter.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
  exit(0);
}

$db = new Database();
newsletterEnsureSchema($db);

if (!newsletterSettings()['enabled']) {
  exit(0);
}

// Emails of posts that were unpublished/deleted meanwhile, and of people who left, are cancelled.
$db->prepare("DELETE FROM newsletter_queue WHERE status = 'queued' AND post_id NOT IN (SELECT id FROM posts WHERE status = 'published')")->execute();
$db->prepare("DELETE FROM newsletter_queue WHERE status = 'queued' AND subscriber_id NOT IN (SELECT id FROM subscribers WHERE status = 'confirmed')")->execute();

$stmt = $db->prepare("SELECT q.id, q.post_id, q.attempts, s.id AS subscriber_id, s.email, s.token
  FROM newsletter_queue q JOIN subscribers s ON s.id = q.subscriber_id
  WHERE q.status = 'queued' AND q.send_after <= CURRENT_TIMESTAMP AND q.attempts < " . NEWSLETTER_MAX_ATTEMPTS . "
  ORDER BY q.id LIMIT " . (int)$limit);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$postStmt = $db->prepare("SELECT id, title, slug, content FROM posts WHERE id = ?");
$posts = [];
$sent = $failed = $retry = 0;

$markSent   = $db->prepare("UPDATE newsletter_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP, attempts = attempts + 1, error = NULL WHERE id = ?");
$markFailed = $db->prepare("UPDATE newsletter_queue SET status = 'failed', attempts = attempts + 1, error = ? WHERE id = ?");
$markRetry  = $db->prepare("UPDATE newsletter_queue SET attempts = attempts + 1, error = ?, send_after = datetime('now', ?) WHERE id = ?");
$markBounce = $db->prepare("UPDATE subscribers SET status = 'bounced' WHERE id = ?");

foreach ($rows as $row) {
  $pid = (int)$row['post_id'];
  if (!isset($posts[$pid])) {
    $postStmt->execute([$pid]);
    $posts[$pid] = $postStmt->fetch(PDO::FETCH_ASSOC);
  }
  $post = $posts[$pid];
  if (!$post) {
    continue;
  }

  try {
    $mail = newsletterBuildMessage($post, ['token' => $row['token']]);
    sendMail([
      'from'      => mailEnv('EMAIL_FROM'),
      'from_name' => newsletterSettings()['from_name'],
      'to'        => $row['email'],
      'reply_to'  => newsletterSettings()['reply_to'],
      'subject'   => $mail['subject'],
      'text'      => $mail['text'],
      'html'      => $mail['html'],
      'headers'   => $mail['headers'],
    ]);
    $markSent->execute([$row['id']]);
    $sent++;
  } catch (MailException $e) {
    $error = mb_substr($e->getMessage(), 0, 500);
    if ($e->isPermanent()) {
      $markFailed->execute([$error, $row['id']]);
      // an address-level refusal means "stop writing to this address"
      if (preg_match('/5\.1\.\d|user unknown|no such user|mailbox (unavailable|not found)|does not exist/i', $error)) {
        $markBounce->execute([$row['subscriber_id']]);
      }
      $failed++;
      continue;
    }
    // temporary problem: try again later (15, 30, 45 ... minutes)
    $markRetry->execute([$error, '+' . (15 * ((int)$row['attempts'] + 1)) . ' minutes', $row['id']]);
    $retry++;
    // no connection, or the server is throttling us (421/451/452): stop, the next run continues
    if ($e->smtpCode === 0 || in_array($e->smtpCode, [421, 451, 452], true)) {
      error_log('Newsletter: stopping this run: ' . $error);
      break;
    }
  }
}

if ($sent || $failed || $retry) {
  echo date('c') . " sent={$sent} failed={$failed} retry={$retry}\n";
}
