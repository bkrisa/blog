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

// Related posts: shared tags, rarer tags count more
$relatedStmt = $db->prepare("
  SELECT p.title, p.slug, p.content, p.created_at, SUM(1.0 / tc.cnt) AS score
  FROM post_tags pt
  JOIN posts p ON p.id = pt.post_id
  JOIN (SELECT tag_id, COUNT(*) AS cnt FROM post_tags GROUP BY tag_id) tc ON tc.tag_id = pt.tag_id
  WHERE pt.tag_id IN (SELECT tag_id FROM post_tags WHERE post_id = ?)
    AND p.id != ?
    AND p.status = 'published'
  GROUP BY p.id
  ORDER BY score DESC, p.created_at DESC
  LIMIT 3
");
$relatedStmt->execute([$post['id'], $post['id']]);
$relatedPosts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

// Previous (older) and next (newer) published posts
$prevStmt = $db->prepare("
  SELECT title, slug, created_at FROM posts
  WHERE status = 'published'
    AND (created_at < ? OR (created_at = ? AND id < ?))
  ORDER BY created_at DESC, id DESC
  LIMIT 1
");
$prevStmt->execute([$post['created_at'], $post['created_at'], $post['id']]);
$prevPost = $prevStmt->fetch(PDO::FETCH_ASSOC);

$nextStmt = $db->prepare("
  SELECT title, slug, created_at FROM posts
  WHERE status = 'published'
    AND (created_at > ? OR (created_at = ? AND id > ?))
  ORDER BY created_at ASC, id ASC
  LIMIT 1
");
$nextStmt->execute([$post['created_at'], $post['created_at'], $post['id']]);
$nextPost = $nextStmt->fetch(PDO::FETCH_ASSOC);

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

  <?php if (!empty($relatedPosts)): ?>
    <section class="related-posts">
      <h3>Related posts</h3>
      <ul>
        <?php foreach ($relatedPosts as $rel): ?>
          <?php $thumbnail = getFirstImage($rel['content']); ?>
          <li>
            <a href="<?php echo htmlspecialchars($root_path . $rel['slug']); ?>">
              <?php if ($thumbnail): ?>
                <img src="<?php echo htmlspecialchars($thumbnail); ?>" alt="<?php echo htmlspecialchars($rel['title']); ?>">
              <?php endif; ?>
              <?php echo htmlspecialchars($rel['title']); ?>
            </a>
            <time datetime="<?php echo htmlspecialchars($rel['created_at']); ?>">
              <?php echo htmlspecialchars(date('F d, Y', strtotime($rel['created_at']))); ?>
            </time>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($prevPost || $nextPost): ?>
    <nav class="post-nav" aria-label="Post navigation">
      <?php if ($prevPost): ?>
        <a class="post-nav-prev" href="<?php echo htmlspecialchars($root_path . $prevPost['slug']); ?>" rel="prev" title="<?php echo htmlspecialchars(date('m.d.Y', strtotime($prevPost['created_at']))); ?>">
          ⟵ <?php echo htmlspecialchars($prevPost['title']); ?>
        </a>
      <?php endif; ?>
      <?php if ($nextPost): ?>
        <a class="post-nav-next" href="<?php echo htmlspecialchars($root_path . $nextPost['slug']); ?>" rel="next" title="<?php echo htmlspecialchars(date('m.d.Y', strtotime($nextPost['created_at']))); ?>">
          <?php echo htmlspecialchars($nextPost['title']); ?> ⟶
        </a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

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
