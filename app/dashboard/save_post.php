<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: editor.php');
  exit;
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : null;
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$tagsInput = trim($_POST['tags'] ?? '');
$status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

if ($title === '' || $content === '') {
  die('Title and content are required.');
}

function removeAccents(string $text): string {
  $map = [
    'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
    'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
    'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O',
    'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
    'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
    'ç' => 'c', 'č' => 'c',
    'è' => 'e', 'ê' => 'e', 'ë' => 'e',
    'ì' => 'i', 'î' => 'i', 'ï' => 'i',
    'ñ' => 'n',
    'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
    'ù' => 'u', 'û' => 'u',
    'ý' => 'y', 'ÿ' => 'y',
    'š' => 's', 'ž' => 'z',
  ];
  return strtr($text, $map);
}

// Slug generation
function slugify(string $text): string {
  $text = removeAccents($text);
  $text = mb_strtolower($text, 'UTF-8');
  $text = preg_replace('/[^a-z0-9]+/', '-', $text);
  return trim($text, '-');
}

/**
 * Description generation (~160 characters)
 */
function generateExcerpt(string $html, int $length = 160): string {
  $text = trim(strip_tags($html));
  $text = preg_replace('/\s+/', ' ', $text);

  if (mb_strlen($text) <= $length) {
    return $text;
  }

  $truncated = mb_substr($text, 0, $length);
  $lastSpace = mb_strrpos($truncated, ' ');

  if ($lastSpace !== false) {
    $truncated = mb_substr($truncated, 0, $lastSpace);
  }

  return $truncated . '…';
}

$db = new Database();
$isEdit = false;

if ($postId) {
  $existingStmt = $db->prepare("SELECT id, slug FROM posts WHERE id = ?");
  $existingStmt->execute([$postId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    die('The post to be edited cannot be found.');
  }

  $isEdit = true;
  $slug = $existing['slug'];
} else {
  // Ensuring slug uniqueness (title-2, title-3)
  $baseSlug = slugify($title);
  $slug = $baseSlug;
  $counter = 2;

  $checkStmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE slug = ?");
  while (true) {
    $checkStmt->execute([$slug]);
    if ($checkStmt->fetchColumn() == 0) {
      break;
    }
    $slug = $baseSlug . '-' . $counter;
    $counter++;
  }
}

$excerpt = generateExcerpt($content);

try {
  $db->beginTransaction();

  if ($isEdit) {
    $stmt = $db->prepare("
      UPDATE posts
      SET title = ?, content = ?, excerpt = ?, status = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?
    ");
    $stmt->execute([$title, $content, $excerpt, $status, $postId]);

    $db->prepare("DELETE FROM post_tags WHERE post_id = ?")->execute([$postId]);
  } else {
    $stmt = $db->prepare("
      INSERT INTO posts (title, slug, content, excerpt, status)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$title, $slug, $content, $excerpt, $status]);
    $postId = $db->lastInsertId();
  }

  // Processing tags
  if ($tagsInput !== '') {
    $tagNames = array_filter(array_map('trim', explode(',', $tagsInput)));

    $findTagStmt = $db->prepare("SELECT id FROM tags WHERE slug = ?");
    $insertTagStmt = $db->prepare("INSERT INTO tags (title, slug) VALUES (?, ?)");
    $linkStmt = $db->prepare("INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)");

    foreach ($tagNames as $tagName) {
      $tagSlug = slugify($tagName);

      if ($tagSlug === '') {
        continue;
      }

      $findTagStmt->execute([$tagSlug]);
      $tagId = $findTagStmt->fetchColumn();

      if (!$tagId) {
        $insertTagStmt->execute([$tagName, $tagSlug]);
        $tagId = $db->lastInsertId();
      }

      $linkStmt->execute([$postId, $tagId]);
    }
  }

  $db->commit();
} catch (Exception $e) {
  $db->rollBack();
  die('An error occurred while saving: ' . htmlspecialchars($e->getMessage()));
}

header('Location: editor.php?id=' . $postId . '&saved=1');
exit;