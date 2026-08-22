<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : null;

if (!$postId) {
  die('Hiányzó poszt azonosító.');
}

$db = new Database();

// A post_tags kapcsolatok automatikusan törlődnek az ON DELETE CASCADE
// miatt (feltéve, hogy a PRAGMA foreign_keys = ON be van kapcsolva
// a Database osztály konstruktorában)
$stmt = $db->prepare("DELETE FROM posts WHERE id = ?");
$stmt->execute([$postId]);

header('Location: index.php?deleted=1');
exit;