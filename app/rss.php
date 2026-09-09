<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/rss+xml; charset=utf-8');

$settings = loadSettings();
$blogUrl = rtrim($settings['site']['blog_url'], '/');
$db = new Database();

function cdata(string $text): string {
  $safe = str_replace(']]>', ']]]]><![CDATA[>', $text);
  return '<![CDATA[' . $safe . ']]>';
}

function absolutizeImages(string $html): string {
  return preg_replace_callback(
    '/<img([^>]+)src=["\']([^"\']+)["\']/i',
    function ($matches) {
      $src = toAbsoluteUrl($matches[2]);
      return '<img' . $matches[1] . 'src="' . htmlspecialchars($src) . '"';
    },
    $html
  );
}

$stmt = $db->prepare("
  SELECT id, title, slug, excerpt, content, created_at
  FROM posts
  WHERE status = 'published'
  ORDER BY created_at DESC
  LIMIT 20
");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tagStmt = $db->prepare("
  SELECT t.title
  FROM tags t
  INNER JOIN post_tags pt ON pt.tag_id = t.id
  WHERE pt.post_id = ?
  ORDER BY pt.rowid
");

$lastBuildDate = !empty($posts) ? date('r', strtotime($posts[0]['created_at'])) : date('r');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
  <title><?php echo htmlspecialchars($settings['site']['name']); ?></title>
  <link><?php echo htmlspecialchars($blogUrl); ?></link>
  <description><?php echo htmlspecialchars($settings['author']['description'] ?? ''); ?></description>
  <language>en-us</language>
  <atom:link href="<?php echo htmlspecialchars($blogUrl); ?>/rss" rel="self" type="application/rss+xml" />
  <lastBuildDate><?php echo htmlspecialchars($lastBuildDate); ?></lastBuildDate>

  <?php foreach ($posts as $post): ?>
    <?php
      $link = $blogUrl . '/' . rawurlencode($post['slug']);
      $pubDate = date('r', strtotime($post['created_at']));

      $tagStmt->execute([$post['id']]);
      $tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

      $fullContent = absolutizeImages($post['content']);
    ?>
    <item>
      <title><?php echo htmlspecialchars($post['title']); ?></title>
      <link><?php echo htmlspecialchars($link); ?></link>
      <guid isPermaLink="true"><?php echo htmlspecialchars($link); ?></guid>
      <pubDate><?php echo htmlspecialchars($pubDate); ?></pubDate>
      <description><?php echo cdata($post['excerpt'] ?? ''); ?></description>
      <content:encoded><?php echo cdata($fullContent); ?></content:encoded>
      <?php foreach ($tags as $tagTitle): ?>
        <category><?php echo htmlspecialchars($tagTitle); ?></category>
      <?php endforeach; ?>
    </item>
  <?php endforeach; ?>
</channel>
</rss>