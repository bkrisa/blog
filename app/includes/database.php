<?php
/**
 * Database class for handling SQLite database operations for the OpenBlog
 */
class Database {
  private $pdo;

  // Initialize database connection without auto-initialization
  public function __construct() {
    try {
      global $config;
      $dbPath = $config['database']['path'];

      $this->pdo = new PDO('sqlite:' . $dbPath);
      $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      
      $this->pdo->exec("PRAGMA journal_mode = WAL");

      $this->pdo->exec("PRAGMA foreign_keys = ON");
    } catch (PDOException $e) {
      throw new Exception("Database connection error: " . $e->getMessage());
    }
  }

  // Initialize database tables - now public so setup.php can call it
  public function initializeDatabase() {
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS posts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title VARCHAR(255) NOT NULL,
      slug VARCHAR(255) UNIQUE NOT NULL,
      content TEXT NOT NULL,
      excerpt VARCHAR(160),
      status VARCHAR(10) DEFAULT 'draft' CHECK(status IN ('draft', 'published')),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_slug ON posts(slug)");
    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status)");

    $this->pdo->exec("CREATE TABLE IF NOT EXISTS tags (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title VARCHAR(100) NOT NULL,
      slug VARCHAR(100) UNIQUE NOT NULL
    )");
    
    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_tags_slug ON tags(slug)");

    $this->pdo->exec("CREATE TABLE IF NOT EXISTS post_tags (
      post_id INTEGER NOT NULL,
      tag_id INTEGER NOT NULL,
      PRIMARY KEY (post_id, tag_id),
      FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
      FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    )");

    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag_id)");
  }

  // Prepare a SQL statement
  public function prepare($sql) {
    return $this->pdo->prepare($sql);
  }

  // Begin a transaction
  public function beginTransaction() {
    return $this->pdo->beginTransaction();
  }

  // Commit a transaction
  public function commit() {
    return $this->pdo->commit();
  }

  // Rollback a transaction
  public function rollBack() {
    return $this->pdo->rollBack();
  }

  // Get last insert ID
  public function lastInsertId() {
    return $this->pdo->lastInsertId();
  }
}