<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

// Set XML header
header('Content-Type: application/xml; charset=utf-8');

$settings = loadSettings();
$baseUrl = rtrim($settings['site']['blog_url'], '/');

$db = new Database();

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Static pages
$staticPages = [
  ['url' => '/', 'priority' => '1.0', 'changefreq' => 'daily'],
];

foreach ($staticPages as $page) {
  echo "  <url>\n";
  echo "    <loc>" . htmlspecialchars($baseUrl . $page['url']) . "</loc>\n";
  echo "    <priority>" . $page['priority'] . "</priority>\n";
  echo "    <changefreq>" . $page['changefreq'] . "</changefreq>\n";
  echo "  </url>\n";
}

// Close XML
echo '</urlset>';