<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';

$settings = loadSettings();
$db = new Database();

$stmt = $db->prepare("
  SELECT id, title, slug, excerpt, content, created_at
  FROM posts
  WHERE status = 'published'
  ORDER BY created_at DESC
");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $settings['site']['name'];
$page_description = "";
$page_url = "";

require_once __DIR__ . '/includes/header.php';
?>

<div class="post-list">
  <?php if (empty($posts)): ?>
    <p>There are no published posts yet.</p>
  <?php endif; ?>

  <?php foreach ($posts as $post): ?>
    <?php $thumbnail = getFirstImage($post['content']); ?>
    <article class="post-preview">
      <?php if ($thumbnail): ?>
        <a href="<?php echo htmlspecialchars($root_path); ?>post.php?slug=<?php echo urlencode($post['slug']); ?>">
          <img src="<?php echo htmlspecialchars($thumbnail); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" 
          class="post-thumbnail" loading="lazy">
        </a>
      <?php endif; ?>

      <time datetime="<?php echo htmlspecialchars($post['created_at']); ?>">
        <?php echo htmlspecialchars(date('Y.m.d.', strtotime($post['created_at']))); ?>
      </time>
      <h2>
        <a href="<?php echo htmlspecialchars($root_path); ?>post.php?slug=<?php echo urlencode($post['slug']); ?>">
          <?php echo htmlspecialchars($post['title']); ?>
        </a>
      </h2>
      <?php if (!empty($post['excerpt'])): ?>
        <p class="post-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>