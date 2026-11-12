<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../../tailscale_auth.php';
verifyTailscaleAccess();

$db = new Database();

require_once __DIR__ . '/../includes/search.php';

// Optional filter (?q=...), same index as the public search (drafts included)
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
$searchFailed = false;
if ($q !== '') {
  $ftsQuery = searchBuildQuery($q);
  if ($ftsQuery === null) {
    $where = 'WHERE 0';
  } else {
    try {
      searchEnsureIndex($db);
      $where = 'WHERE p.id IN (SELECT rowid FROM posts_fts WHERE posts_fts MATCH ?)';
      $params[] = $ftsQuery;
    } catch (Throwable $e) {
      error_log('Dashboard search failed: ' . $e->getMessage());
      $searchFailed = true;
    }
  }
}

$stmt = $db->prepare("
  SELECT
    p.id,
    p.title,
    p.slug,
    p.status,
    p.created_at,
    p.updated_at,
    GROUP_CONCAT(t.title, ', ') AS tags
  FROM posts p
  LEFT JOIN post_tags pt ON pt.post_id = p.id
  LEFT JOIN tags t ON t.id = pt.tag_id
  $where
  GROUP BY p.id
  ORDER BY (p.status = 'draft') DESC, p.created_at DESC, p.id DESC
");
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Dashboard";
$page_description = "";
$page_url = "";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard">
  <?php if (isset($_GET['deleted'])): ?>
    <p class="save-notice">Post deleted.</p>
  <?php endif; ?>

  <div class="dashboard-toolbar">
    <a href="editor.php">+ New post</a>
    <form action="./" method="get" role="search">
      <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search posts…" maxlength="100" aria-label="Search posts">
      <?php if ($q !== ''): ?><a href="./">Clear</a><?php endif; ?>
    </form>
  </div>

  <?php if ($searchFailed): ?>
    <p>Search is unavailable, showing all posts.</p>
  <?php elseif ($q !== ''): ?>
    <p><?php echo count($posts); ?> post<?php echo count($posts) === 1 ? '' : 's'; ?> found for “<?php echo htmlspecialchars($q); ?>”.</p>
  <?php endif; ?>

  <table class="posts-table">
    <thead>
      <tr>
        <th>title</th>
        <th>tags</th>
        <th>status</th>
        <th>last modified</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($posts)): ?>
        <tr>
          <td colspan="5"><?php echo $q !== '' ? 'No posts match your search.' : 'There are no posts yet.'; ?></td>
        </tr>
      <?php endif; ?>

      <?php foreach ($posts as $post): ?>
        <tr>
          <td>
            <a href="editor.php?id=<?php echo (int)$post['id']; ?>">
              <?php echo htmlspecialchars($post['title']); ?>
            </a>
          </td>
          <td><?php echo htmlspecialchars($post['tags'] ?? ''); ?></td>
          <td>
            <span class="status-badge status-<?php echo htmlspecialchars($post['status']); ?>">
              <?php echo $post['status'] === 'published' ? 'Published' : 'Draft'; ?>
            </span>
          </td>
          <td><?php echo htmlspecialchars($post['updated_at']); ?></td>
          <td>
            <form
              action="delete_post.php"
              method="POST"
              onsubmit="return confirm('Are you sure you want to delete this post? This cannot be undone.');"
              style="display:inline"
            >
              <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
              <button type="submit" style="border: none;background: transparent;cursor: pointer;">🗑️</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
