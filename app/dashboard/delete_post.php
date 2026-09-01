<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : null;

if (!$postId) {
  die('The post ID is missing.');
}

$db = new Database();

$stmt = $db->prepare("DELETE FROM posts WHERE id = ?");
$stmt->execute([$postId]);

header('Location: index.php?deleted=1');
exit;