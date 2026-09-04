<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Load settings from JSON file
require_once __DIR__ . '/../includes/settings.php';
$settings = loadSettings();
$root_path = getRootPath();

function getSafeDescription(string $rawDesc): string {
  $cleanHtml = strip_tags($rawDesc, '<a>');
  
  $cleanHtml = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $cleanHtml);
  $cleanHtml = preg_replace('/href\s*=\s*["\']?\s*javascript:[^"\'>]*["\']?/i', 'href="#"', $cleanHtml);

  $cleanHtml = preg_replace_callback('/<a\s+([^>]*+)>/i', function($matches) {
    $attributes = $matches[1];
    $attributes = preg_replace('/\s*target\s*=\s*["\'][^"\']*["\']/i', '', $attributes);
    $attributes = preg_replace('/\s*rel\s*=\s*["\'][^"\']*["\']/i', '', $attributes);

    return '<a ' . trim($attributes) . ' target="_blank" rel="noopener">';
  }, $cleanHtml);

  return $cleanHtml;
}

// Check if the current page is a dashboard page
$uri = $_SERVER['REQUEST_URI'];
// $is_dashboard_page = str_starts_with($uri, $root_path . 'dashboard');
$is_dashboard_page = (strpos($uri, '/dashboard/') !== false);

// Security constant definition
if (!defined('SECURE_ACCESS')) {
  define('SECURE_ACCESS', true);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">

  <title><?php echo htmlspecialchars($page_title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">

  <!-- dashboard -->
  <?php if(!$is_dashboard_page): ?>
    <meta name="robots" content="index, follow">
  <?php else: ?>
    <meta name="robots" content="noindex, nofollow">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($root_path); ?>assets/css/dashboard.css">
  <?php endif; ?>

  <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
  <meta property="og:url" content="<?php echo htmlspecialchars($page_url); ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?php echo htmlspecialchars($settings['site']['name']); ?>">
  <meta property="og:image" content="<?php echo htmlspecialchars($settings['site']['logo']); ?>">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo htmlspecialchars($page_title); ?>">
  <meta name="twitter:description" content="<?php echo htmlspecialchars($page_description); ?>">
  <meta name="twitter:image" content="<?php echo htmlspecialchars($settings['site']['logo']); ?>">
  <meta name="twitter:image:alt" content="<?php echo htmlspecialchars($settings['site']['name']); ?>">
  <meta name="twitter:image:src" content="<?php echo htmlspecialchars($settings['site']['logo']); ?>">

  <link rel="canonical" href="<?php echo htmlspecialchars($page_url); ?>" />

  <!-- favicon -->
  <?php if (!empty($settings['site']['logo'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($settings['site']['logo']); ?>" type="image/x-icon">
  <?php endif; ?>

  <!-- script -->
  <script src="<?php echo htmlspecialchars($root_path); ?>assets/js/main.js" defer></script>

  <!-- styles -->
  <link rel="stylesheet" href="<?php echo htmlspecialchars($root_path); ?>assets/css/blog.css">
  <style>
    :root {
      --main: <?php echo htmlspecialchars($settings['design']['colors']['main']) ?>;
      --heading: <?php echo htmlspecialchars($settings['design']['colors']['heading']) ?>;
      --text: <?php echo htmlspecialchars($settings['design']['colors']['text']) ?>;
      --bg: <?php echo htmlspecialchars($settings['design']['colors']['bg']) ?>;
      --font: <?php echo htmlspecialchars($settings['design']['fonts']['primary']) ?>;
    }
  </style>

  <!-- Schema.org -->
  <?php if (isset($schema)) echo $schema; ?>
</head>

<body class="">
  <header id="header">
    <a href="<?php echo htmlspecialchars($root_path); ?>" class="logo-area">
      <?php if (!empty($settings['site']['logo'])): ?>
        <img src="<?php echo htmlspecialchars($settings['site']['logo']); ?>" alt="<?php echo htmlspecialchars($settings['site']['name']); ?>" class="logo-icon" loading="lazy">
      <?php endif; ?>
      <span class="logo-text"><?php echo htmlspecialchars($settings['site']['name']); ?></span>
    </a>

    <?php if (!$is_dashboard_page): ?>
      <nav>
        <ul>
          <?php foreach ($settings['navigation'] as $item): ?>
            <li>
              <a href="<?php echo htmlspecialchars($item['url']); ?>">
                <?php echo htmlspecialchars($item['label']); ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>
    <?php else: ?>
      <nav>
        <ul>
          <li>
            <a href="/blog/dashboard">Dashboard</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </header>
  <main>
