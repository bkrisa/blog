<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../../tailscale_auth.php';
verifyTailscaleAccess();

$db = new Database();

$postId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$post = null;
$postTags = [];

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
  $postTags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}

// All existing tags (shown in the tag popover)
$allTagsStmt = $db->prepare("SELECT title FROM tags ORDER BY title COLLATE NOCASE");
$allTagsStmt->execute();
$allTags = $allTagsStmt->fetchAll(PDO::FETCH_COLUMN);

$isPublished = $post && $post['status'] === 'published';

$editorData = [
  'tags'      => array_values($postTags),
  'allTags'   => array_values($allTags),
  'published' => (bool)$isPublished,
  'slug'      => (string)($post['slug'] ?? ''),
];

function bar_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = $post ? "Editing: " . $post['title'] : "Post editor";
$page_description = "";
$page_url = "";
require_once __DIR__ . '/../includes/header.php';

$slugPrefix = function_exists('getRootPath') ? getRootPath() : '/';
?>

<div class="editor">
  <a href="/blog/dashboard/" style="font-size: 14px;margin-bottom: 10px;">← Back dashboard</a>

  <form action="save_post.php" method="POST" id="mde-form">
    <?php if ($post): ?>
      <input type="hidden" name="post_id" value="<?php echo (int)$post['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="tags" id="mde-tags-value" value="<?php echo bar_h(implode(', ', $postTags)); ?>">

    <input
      type="text"
      name="title"
      class="post-title"
      id="mde-title"
      placeholder="Post title"
      required
      autofocus
      value="<?php echo htmlspecialchars($post['title'] ?? ''); ?>"
    >
    <textarea id="my-editor" name="content"><?php echo htmlspecialchars($post['content'] ?? ''); ?></textarea>

    <div class="mde-bar">
      <div class="mde-bar-inner">
        <div class="mde-bar-left">
          <div class="mde-pop-wrap">
            <button type="button" class="mde-icon" id="btn-tags" title="Tags" aria-label="Tags" aria-haspopup="true" aria-expanded="false">
              <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-tag" viewBox="0 0 16 16">
                <path d="M6 4.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m-1 0a.5.5 0 1 0-1 0 .5.5 0 0 0 1 0"/>
                <path d="M2 1h4.586a1 1 0 0 1 .707.293l7 7a1 1 0 0 1 0 1.414l-4.586 4.586a1 1 0 0 1-1.414 0l-7-7A1 1 0 0 1 1 6.586V2a1 1 0 0 1 1-1m0 5.586 7 7L13.586 9l-7-7H2z"/>
              </svg>
              <span class="mde-badge" id="tag-count" hidden></span>
            </button>
            <div class="mde-pop" id="pop-tags" hidden>
              <label for="mde-tag-input">Add a tag</label>
              <input type="text" id="mde-tag-input" placeholder="Type, then Enter" autocomplete="off" maxlength="100">
              <div class="mde-chips" id="mde-selected" aria-label="Tags on this post"></div>
              <p class="mde-sub" id="mde-existing-label">Existing tags</p>
              <div class="mde-chips" id="mde-existing"></div>
            </div>
          </div>

          <div class="mde-pop-wrap">
            <button type="button" class="mde-icon" id="btn-url" title="URL" aria-label="URL" aria-haspopup="true" aria-expanded="false">
              <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-link-45deg" viewBox="0 0 16 16">
                <path d="M4.715 6.542 3.343 7.914a3 3 0 1 0 4.243 4.243l1.828-1.829A3 3 0 0 0 8.586 5.5L8 6.086a1 1 0 0 0-.154.199 2 2 0 0 1 .861 3.337L6.88 11.45a2 2 0 1 1-2.83-2.83l.793-.792a4 4 0 0 1-.128-1.287z"/>
                <path d="M6.586 4.672A3 3 0 0 0 7.414 9.5l.775-.776a2 2 0 0 1-.896-3.346L9.12 3.55a2 2 0 1 1 2.83 2.83l-.793.792c.112.42.155.855.128 1.287l1.372-1.372a3 3 0 1 0-4.243-4.243z"/>
              </svg>
            </button>
            <div class="mde-pop" id="pop-url" hidden>
              <label for="mde-slug">Post URL</label>
              <div class="mde-url">
                <span><?php echo bar_h($slugPrefix); ?></span>
                <input type="text" id="mde-slug" name="slug" value="<?php echo bar_h($post['slug'] ?? ''); ?>" placeholder="post-url" autocomplete="off" maxlength="200">
              </div>
              <p class="mde-hint" id="mde-slug-hint">Leave it empty to create it from the title.</p>
            </div>
          </div>
        </div>

        <div class="mde-bar-right">
          <div class="mde-split">
            <button type="submit" name="status" value="published" class="mde-publish" id="btn-publish">
              <?php echo $isPublished ? 'Update' : 'Publish'; ?>
            </button>
            <button type="button" class="mde-split-toggle" id="btn-more" aria-label="More save options" aria-haspopup="true" aria-expanded="false">
              <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-chevron-up" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M7.646 4.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1-.708.708L8 5.707l-5.646 5.647a.5.5 0 0 1-.708-.708z"/>
              </svg>
            </button>
            <div class="mde-menu" id="menu-more" hidden>
              <button type="submit" name="status" value="draft" id="btn-draft">
                <?php echo $isPublished ? 'Unpublish (save as draft)' : 'Save draft'; ?>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>

  <script type="application/json" id="mde-data"><?php echo json_encode($editorData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>

  <script>
    tinymce.init({
      selector: '#my-editor',
      height: 600,
      menubar: false,
      content_style: `
        body {max-width: 100%;}
        img {
          display: block;
          max-width: 100%;
          max-height: 800px;
          height: auto;
          margin-inline: auto;
        }
        table {width: 100%; border-collapse: collapse;}
        th, td {border: 1px solid #ddd; padding: 6px 10px;}
        hr {border: 0; border-top: 1px solid #ccc;}
      `,
      plugins: 'link image code table lists',
      toolbar: 'blocks bold italic underline strikethrough | bullist numlist link image table hr code',
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

        // Unsaved changes warning
        editor.on('input change', () => { window.mdeDirty = true; });
      }
    });
  </script>

  <script>
  (function () {
    'use strict';

    var data = JSON.parse(document.getElementById('mde-data').textContent);
    var $ = function (id) { return document.getElementById(id); };
    var form = $('mde-form'), title = $('mde-title');
    var submitting = false;
    function markDirty() { window.mdeDirty = true; }
    title.addEventListener('input', markDirty);

  /* ---------- popovers (tags, URL, save menu) ---------- */
  var pops = [
    { btn: $('btn-tags'), pop: $('pop-tags'), onClose: function () { commitTagInput(); } },
    { btn: $('btn-url'), pop: $('pop-url') },
    { btn: $('btn-more'), pop: $('menu-more') }
  ];
  function closePop(p) {
    if (p.pop.hidden) { return; }
    p.pop.hidden = true;
    p.btn.setAttribute('aria-expanded', 'false');
    if (p.onClose) { p.onClose(); }
  }
  function closeAll(except) { pops.forEach(function (p) { if (p !== except) { closePop(p); } }); }
  pops.forEach(function (p) {
    p.btn.addEventListener('click', function () {
      if (!p.pop.hidden) { closePop(p); return; }
      closeAll(p);
      p.pop.hidden = false;
      p.btn.setAttribute('aria-expanded', 'true');
      var field = p.pop.querySelector('input[type=text]');
      if (field) { field.focus(); }
    });
  });
  document.addEventListener('click', function (e) {
    pops.forEach(function (p) {
      var path = e.composedPath ? e.composedPath() : [];
      // composedPath: a clicked chip may already be re-rendered (detached) when this runs
      if (!p.pop.hidden && path.indexOf(p.pop) === -1 && path.indexOf(p.btn) === -1) { closePop(p); }
    });
  });

  /* ---------- tags ---------- */
  var tags = data.tags.slice();
  var allTags = data.allTags;
  var tagInput = $('mde-tag-input'), tagsValue = $('mde-tags-value'), tagCount = $('tag-count');
  var selectedBox = $('mde-selected'), existingBox = $('mde-existing');

  function cleanTag(t) { return t.replace(/[,\n\r]+/g, ' ').replace(/\s+/g, ' ').trim(); }
  function hasTag(t) { return tags.some(function (x) { return x.toLowerCase() === t.toLowerCase(); }); }
  function addTag(raw) {
    var t = cleanTag(raw);
    if (!t || hasTag(t)) { return; }
    var existing = allTags.filter(function (x) { return x.toLowerCase() === t.toLowerCase(); })[0];
    tags.push(existing || t);
    renderTags(true);
  }
  function removeTag(t) {
    tags = tags.filter(function (x) { return x.toLowerCase() !== t.toLowerCase(); });
    renderTags(true);
  }
  function chip(label, on, onClick, prefix) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'mde-chip' + (on ? ' on' : '');
    b.textContent = (prefix || '') + label;
    b.addEventListener('click', onClick);
    return b;
  }
  function renderTags(changed) {
    tagsValue.value = tags.join(', ');
    tagCount.hidden = tags.length === 0;
    tagCount.textContent = String(tags.length);

    selectedBox.textContent = '';
    tags.forEach(function (t) {
      var c = chip(t, true, function () { removeTag(t); }, '');
      c.textContent = t + ' ×';
      c.setAttribute('aria-label', 'Remove tag ' + t);
      selectedBox.appendChild(c);
    });

    existingBox.textContent = '';
    allTags.forEach(function (t) {
      var on = hasTag(t);
      var c = chip(t, on, function () { if (hasTag(t)) { removeTag(t); } else { addTag(t); } });
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
      existingBox.appendChild(c);
    });
    $('mde-existing-label').hidden = allTags.length === 0;
    if (changed) { markDirty(); }
  }
  function commitTagInput() {
    if (tagInput.value.trim()) { addTag(tagInput.value); }
    tagInput.value = '';
  }
  tagInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); commitTagInput(); }
    else if (e.key === 'Backspace' && tagInput.value === '' && tags.length) { removeTag(tags[tags.length - 1]); }
  });
  renderTags(false);

  /* ---------- post URL ---------- */
  var slugInput = $('mde-slug'), slugHint = $('mde-slug-hint');
  function slugify(s) {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  function updateSlugUi() {
    slugInput.placeholder = slugify(title.value) || 'post-url';
    var changed = data.published && data.slug && slugInput.value.trim() !== data.slug;
    slugHint.className = 'mde-hint' + (changed ? ' warn' : '');
    slugHint.textContent = changed
      ? 'This post is public. Changing its URL breaks links that already point to it.'
      : 'Leave it empty to create it from the title.';
  }
  title.addEventListener('input', updateSlugUi);
  slugInput.addEventListener('input', function () { updateSlugUi(); markDirty(); });
  updateSlugUi();


    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(); } });

    form.addEventListener('submit', function () {
      commitTagInput();
      submitting = true;
    });
    window.addEventListener('beforeunload', function (e) {
      if (window.mdeDirty && !submitting) { e.preventDefault(); e.returnValue = ''; }
    });
  })();
  </script>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
