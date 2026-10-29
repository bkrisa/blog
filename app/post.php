<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/schema.php';

$settings = loadSettings();
$db = new Database();

$slug = $_GET['slug'] ?? '';

if ($slug === '') {
  http_response_code(404);
  die('Post not found.');
}

$stmt = $db->prepare("SELECT * FROM posts WHERE slug = ? AND status = 'published'");
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
  http_response_code(404);
  die('Post not found.');
}

$tagStmt = $db->prepare("
  SELECT t.title, t.slug
  FROM tags t
  INNER JOIN post_tags pt ON pt.tag_id = t.id
  WHERE pt.post_id = ?
  ORDER BY pt.rowid
");
$tagStmt->execute([$post['id']]);
$tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

// schema
$schema = generate_blog_post_schema($settings, $post, $tags);

$firstImage = getFirstImage($post['content']);

$page_image = $firstImage
  ? toAbsoluteUrl($firstImage)
  : toAbsoluteUrl($root_path . 'assets/img/' . $settings['site']['logo']);

$page_title = $post['title'];
$page_description = $post['excerpt'] ?? '';
$page_url = rtrim($settings['site']['blog_url'], '/') . '/' . $post['slug'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="post">
  <article>
    <div class="post-header">
      <h1><?php echo htmlspecialchars($post['title']); ?></h1>

      <div class="post-meta">
        <time datetime="<?php echo htmlspecialchars($post['created_at']); ?>">
          <?php echo htmlspecialchars(date('F d, Y', strtotime($post['created_at']))); ?>
        </time>

        <?php if (!empty($tags)): ?>
          -
          <div class="post-tags">
            <?php foreach ($tags as $tag): ?>
              <a href="<?php echo htmlspecialchars($root_path . 'tag/' . $tag['slug']); ?>"><?php echo htmlspecialchars($tag['title']); ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="post-content">
      <?php echo addHeadingIds($post['content']); ?>
    </div>
  </article>

  <!-- Convert standalone tweet URLs to embedded tweets -->
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const tweetPattern = /^https?:\/\/(www\.)?(twitter|x)\.com\/[a-zA-Z0-9_]+\/status\/\d+/;
      const normalize = s => s.trim().replace(/\/+$/, '');
      let converted = 0;

      document.querySelectorAll('.post-content a').forEach(link => {
        const url = link.href;
        if (!tweetPattern.test(url)) return;

        const paragraph = link.parentElement;
        if (!paragraph || paragraph.tagName !== 'P') return;
        if (normalize(paragraph.textContent) !== normalize(link.textContent)) return;
        if (normalize(link.textContent) !== normalize(link.getAttribute('href'))) return;

        const blockquote = document.createElement('blockquote');
        blockquote.className = 'twitter-tweet';
        blockquote.innerHTML = `<a href="${url}"></a>`;
        paragraph.replaceWith(blockquote);
        converted++;
      });

      // widgets.js only loads if there is a tweet on the page
      if (converted > 0) {
        const s = document.createElement('script');
        s.src = 'https://platform.twitter.com/widgets.js';
        s.async = true;
        s.charset = 'utf-8';
        document.body.appendChild(s);
      }
    });
  </script>

  <div class="author-bio">
    <b>Written by</b>
    <div class="author-info">
      <img src="<?php echo htmlspecialchars($settings['site']['logo']); ?>" alt="<?php echo htmlspecialchars($settings['author']['name']); ?>">
      <div>
        <h3><?php echo htmlspecialchars($settings['author']['name']); ?></h3>
        <p><?php echo getSafeDescription($settings['author']['description'] ?? ''); ?></p>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
