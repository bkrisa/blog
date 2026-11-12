<?php
/**
 * Full-text search for OpenBlog, built on SQLite FTS5
 */

function searchEnsureTable($db): void {
  $db->prepare("
    CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
      title, content, tags,
      tokenize = 'unicode61 remove_diacritics 2'
    )
  ")->execute();
}

// HTML -> plain text (tags removed, entities decoded, whitespace collapsed)
function searchPlainText(string $html): string {
  $text = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
  $text = preg_replace('/<[^>]+>/', ' ', $text);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return trim(preg_replace('/\s+/u', ' ', $text));
}

// Add or update one post in the index (drafts included)
function searchIndexPost($db, int $postId): void {
  searchEnsureTable($db);
  $db->prepare("DELETE FROM posts_fts WHERE rowid = ?")->execute([$postId]);

  $stmt = $db->prepare("SELECT title, content FROM posts WHERE id = ?");
  $stmt->execute([$postId]);
  $post = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$post) {
    return;
  }

  $tagStmt = $db->prepare("
    SELECT t.title FROM tags t
    INNER JOIN post_tags pt ON pt.tag_id = t.id
    WHERE pt.post_id = ?
  ");
  $tagStmt->execute([$postId]);
  $tags = implode(' ', $tagStmt->fetchAll(PDO::FETCH_COLUMN));

  $db->prepare("INSERT INTO posts_fts (rowid, title, content, tags) VALUES (?, ?, ?, ?)")
     ->execute([$postId, $post['title'], searchPlainText($post['content']), $tags]);
}

function searchRemovePost($db, int $postId): void {
  searchEnsureTable($db);
  $db->prepare("DELETE FROM posts_fts WHERE rowid = ?")->execute([$postId]);
}

// Rebuild the whole index from the posts table
function searchRebuild($db): void {
  searchEnsureTable($db);
  $db->prepare("DELETE FROM posts_fts")->execute();
  $ids = $db->prepare("SELECT id FROM posts");
  $ids->execute();
  foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
    searchIndexPost($db, (int)$id);
  }
}

/**
 * Self-healing: if the index does not hold exactly one row per post
 * (first use, or after an older version that skipped drafts), rebuild it
 */
function searchEnsureIndex($db): void {
  searchEnsureTable($db);
  $indexed = $db->prepare("SELECT COUNT(*) FROM posts_fts");
  $indexed->execute();
  $total = $db->prepare("SELECT COUNT(*) FROM posts");
  $total->execute();
  if ((int)$indexed->fetchColumn() !== (int)$total->fetchColumn()) {
    searchRebuild($db);
  }
}

// Turn what the visitor typed into a safe FTS5 query
function searchBuildQuery(string $input): ?string {
  $input = mb_substr($input, 0, 100);
  preg_match_all('/[\p{L}\p{N}]+/u', $input, $m);
  $tokens = array_slice($m[0], 0, 8);
  if (!$tokens) {
    return null;
  }
  $parts = [];
  foreach ($tokens as $t) {
    $parts[] = '"' . $t . '"' . (mb_strlen($t) >= 2 ? '*' : '');
  }
  return implode(' ', $parts);
}

// Escape text and turn our highlight markers into <mark> tags
function searchMark(string $s): string {
  return str_replace(
    ["\x02", "\x03"],
    ['<mark>', '</mark>'],
    htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
  );
}

// Search published posts
function searchPosts($db, string $input, int $limit = 20): array {
  $query = searchBuildQuery($input);
  if ($query === null) {
    return [];
  }

  searchEnsureTable($db);

  searchEnsureIndex($db);

  $stmt = $db->prepare("
    SELECT
      p.title, p.slug, p.created_at, p.excerpt,
      highlight(posts_fts, 0, ?, ?)            AS title_hl,
      snippet(posts_fts, 1, ?, ?, '…', 28)     AS snip
    FROM posts_fts
    JOIN posts p ON p.id = posts_fts.rowid
    WHERE posts_fts MATCH ?
      AND p.status = 'published'
    ORDER BY bm25(posts_fts, 10.0, 1.0, 5.0), p.created_at DESC
    LIMIT ?
  ");
  $stmt->bindValue(1, "\x02");
  $stmt->bindValue(2, "\x03");
  $stmt->bindValue(3, "\x02");
  $stmt->bindValue(4, "\x03");
  $stmt->bindValue(5, $query);
  $stmt->bindValue(6, $limit, PDO::PARAM_INT);
  $stmt->execute();

  $results = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $snippet = (strpos($row['snip'], "\x02") !== false) ? $row['snip'] : (string)($row['excerpt'] ?? '');
    $results[] = [
      'slug'         => $row['slug'],
      'created_at'   => $row['created_at'],
      'title_html'   => searchMark($row['title_hl']),
      'snippet_html' => searchMark($snippet),
    ];
  }
  return $results;
}
