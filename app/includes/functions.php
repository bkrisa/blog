<?php
// Extracts the src attribute of the first <img> tag from the HTML content of a post
function getFirstImage(string $html): ?string {
  if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
    return $matches[1];
  }
  return null;
}

// Generates a safe description for meta tags by stripping HTML tags and limiting the length
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
 
// Adds unique IDs to all heading tags (h1-h6) in the provided HTML content
function addHeadingIds(string $html): string {
  if (trim($html) === '') {
    return $html;
  }
 
  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();

  $usedSlugs = [];
 
  foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
    $headings = $dom->getElementsByTagName($tag);
    foreach ($headings as $heading) {
      if ($heading->hasAttribute('id')) {
        continue;
      }
      $slug = slugify($heading->textContent);
      if ($slug === '') {
        continue;
      }
      $finalSlug = $slug;
      $counter = 2;
      while (in_array($finalSlug, $usedSlugs, true)) {
        $finalSlug = $slug . '-' . $counter;
        $counter++;
      }
      $usedSlugs[] = $finalSlug;
      $heading->setAttribute('id', $finalSlug);
    }
  }
  $body = $dom->getElementsByTagName('body')->item(0);
  $result = '';
  foreach ($body->childNodes as $child) {
    $result .= $dom->saveHTML($child);
  }
  return $result;
}