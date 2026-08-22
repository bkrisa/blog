<?php
require_once __DIR__ . '/app/includes/config.php';

$dbPath = $config['database']['path'];
$dbDir = dirname($dbPath);

if (!is_dir($dbDir)) {
  mkdir($dbDir, 0755, true);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app/includes/database.php';
$db = new Database();
$db->initializeDatabase();

echo "Database created: $dbPath\n";
?>