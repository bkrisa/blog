<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

header('Content-Type: text/plain; charset=utf-8');

$settings = loadSettings();
$baseUrl = rtrim($settings['site']['base_url'], '/');
$blogUrl = rtrim($settings['site']['blog_url'], '/');
$db = new Database();

$stmt = $db->prepare("
  SELECT title, slug, excerpt, created_at
  FROM posts
  WHERE status = 'published'
  ORDER BY created_at DESC
");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tagStmt = $db->prepare("
  SELECT DISTINCT t.title
  FROM tags t
  INNER JOIN post_tags pt ON pt.tag_id = t.id
  INNER JOIN posts p ON p.id = pt.post_id
  WHERE p.status = 'published'
  ORDER BY t.title
");
$tagStmt->execute();
$tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

$lastUpdated = !empty($posts) ? date('Y-m-d', strtotime($posts[0]['created_at'])) : date('Y-m-d');
$authorName = $settings['author']['name'] ?? '';
$siteName = $settings['site']['name'] ?? '';
$authorDescription = strip_tags($settings['author']['description'] ?? '');
?>
# <?php echo $siteName; ?>

> <?php echo $authorDescription; ?>

All content here is <?php echo $authorName; ?>'s own writing. Please link back if you quote it.

Last updated: <?php echo $lastUpdated; ?> (most recent post). This file is generated live, so it always reflects what is published right now.

## Machine-readable access

- [RSS feed](<?php echo $blogUrl; ?>/rss): all posts, newest first
- [Sitemap](<?php echo $blogUrl; ?>/sitemap.xml): every URL on the blog

## Pages

- [Home](<?php echo $baseUrl; ?>/)
- [Blog - every post, newest first](<?php echo $blogUrl; ?>/)
<?php foreach ($tags as $tag): ?>
- [Tag: <?php echo $tag; ?>](<?php echo $blogUrl; ?>/tag/<?php echo rawurlencode(strtolower($tag)); ?>)
<?php endforeach; ?>

## All posts

<?php foreach ($posts as $post): ?>
- [<?php echo str_replace(["\r", "\n"], ' ', $post['title']); ?>](<?php echo $blogUrl . '/' . rawurlencode($post['slug']); ?>) — <?php echo date('Y-m-d', strtotime($post['created_at'])); ?>
<?php if (!empty($post['excerpt'])): ?>
  <?php echo str_replace(["\r", "\n"], ' ', $post['excerpt']); ?>

<?php endif; ?>
<?php endforeach; ?>