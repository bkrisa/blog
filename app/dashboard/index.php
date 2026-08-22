<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$db = new Database();

$stmt = $db->prepare("
  SELECT
    p.id,
    p.title,
    p.slug,
    p.status,
    p.updated_at,
    GROUP_CONCAT(t.title, ', ') AS tags
  FROM posts p
  LEFT JOIN post_tags pt ON pt.post_id = p.id
  LEFT JOIN tags t ON t.id = pt.tag_id
  GROUP BY p.id
  ORDER BY p.updated_at DESC
");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Dashboard";
$page_description = "";
$page_url = "";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="">

  <?php if (isset($_GET['deleted'])): ?>
    <p class="save-notice">Post deleted.</p>
  <?php endif; ?>

  <a href="editor.php" class="button-new-post">+ New poszt</a>

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
          <td colspan="5">There are no posts yet.</td>
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
            <a href="editor.php?id=<?php echo (int)$post['id']; ?>">Editing</a>

            <form
              action="delete_post.php"
              method="POST"
              onsubmit="return confirm('Are you sure you want to delete this post? This cannot be undone.');"
              style="display:inline"
            >
              <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>