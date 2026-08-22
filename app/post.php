<?php
require_once __DIR__ . '/includes/config.php';

$page_title = "";
$page_description = "";
$page_url = "";

require_once __DIR__ . '/includes/header.php';
?>

<div class="">

  <!-- Twitter script -->
  <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>

  <!-- Automatic conversion script -->
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
    });
  </script>


  <div class="author-bio">
    <h3><?php echo htmlspecialchars($config['author']['name']); ?></h3>
    <p><?php echo getSafeDescription($config['author']['description'] ?? ''); ?></p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>