<?php
// Extracts the src attribute of the first <img> tag from the HTML content of a post
function getFirstImage(string $html): ?string {
  if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
    return $matches[1];
  }
  return null;
}