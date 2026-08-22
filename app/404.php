<?php
$page_title = "404 - Page not found";
$page_description = "The requested page was not found on the website.";
$page_url = "";

require_once __DIR__ . '/includes/header.php';
?>

<div class="error-page">
  <h1>404 - Page not found</h1>
  <p>The page you are looking for doesn't exist.</p>
  <a href="<?php echo htmlspecialchars($root_path); ?>">Back to all posts</a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>