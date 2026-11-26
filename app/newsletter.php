<?php
// Public newsletter endpoint: subscribe (POST), confirm and unsubscribe (link in the emails).
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/newsletter.php';

$root_path = getRootPath();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');

// Messages shown on the result page (fixed list: nothing from the URL is printed)
$messages = [
  'check'        => ['Check your inbox', 'We sent you an email. Click the button in it to confirm your subscription.'],
  'confirmed'    => ['You’re on the list.', 'Also, so you know, not every post is sent to the mailing list. Every post hits the web, but the author decides which posts are emailed.'],
  'unsubscribed' => ['You are unsubscribed', 'You will not get any more emails from us.'],
  'expired'      => ['This link has expired', 'Please subscribe again to get a new confirmation email.'],
  'invalid'      => ['Something is not right', 'That email address or link is not valid.'],
  'ratelimit'    => ['Too many tries', 'Please try again a little later.'],
  'error'        => ['Could not send the email', 'Please try again a little later.'],
  'disabled'     => ['Newsletter is off', 'The newsletter is not available right now.'],
];

$resultKey = 'invalid';

try {
  $db = new Database();
  newsletterEnsureSchema($db);

  if ($method === 'POST' && $action === '') {
    // subscribe form (honeypot filled = bot: pretend it worked)
    $result = trim((string)($_POST['website'] ?? '')) !== ''
      ? 'check'
      : newsletterSubscribe($db, (string)($_POST['email'] ?? ''));
    // the form on the page sends the request with fetch(): answer with a short message, no redirect
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
      $inline = [
        'check'     => 'Thank you! Check your inbox and click the button in the email to confirm.',
        'invalid'   => 'Please enter a valid email address.',
        'ratelimit' => 'Too many tries. Please try again a little later.',
        'error'     => 'Could not send the email. Please try again a little later.',
        'disabled'  => 'The newsletter is not available right now.',
      ];
      header('Content-Type: application/json');
      header('Cache-Control: no-store');
      echo json_encode(['ok' => $result === 'check', 'message' => $inline[$result] ?? $inline['error']]);
      exit;
    }
    header('Location: ' . $root_path . 'newsletter?done=' . $result, true, 303);
    exit;
  }

  if ($method === 'GET' && isset($_GET['done'])) {
    $key = (string)$_GET['done'];
    $resultKey = isset($messages[$key]) ? $key : 'invalid';
  } elseif ($action === 'confirm') {
    // the button in the email opens this link: confirm right away, no second button
    $resultKey = newsletterConfirm($db, $token);
  } elseif ($action === 'unsubscribe') {
    // GET (link in the email) and POST (one-click from Gmail/Yahoo) both unsubscribe right away
    $resultKey = newsletterUnsubscribe($db, $token);
  } else {
    header('Location: ' . $root_path, true, 302);
    exit;
  }
} catch (Throwable $e) {
  error_log('Newsletter endpoint failed: ' . $e->getMessage());
  $resultKey = 'error';
}

[$heading, $text] = $messages[$resultKey];
$body = '<p>' . nl_h($text) . '</p>';
http_response_code($resultKey === 'invalid' ? 400 : 200);
$backLabel = $resultKey === 'confirmed' ? 'See all posts' : 'Back to the blog';

$page_title = $heading;
$page_description = '';
$page_url = '';
header('X-Robots-Tag: noindex');
require __DIR__ . '/includes/header.php';
?>
<main class="newsletter-result">
  <h1><?php echo nl_h($heading); ?></h1>
  <?php echo $body; ?>
  <p><a href="<?php echo nl_h($root_path); ?>"><?php echo nl_h($backLabel); ?> &rarr;</a></p>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
