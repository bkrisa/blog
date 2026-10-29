
<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schema.php';

$settings = loadSettings();
$db = new Database();

$stmt = $db->prepare("
  SELECT
    t.id,
    t.title,
    t.slug,
    COUNT(p.id) AS post_count
  FROM tags t
  INNER JOIN post_tags pt ON pt.tag_id = t.id
  INNER JOIN posts p ON p.id = pt.post_id AND p.status = 'published'
  GROUP BY t.id
  ORDER BY post_count DESC, t.title ASC
");
$stmt->execute();
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Tags - ' . $settings['site']['name'];
$page_description = 'All tags';
$page_url = $settings['site']['blog_url'] . '/tag';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="post-list">
  <h1>Tags</h1>

  <?php if (empty($tags)): ?>
    <p>There are no published tags yet.</p>
  <?php else: ?>
    <p class="counter"><?= count($tags) ?> tags</p>

    <div class="tag-list">
      <?php foreach ($tags as $tag): ?>
        <a
          class="tag-list-item"
          href="<?php echo htmlspecialchars($root_path . 'tag/' . $tag['slug']); ?>"
          title="<?= (int)$tag['post_count'] ?> post<?= $tag['post_count'] == 1 ? '' : 's' ?>"
        >
          <?php echo htmlspecialchars($tag['title']) ?>
          <span class="tag-list-count"><?= (int)$tag['post_count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
