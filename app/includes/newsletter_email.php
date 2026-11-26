<?php
/**
 * Turns a blog post into a newsletter email: plain, single column, real links, absolute image URLs.
 * Needs newsletter.php (settings, unsubscribe URLs).
 */
require_once __DIR__ . '/newsletter.php';

function nlOrigin(string $blogUrl): string {
  $p = parse_url($blogUrl);
  if (!$p || empty($p['host'])) {
    return '';
  }
  return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

/** Relative URL -> absolute (emails have no "current page"). Returns '' for unsafe schemes. */
function nlAbsoluteUrl(string $url, string $blogUrl, string $postUrl): string {
  $url = trim($url);
  if ($url === '') {
    return '';
  }
  if (preg_match('#^(https?:|mailto:)#i', $url)) {
    return $url;
  }
  if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
    return ''; // javascript:, data:, ...
  }
  if (strpos($url, '//') === 0) {
    return 'https:' . $url;
  }
  if ($url[0] === '#') {
    return $postUrl . $url;
  }
  if ($url[0] === '/') {
    return nlOrigin($blogUrl) . $url;
  }
  return rtrim($blogUrl, '/') . '/' . $url;
}

/** Keep only a few harmless CSS properties from an inline style. */
function nlSafeStyle(string $style): string {
  $keep = [];
  foreach (explode(';', $style) as $decl) {
    if (preg_match('/^\s*text-align\s*:\s*(left|right|center|justify)\s*$/i', $decl, $m)) {
      $keep[] = 'text-align:' . strtolower($m[1]);
    }
  }
  return implode(';', $keep);
}

/**
 * Clean the post's HTML for email. Returns the inner HTML.
 */
function newsletterCleanContent(string $html, string $postUrl): string {
  $n = newsletterSettings();
  $blogUrl = $n['blog_url'];
  $all = function_exists('loadSettings') ? loadSettings() : [];
  $link = (string)($all['design']['colors']['main'] ?? '');
  if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $link)) {
    $link = '#1a56db';
  }

  $prev = libxml_use_internal_errors(true);
  $doc = new DOMDocument('1.0', 'UTF-8');
  $doc->loadHTML('<?xml encoding="UTF-8"><div id="nl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  $xp = new DOMXPath($doc);

  // 1. things email clients can't (or shouldn't) run
  foreach ($xp->query('//script|//style|//iframe|//video|//audio|//object|//embed|//form|//noscript|//button|//input|//textarea|//select|//svg|//canvas|//link|//meta') as $el) {
    $el->parentNode->removeChild($el);
  }

  // 2. <details>/<summary>/<figure>: keep the content, drop the wrapper
  foreach (iterator_to_array($xp->query('//summary')) as $el) {
    $p = $doc->createElement('p');
    $strong = $doc->createElement('strong');
    while ($el->firstChild) {
      $strong->appendChild($el->firstChild);
    }
    $p->appendChild($strong);
    $el->parentNode->replaceChild($p, $el);
  }
  foreach (iterator_to_array($xp->query('//figcaption')) as $el) {
    $p = $doc->createElement('p');
    $p->setAttribute('data-nl', 'caption');
    while ($el->firstChild) {
      $p->appendChild($el->firstChild);
    }
    $el->parentNode->replaceChild($p, $el);
  }
  foreach (iterator_to_array($xp->query('//details|//figure')) as $el) {
    while ($el->firstChild) {
      $el->parentNode->insertBefore($el->firstChild, $el);
    }
    $el->parentNode->removeChild($el);
  }

  // 3. images: absolute URL, fits the screen, no srcset/lazy-loading attributes
  foreach (iterator_to_array($xp->query('//img')) as $img) {
    $src = nlAbsoluteUrl((string)$img->getAttribute('src'), $blogUrl, $postUrl);
    if ($src === '' || !preg_match('#^https?://#i', $src)) {
      $img->parentNode->removeChild($img);
      continue;
    }
    $img->setAttribute('src', $src);
  }

  // 4. links
  foreach ($xp->query('//a') as $a) {
    $href = nlAbsoluteUrl((string)$a->getAttribute('href'), $blogUrl, $postUrl);
    if ($href === '') {
      $a->removeAttribute('href'); // unsafe link: keep the text, drop the link (unwrapped below)
    } else {
      $a->setAttribute('href', $href);
    }
  }

  foreach (iterator_to_array($xp->query('//a[not(@href)]')) as $a) {
    while ($a->firstChild) {
      $a->parentNode->insertBefore($a->firstChild, $a);
    }
    $a->parentNode->removeChild($a);
  }

  // 5. strip attributes, add the few inline styles that matter
  $allowed = [
    'a' => ['href'], 'img' => ['src', 'alt', 'width', 'height'],
    'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'], 'ol' => ['start'],
  ];
  $styles = [
    'p' => 'margin:0 0 16px;',
    'h1' => 'margin:28px 0 10px;font-size:22px;line-height:1.3;',
    'h2' => 'margin:28px 0 10px;font-size:22px;line-height:1.3;',
    'h3' => 'margin:24px 0 8px;font-size:18px;line-height:1.3;',
    'h4' => 'margin:20px 0 8px;font-size:16px;line-height:1.3;',
    'h5' => 'margin:20px 0 8px;font-size:16px;line-height:1.3;',
    'h6' => 'margin:20px 0 8px;font-size:16px;line-height:1.3;',
    'ul' => 'margin:0 0 16px;padding-left:24px;',
    'ol' => 'margin:0 0 16px;padding-left:24px;',
    'li' => 'margin:0 0 6px;',
    'blockquote' => 'margin:0 0 16px;padding-left:14px;border-left:3px solid #dddddd;color:#555555;',
    'pre' => 'margin:0 0 16px;padding:12px;background:#f5f5f5;font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word;',
    'code' => 'font-family:Menlo,Consolas,monospace;font-size:90%;',
    'hr' => 'border:0;border-top:1px solid #dddddd;margin:24px 0;',
    'table' => 'width:100%;border-collapse:collapse;margin:0 0 16px;font-size:15px;',
    'th' => 'border:1px solid #dddddd;padding:6px 10px;text-align:left;',
    'td' => 'border:1px solid #dddddd;padding:6px 10px;text-align:left;',
    'img' => 'display:block;max-width:100%;height:auto;margin:16px auto;',
    'a' => 'color:' . $link . ';',
  ];
  foreach (iterator_to_array($xp->query('//*[not(@id="nl-root")]')) as $el) {
    $tag = strtolower($el->nodeName);
    $align = nlSafeStyle((string)$el->getAttribute('style'));
    $caption = $el->getAttribute('data-nl') === 'caption';
    foreach (iterator_to_array($el->attributes) as $attr) {
      if (!in_array($attr->name, $allowed[$tag] ?? [], true)) {
        $el->removeAttribute($attr->name);
      }
    }
    $style = ($styles[$tag] ?? '') . ($caption ? 'font-size:14px;color:#666666;text-align:center;' : '') . ($align !== '' ? $align . ';' : '');
    // <pre><code>: no second box
    if ($tag === 'code' && $el->parentNode && strtolower($el->parentNode->nodeName) === 'pre') {
      $style = 'font-family:Menlo,Consolas,monospace;font-size:inherit;';
    }
    if ($style !== '') {
      $el->setAttribute('style', $style);
    }
  }

  $root = $doc->getElementById('nl-root') ?: $xp->query('//*[@id="nl-root"]')->item(0);
  $out = '';
  if ($root) {
    foreach ($root->childNodes as $child) {
      $out .= $doc->saveHTML($child);
    }
  }
  return trim($out);
}

/** Plain-text version of the cleaned HTML (readable, with the links kept). */
function newsletterHtmlToText(string $html): string {
  $prev = libxml_use_internal_errors(true);
  $doc = new DOMDocument('1.0', 'UTF-8');
  $doc->loadHTML('<?xml encoding="UTF-8"><div id="nl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);

  $walk = function (DOMNode $node) use (&$walk): string {
    if ($node->nodeType === XML_TEXT_NODE) {
      return preg_replace('/[ \t\r\n]+/', ' ', $node->nodeValue);
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) {
      return '';
    }
    $tag = strtolower($node->nodeName);
    $inner = '';
    foreach ($node->childNodes as $c) {
      $inner .= $walk($c);
    }
    switch ($tag) {
      case 'br':
        return "\n";
      case 'p': case 'div': case 'table': case 'blockquote':
        return "\n\n" . trim($inner) . "\n\n";
      case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
        return "\n\n" . trim($inner) . "\n\n";
      case 'ul': case 'ol':
        return "\n" . $inner . "\n";
      case 'li':
        return "\n- " . trim($inner);
      case 'tr':
        return "\n" . trim($inner);
      case 'td': case 'th':
        return trim($inner) . '  ';
      case 'hr':
        return "\n\n---\n\n";
      case 'pre':
        return "\n\n" . $node->textContent . "\n\n";
      case 'img':
        $alt = trim((string)$node->getAttribute('alt'));
        return "\n\n[" . ($alt !== '' ? $alt : 'image') . '] ' . $node->getAttribute('src') . "\n\n";
      case 'a':
        $href = trim((string)$node->getAttribute('href'));
        $label = trim($inner);
        if ($href === '' || $label === $href || $label === '') {
          return $label !== '' ? $label : $href;
        }
        return $label . ' (' . $href . ')';
      default:
        return $inner;
    }
  };

  $root = $doc->getElementById('nl-root');
  $text = $root ? $walk($root) : '';
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/[ \t]+\n/", "\n", $text);
  $text = preg_replace("/\n{3,}/", "\n\n", $text);
  return trim($text);
}

function nlParagraphsHtml(string $text): string {
  $out = '';
  foreach (preg_split('/\n{2,}/', trim($text)) as $block) {
    if (trim($block) !== '') {
      $out .= '<p style="margin:0 0 16px;">' . nl2br(nl_h(trim($block)), false) . '</p>';
    }
  }
  return $out;
}

/**
 * Build the complete email for one subscriber.
 * $post: row from posts (title, slug, content). $subscriber: row with 'token'.
 * Returns ['subject', 'html', 'text', 'headers'].
 */
function newsletterBuildMessage(array $post, array $subscriber): array {
  static $cache = [];
  $n = newsletterSettings();
  $postUrl = $n['blog_url'] . '/' . $post['slug'];

  $key = ($post['id'] ?? '') . '|' . md5((string)$post['content']);
  if (!isset($cache[$key])) {
    $clean = newsletterCleanContent((string)$post['content'], $postUrl);
    $cache = [$key => ['html' => $clean, 'text' => newsletterHtmlToText($clean)]];
  }
  $body = $cache[$key];

  $unsubscribe = newsletterUrl(['action' => 'unsubscribe', 'token' => $subscriber['token']]);
  $site = $n['site_name'];
  $titleH = nl_h($post['title']);
  $linkColor = '#1a56db';
  $all = function_exists('loadSettings') ? loadSettings() : [];
  if (preg_match('/^#[0-9a-fA-F]{3,6}$/', (string)($all['design']['colors']['main'] ?? ''))) {
    $linkColor = $all['design']['colors']['main'];
  }

  $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
    . '<body style="margin:0;padding:24px 16px;background:#ffffff;">'
    . '<div style="max-width:640px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:17px;line-height:1.6;color:#222222;">';
  if (trim($n['intro']) !== '') {
    $html .= nlParagraphsHtml($n['intro']);
  }
  $html .= '<h1 style="margin:0 0 20px;font-size:26px;line-height:1.25;"><a href="' . nl_h($postUrl) . '" style="color:#222222;text-decoration:none;">' . $titleH . '</a></h1>';
  $html .= $body['html'];
  if (trim($n['outro']) !== '') {
    $html .= nlParagraphsHtml($n['outro']);
  }
  $html .= '<hr style="border:0;border-top:1px solid #dddddd;margin:32px 0 14px;">'
    . '<p style="margin:0;font-size:13px;line-height:1.5;color:#777777;">You get this email because you subscribed to ' . nl_h($site) . '. '
    . '<a href="' . nl_h($postUrl) . '" style="color:' . nl_h($linkColor) . ';">Read it on the web</a> &middot; '
    . '<a href="' . nl_h($unsubscribe) . '" style="color:' . nl_h($linkColor) . ';">Unsubscribe</a></p>'
    . '</div></body></html>';

  $text = '';
  if (trim($n['intro']) !== '') {
    $text .= trim($n['intro']) . "\n\n";
  }
  $text .= $post['title'] . "\n" . $postUrl . "\n\n" . $body['text'] . "\n\n";
  if (trim($n['outro']) !== '') {
    $text .= trim($n['outro']) . "\n\n";
  }
  $text .= "--\nYou get this email because you subscribed to {$site}.\nRead it on the web: {$postUrl}\nUnsubscribe: {$unsubscribe}\n";

  return [
    'subject' => (string)$post['title'],
    'html' => $html,
    'text' => $text,
    'headers' => newsletterUnsubscribeHeaders($subscriber['token']),
  ];
}

/**
 * Put a published post in the queue for every confirmed subscriber.
 * Does nothing when the post was queued before (editing a post never sends it again).
 * Returns the number of queued emails (0 if already queued or nobody is subscribed).
 */
function newsletterQueuePost(Database $db, int $postId, ?int $delayMinutes = null): int {
  newsletterEnsureSchema($db);
  $n = newsletterSettings();
  if (!$n['enabled']) {
    return 0;
  }
  $delay = $delayMinutes ?? $n['delay_minutes'];

  // only published posts are ever sent
  $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE id = ? AND status = 'published'");
  $stmt->execute([$postId]);
  if ((int)$stmt->fetchColumn() === 0) {
    return 0;
  }

  $stmt = $db->prepare("SELECT COUNT(*) FROM newsletter_queue WHERE post_id = ?");
  $stmt->execute([$postId]);
  if ((int)$stmt->fetchColumn() > 0) {
    return 0;
  }

  $stmt = $db->prepare("INSERT OR IGNORE INTO newsletter_queue (post_id, subscriber_id, send_after)
    SELECT ?, id, datetime('now', ?) FROM subscribers WHERE status = 'confirmed'");
  $stmt->execute([$postId, '+' . max(0, $delay) . ' minutes']);
  return $stmt->rowCount();
}
