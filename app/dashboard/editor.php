<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$db = new Database();

$postId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$post = null;
$tagsValue = '';

if ($postId) {
  $stmt = $db->prepare("SELECT * FROM posts WHERE id = ?");
  $stmt->execute([$postId]);
  $post = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$post) {
    die('Post not found.');
  }

  $tagStmt = $db->prepare("
    SELECT t.title
    FROM tags t
    INNER JOIN post_tags pt ON pt.tag_id = t.id
    WHERE pt.post_id = ?
    ORDER BY pt.rowid
  ");
  $tagStmt->execute([$postId]);
  $tagTitles = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
  $tagsValue = implode(', ', $tagTitles);
}

$page_title = $post ? "Editing: " . $post['title'] : "Post editor";
$page_description = "";
$page_url = "";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="editor">
  <a href="/blog/dashboard/" style="font-size: 14px;margin-bottom: 10px;">← Back dashboard</a>

  <form action="save_post.php" method="POST">
    <?php if ($post): ?>
      <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
    <?php endif; ?>

    <input
      type="text"
      name="title"
      class="post-title"
      placeholder="Post title"
      required
      autofocus
      value="<?php echo htmlspecialchars($post['title'] ?? ''); ?>"
    >

    <input
      type="text"
      name="tags"
      class="post-tags"
      placeholder="tag1, tag2, tag3"
      value="<?php echo htmlspecialchars($tagsValue); ?>"
    >

    <textarea id="my-editor" name="content"><?php echo htmlspecialchars($post['content'] ?? ''); ?></textarea>

    <button type="submit" name="status" value="published">Publish</button>
    <button type="submit" name="status" value="draft">Save draft</button>
  </form>

  <script>
    tinymce.init({
      selector: '#my-editor',
      height: 400,
      menubar: false,
      plugins: 'link image code table lists',
      toolbar: 'blocks bold italic underline strikethrough superscript subscript | alignleft aligncenter alignright | bullist numlist link image code',
      promotion: false,
      branding: false,
      relative_urls: false,
      remove_script_host: false,
      convert_urls: true,

      // Image upload
      images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('file', blobInfo.blob(), blobInfo.filename());

        fetch('/blog/dashboard/upload.php', {
          method: 'POST',
          body: formData
        })
        .then(response => {
          if (!response.ok) throw new Error('HTTP error: ' + response.status);
          return response.json();
        })
        .then(data => {
          if (data && data.location) {
            resolve(data.location);
          } else {
            reject(data.error || 'Error during upload.');
          }
        })
        .catch(error => reject('Upload error: ' + error.message));
      }),

      // Automatic ALT
      setup: (editor) => {
        const autoFillAlt = () => {
          const imgs = editor.dom.select('img');
          imgs.forEach(img => {
            const alt = img.getAttribute('alt');
            const src = img.getAttribute('src');

            if ((!alt || alt.trim() === '') && src) {
              const filename = src.split('/').pop().replace(/\.[^/.]+$/, '');
              img.setAttribute('alt', filename);
            }
          });
        };

        editor.on('SetContent ExecCommand NodeChange', autoFillAlt);
      }
    });
  </script>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
