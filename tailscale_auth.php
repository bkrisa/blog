<?php
function verifyTailscaleAccess(): void {
  // There is nothing to check in a development environment (localhost)
  if (($_SERVER['HTTP_HOST'] ?? '') === 'localhost') {
    return;
  }

  $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

  if ($remoteIp === '') {
    $output = shell_exec('sudo /usr/bin/tailscale whois --json ' . escapeshellarg($remoteIp) . ' 2>&1');
    error_log('TAILSCALE DEBUG - IP: ' . $remoteIp . ' - Output: ' . $output);
    $data = json_decode((string)$output, true);
    http_response_code(403);
    die('Access denied.');
  }

  $output = shell_exec('sudo /usr/bin/tailscale whois --json ' . escapeshellarg($remoteIp) . ' 2>&1');
  $data = json_decode((string)$output, true);

  if (!$data || empty($data['UserProfile']['LoginName'])) {
    error_log('Tailscale whois failed for IP: ' . $remoteIp . ' - Response: ' . $output);
    http_response_code(403);
    die('Tailscale authentication failed.');
  }
}