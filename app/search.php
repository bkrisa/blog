<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/search.php';

$settings = loadSettings();
$db = new Database();

$q = trim((string)($_GET['q'] ?? ''));
$results = [];
$failed = false;

if ($q !== '') {
  try {
    $results = searchPosts($db, $q);
  } catch (Throwable $e) {
    error_log('Search failed: ' . $e->getMessage());
    $failed = true;
  }
}

// Search result pages should not end up in Google
header('X-Robots-Tag: noindex, follow');

$page_title = 'Search';
$page_description = '';
$page_url = rtrim($settings['site']['blog_url'], '/') . '/search';

$search_open = true;   // on the search page the bar is always open
$search_query = $q;    // pre-fill with what was searched

require_once __DIR__ . '/includes/header.php';
?>

<div class="post-list search-page">
  <?php if ($failed): ?>
    <p>Search is temporarily unavailable.</p>
  <?php elseif ($q !== '' && empty($results)): ?>
    <p>No posts found for “<?php echo htmlspecialchars($q); ?>”.</p>
  <?php elseif ($q !== ''): ?>
    <p class="search-count"><?php echo count($results); ?> result<?php echo count($results) === 1 ? '' : 's'; ?></p>
  <?php endif; ?>

  <?php foreach ($results as $r): ?>
    <article class="post-preview">
      <div>
        <time datetime="<?php echo htmlspecialchars($r['created_at']); ?>">
          <?php echo htmlspecialchars(date('F d, Y', strtotime($r['created_at']))); ?>
        </time>
        <h2>
          <a href="<?php echo htmlspecialchars($root_path . $r['slug']); ?>"><?php echo $r['title_html']; ?></a>
        </h2>
        <?php if ($r['snippet_html'] !== ''): ?>
          <p class="post-excerpt"><?php echo $r['snippet_html']; ?></p>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
