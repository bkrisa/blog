<?php
// Sends a test copy of the post you are editing to one address (nothing is queued, nobody else gets it).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/same_origin.php';
require_once __DIR__ . '/../includes/newsletter_email.php';
require_once __DIR__ . '/../../tailscale_auth.php';
verifyTailscaleAccess();
requireSameOrigin();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'POST only']);
  exit;
}

$to = trim((string)($_POST['to'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$content = (string)($_POST['content'] ?? '');
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_POST['slug'] ?? ''))) ?: 'preview';

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Enter a valid email address for the test.']);
  exit;
}
if ($title === '' || trim(strip_tags($content, '<img>')) === '') {
  http_response_code(400);
  echo json_encode(['error' => 'The post needs a title and some content first.']);
  exit;
}

try {
  $mail = newsletterBuildMessage(
    ['id' => 'test', 'title' => $title, 'slug' => $slug, 'content' => $content],
    ['token' => str_repeat('0', 48)] // the unsubscribe link of a test email does nothing
  );
  sendMail([
    'from'      => mailEnv('EMAIL_FROM'),
    'from_name' => newsletterSettings()['from_name'],
    'to'        => $to,
    'reply_to'  => newsletterSettings()['reply_to'],
    'subject'   => '[Test] ' . $mail['subject'],
    'text'      => $mail['text'],
    'html'      => $mail['html'],
    'headers'   => $mail['headers'],
  ]);
  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  error_log('Newsletter test email failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Could not send: ' . $e->getMessage()]);
}
