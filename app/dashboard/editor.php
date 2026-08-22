<?php
require_once __DIR__ . '/../includes/config.php';
$page_title = "Post editor";
$page_description = "";
$page_url = "";
require_once __DIR__ . '/../includes/header.php';
?>


<div class="">

  <form action="save_post.php" method="POST">
    <input type="text" name="title" class="post-title" placeholder="Post title" required autofocus>
    <textarea id="my-editor" name="content"></textarea>
    <button type="submit">Save</button>
  </form>

  <form action="save_post.php" method="POST">
  <input type="text" name="title" class="post-title" placeholder="Post title" required autofocus>

  <input type="text" name="tags" class="post-tags" placeholder="tag1, tag2, tag3">

  <textarea id="my-editor" name="content"></textarea>

  <button type="submit" name="status" value="draft">Save draft</button>
  <button type="submit" name="status" value="published">Publish</button>
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

      // Image upload
      images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('file', blobInfo.blob(), blobInfo.filename());

        fetch('includes/upload.php', {
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