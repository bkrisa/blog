<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/xml; charset=utf-8');

$settings = loadSettings();
$baseUrl = rtrim($settings['site']['blog_url'], '/');
$db = new Database();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

function sitemapUrl(string $loc, string $priority, string $changefreq, ?string $lastmod = null): void {
  echo "  <url>\n";
  echo "    <loc>" . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
  if ($lastmod) {
    echo "    <lastmod>" . htmlspecialchars($lastmod, ENT_XML1, 'UTF-8') . "</lastmod>\n";
  }
  echo "    <changefreq>{$changefreq}</changefreq>\n";
  echo "    <priority>{$priority}</priority>\n";
  echo "  </url>\n";
}

sitemapUrl($baseUrl . '/', '1.0', 'daily');

$stmt = $db->prepare("
  SELECT slug, created_at, updated_at
  FROM posts
  WHERE status = 'published'
  ORDER BY COALESCE(updated_at, created_at) DESC
");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($posts as $post) {
  $loc = $baseUrl . '/' . rawurlencode($post['slug']);

  $raw = $post['updated_at'] ?: $post['created_at'];
  $lastmod = $raw ? date('Y-m-d', strtotime($raw)) : null;

  sitemapUrl($loc, '0.8', 'weekly', $lastmod);
}
echo '</urlset>';