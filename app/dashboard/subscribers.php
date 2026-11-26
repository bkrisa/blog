<?php
// Newsletter overview: subscribers (list, search, delete, CSV export) and what was sent.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/same_origin.php';
require_once __DIR__ . '/../includes/newsletter.php';
require_once __DIR__ . '/../../tailscale_auth.php';
verifyTailscaleAccess();

$db = new Database();
newsletterEnsureSchema($db);

const SUBS_PER_PAGE = 50;
$statuses = ['confirmed', 'pending', 'unsubscribed', 'bounced'];

// ---------- actions (POST) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  requireSameOrigin();
  $action = (string)($_POST['action'] ?? '');
  $msg = 'error';

  if ($action === 'delete') {
    // removes the person completely (queue rows go with it): the "right to be forgotten"
    $db->prepare("DELETE FROM subscribers WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    $msg = 'deleted';
  } elseif ($action === 'retry') {
    $db->prepare("UPDATE newsletter_queue SET status = 'queued', attempts = 0, error = NULL, send_after = CURRENT_TIMESTAMP
                  WHERE post_id = ? AND status = 'failed'
                  AND subscriber_id IN (SELECT id FROM subscribers WHERE status = 'confirmed')")
       ->execute([(int)($_POST['post_id'] ?? 0)]);
    $msg = 'retried';
  } elseif ($action === 'cancel') {
    $db->prepare("DELETE FROM newsletter_queue WHERE post_id = ? AND status = 'queued'")->execute([(int)($_POST['post_id'] ?? 0)]);
    $msg = 'cancelled';
  }

  header('Location: subscribers.php?msg=' . $msg, true, 303);
  exit;
}

// ---------- filters ----------
$filter = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];
if ($filter !== '') {
  $where[] = 's.status = ?';
  $params[] = $filter;
}
if ($search !== '') {
  $where[] = "s.email LIKE ? ESCAPE '\\'";
  $params[] = '%' . addcslashes($search, '%_\\') . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---------- CSV export ----------
if (($_GET['export'] ?? '') === 'csv') {
  $stmt = $db->prepare("SELECT s.email, s.status, s.created_at, s.confirmed_at FROM subscribers s $whereSql ORDER BY s.id");
  $stmt->execute($params);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="subscribers-' . ($filter ?: 'all') . '-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['email', 'status', 'subscribed_at', 'confirmed_at']);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // a cell that starts with = + - @ could run as a formula in Excel
    $row['email'] = preg_match('/^[=+\-@\t\r]/', $row['email']) ? "'" . $row['email'] : $row['email'];
    fputcsv($out, $row);
  }
  fclose($out);
  exit;
}

// ---------- data ----------
$counts = array_fill_keys($statuses, 0);
$stmt = $db->prepare("SELECT status, COUNT(*) FROM subscribers GROUP BY status");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$st, $n]) {
  $counts[$st] = (int)$n;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM subscribers s $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / SUBS_PER_PAGE));
$page = min($page, $pages);

$stmt = $db->prepare("SELECT s.id, s.email, s.status, s.created_at, s.confirmed_at FROM subscribers s $whereSql
                      ORDER BY s.id DESC LIMIT " . SUBS_PER_PAGE . " OFFSET " . (($page - 1) * SUBS_PER_PAGE));
$stmt->execute($params);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT p.id, p.title,
    SUM(q.status = 'queued') AS queued, SUM(q.status = 'sent') AS sent, SUM(q.status = 'failed') AS failed,
    MAX(q.sent_at) AS last_sent
  FROM newsletter_queue q JOIN posts p ON p.id = q.post_id
  GROUP BY p.id ORDER BY MAX(q.id) DESC LIMIT 10");
$stmt->execute();
$sends = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT s.email, p.title, q.error FROM newsletter_queue q
  JOIN subscribers s ON s.id = q.subscriber_id JOIN posts p ON p.id = q.post_id
  WHERE q.status = 'failed' ORDER BY q.id DESC LIMIT 20");
$stmt->execute();
$failures = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notices = [
  'deleted' => 'Subscriber deleted.',
  'retried' => 'The failed emails are queued again.',
  'cancelled' => 'The waiting emails were cancelled.',
  'error' => 'Something went wrong.',
];
$notice = $notices[$_GET['msg'] ?? ''] ?? '';

function subs_url(array $over = []): string {
  global $filter, $search, $page;
  $q = array_filter(array_merge(['status' => $filter, 'q' => $search, 'page' => $page > 1 ? $page : ''], $over), fn($v) => $v !== '' && $v !== null);
  return 'subscribers.php' . ($q ? '?' . http_build_query($q) : '');
}

$page_title = 'Newsletter';
$page_description = '';
$page_url = '';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="dashboard">
  <a href="./" style="font-size: 14px;">← Back dashboard</a>
  <h1 style="margin:10px 0;">Newsletter</h1>

  <?php if ($notice): ?>
    <p class="subs-notice"><?= nl_h($notice) ?></p>
  <?php endif; ?>

  <nav class="subs-tabs" aria-label="Subscriber status">
    <a href="<?= nl_h(subs_url(['status' => '', 'page' => ''])) ?>" <?= $filter === '' ? 'class="on"' : '' ?>>All <?= array_sum($counts) ?></a>
    <?php foreach ($statuses as $st): ?>
      <a href="<?= nl_h(subs_url(['status' => $st, 'page' => ''])) ?>" <?= $filter === $st ? 'class="on"' : '' ?>><?= nl_h(ucfirst($st)) ?> <?= $counts[$st] ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="dashboard-toolbar">
    <form method="GET" action="subscribers.php" style="display:flex;gap:8px;flex:1;">
      <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= nl_h($filter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= nl_h($search) ?>" placeholder="Search email…" aria-label="Search email" style="flex:1;max-width:320px;">
    </form>
    <a href="<?= nl_h(subs_url(['export' => 'csv', 'page' => ''])) ?>">Export CSV</a>
  </div>

  <table class="subs-table subs-people">
    <thead><tr><th>Email</th><th>Status</th><th>Since</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($subs as $s): ?>
      <tr>
        <td><?= nl_h($s['email']) ?></td>
        <td><span class="subs-status subs-<?= nl_h($s['status']) ?>"><?= nl_h($s['status']) ?></span></td>
        <td><?= nl_h(substr((string)($s['confirmed_at'] ?: $s['created_at']), 0, 10)) ?></td>
        <td>
          <form method="POST" action="subscribers.php" onsubmit="return confirm('Delete <?= nl_h(addslashes($s['email'])) ?> for good?');" style="margin:0;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="subs-link" aria-label="Delete <?= nl_h($s['email']) ?>">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$subs): ?>
      <tr><td colspan="4" style="padding:16px 8px;">No subscribers here yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
    <p class="subs-pager">
      <?php if ($page > 1): ?><a href="<?= nl_h(subs_url(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>">← Newer</a><?php endif; ?>
      <span>Page <?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= nl_h(subs_url(['page' => $page + 1])) ?>">Older →</a><?php endif; ?>
    </p>
  <?php endif; ?>

  <h2 style="margin:28px 0 8px;font-size:18px;">Sent emails</h2>
  <table class="subs-table">
    <thead><tr><th>Post</th><th>Sent</th><th>Waiting</th><th>Failed</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sends as $r): ?>
      <tr>
        <td><?= nl_h($r['title']) ?></td>
        <td><?= (int)$r['sent'] ?></td>
        <td><?= (int)$r['queued'] ?></td>
        <td><?= (int)$r['failed'] ?></td>
        <td style="white-space:nowrap;">
          <?php if ((int)$r['failed'] > 0): ?>
            <form method="POST" action="subscribers.php" style="display:inline;margin:0;">
              <input type="hidden" name="action" value="retry"><input type="hidden" name="post_id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="subs-link">Retry failed</button>
            </form>
          <?php endif; ?>
          <?php if ((int)$r['queued'] > 0): ?>
            <form method="POST" action="subscribers.php" style="display:inline;margin:0;" onsubmit="return confirm('Cancel the emails that are still waiting?');">
              <input type="hidden" name="action" value="cancel"><input type="hidden" name="post_id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="subs-link">Cancel waiting</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sends): ?>
      <tr><td colspan="5" style="padding:16px 8px;">Nothing was sent yet. Tick the envelope icon in the editor when you publish.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if ($failures): ?>
    <h2 style="margin:28px 0 8px;font-size:18px;">Failed emails</h2>
    <table class="subs-table">
      <thead><tr><th>Email</th><th>Post</th><th>Error</th></tr></thead>
      <tbody>
      <?php foreach ($failures as $f): ?>
        <tr>
          <td><?= nl_h($f['email']) ?></td>
          <td><?= nl_h($f['title']) ?></td>
          <td style="word-break:break-word;"><?= nl_h($f['error']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
