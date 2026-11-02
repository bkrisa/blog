<?php
// CSRF protection without sessions
function requireSameOrigin(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    return;
  }

  $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
  $origin    = $_SERVER['HTTP_ORIGIN'] ?? '';
  $ok = false;

  if ($fetchSite !== '') {
    $ok = ($fetchSite === 'same-origin');
  } elseif ($origin !== '') {
    $originHost  = parse_url($origin, PHP_URL_HOST);
    $requestHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    $ok = $originHost && strcasecmp($originHost, $requestHost) === 0;
  }

  if (!$ok) {
    http_response_code(403);
    die('Cross-origin request blocked.');
  }
}
