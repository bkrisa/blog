<?php
// Session settings
ini_set('session.gc_maxlifetime', 604800); // 7 days
ini_set('session.cookie_lifetime', 604800); // 7 days
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');

// Load environment variables and functions
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/breadcrumbs.php';

// Site settings
$config = [
  'database' => [
    'path' => $_ENV['DB_PATH'] ?? dirname(__DIR__, 2) . '/data/database.db',
  ],
];

// Global configuration
$GLOBALS['config'] = $config;

// Character encoding
ini_set('default_charset', 'UTF-8');

// Start session
if (session_status() === PHP_SESSION_NONE) {
  session_start();

  // Session Fixation Protection
  // Regenerate session ID on first visit
  if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
    $_SESSION['created'] = time();
  }

  // Regenerate session ID every 30 minutes (WITHOUT deleting old session)
  if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
  } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
    session_regenerate_id(false); // false = don't delete the old session file
    $_SESSION['last_regeneration'] = time();
  }
}