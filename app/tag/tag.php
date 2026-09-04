<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/functions.php';

$settings = loadSettings();
$db = new Database();
$slug = $_GET['slug'] ?? '';

$stmt = $db->prepare("
  SELECT id, title, slug
  FROM tags
  WHERE slug = :slug
  LIMIT 1
");
$stmt->execute(['slug' => $slug]);
$tag = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
  SELECT
    p.id,
    p.title,
    p.slug,
    p.excerpt,
    p.content,
    p.created_at
  FROM posts p
  INNER JOIN post_tags pt ON pt.post_id = p.id
  INNER JOIN tags t ON t.id = pt.tag_id
  WHERE t.slug = :slug
    AND p.status = 'published'
  ORDER BY p.created_at DESC
");
$stmt->execute(['slug' => $slug]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $tag['title'] . ' - ' . $settings['site']['name'];
$page_description = "";
$page_url = "";

require_once __DIR__ . '/../includes/header.php';
?>

<div class="post-list">
  <?php if (empty($posts)): ?>
    <p>There are no published posts yet.</p>
  <?php endif; ?>

  <?php if ($tag): ?>
    <h1><?php echo htmlspecialchars($tag['title']); ?></h1>
  <?php endif; ?>

  <?php foreach ($posts as $post): ?>
    <?php $thumbnail = getFirstImage($post['content']); ?>
    <article class="post-preview">
      <?php if ($thumbnail): ?>
        <a class="post-thumbnail-link" href="<?php echo htmlspecialchars($root_path); ?>post.php?slug=<?php echo urlencode($post['slug']); ?>">
          <img src="<?php echo htmlspecialchars($thumbnail); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" 
          class="post-thumbnail" loading="lazy">
        </a>
      <?php endif; ?>
      <div>
        <time datetime="<?php echo htmlspecialchars($post['created_at']); ?>">
          <?php echo htmlspecialchars(date('Y M. d.', strtotime($post['created_at']))); ?>
        </time>
        <h2>
          <a href="<?php echo htmlspecialchars($root_path); ?>post.php?slug=<?php echo urlencode($post['slug']); ?>">
            <?php echo htmlspecialchars($post['title']); ?>
          </a>
        </h2>
        <?php if (!empty($post['excerpt'])): ?>
          <p class="post-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
