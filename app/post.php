<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/settings.php';

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

$page_title = $post['title'];
$page_description = $post['excerpt'] ?? '';
$page_url = rtrim($settings['site']['blog_url'], '/') . '/post.php?slug=' . urlencode($post['slug']);

require_once __DIR__ . '/includes/header.php';
?>

<div class="post">

  <article>
    <h1><?php echo htmlspecialchars($post['title']); ?></h1>

    <time datetime="<?php echo htmlspecialchars($post['created_at']); ?>">
      <?php echo htmlspecialchars(date('Y.m.d.', strtotime($post['created_at']))); ?>
    </time>

    <div class="post-content">
      <?php echo $post['content']; ?>
    </div>

    <?php if (!empty($tags)): ?>
      <div class="post-tags">
        <?php foreach ($tags as $tag): ?>
          <a href="<?php echo htmlspecialchars($root_path); ?>tags.php?slug=<?php echo urlencode($tag['slug']); ?>">
            <?php echo htmlspecialchars($tag['title']); ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>

  <!-- Twitter widgets -->
  <script>
    window.twttr = (function(d, s, id) {
      var js, fjs = d.getElementsByTagName(s)[0],
        t = window.twttr || {};
      if (d.getElementById(id)) return t;
      js = d.createElement(s);
      js.id = id;
      js.src = "https://platform.twitter.com/widgets.js";
      fjs.parentNode.insertBefore(js, fjs);

      t._e = [];
      t.ready = function(f) {
        t._e.push(f);
      };

      return t;
    }(document, "script", "twitter-wjs"));
  </script>

  <!-- Automatically convert tweet links to blockquotes -->
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const links = document.querySelectorAll('.post-content a');

      links.forEach(link => {
        const url = link.href;
        if (url.match(/^https?:\/\/(www\.)?(twitter|x)\.com\/[a-zA-Z0-9_]+\/status\/\d+/)) {
          const blockquote = document.createElement('blockquote');
          blockquote.className = 'twitter-tweet';
          blockquote.innerHTML = `<a href="${url}"></a>`;

          link.parentNode.replaceChild(blockquote, link);
        }
      });

      window.twttr.ready(function(twttr) {
        twttr.widgets.load();
      });
    });
  </script>

  <div class="author-bio">
    <h3><?php echo htmlspecialchars($settings['author']['name']); ?></h3>
    <p><?php echo getSafeDescription($settings['author']['description'] ?? ''); ?></p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>