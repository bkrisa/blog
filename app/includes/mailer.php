<?php
/**
 * Minimal mailer for the newsletter (no dependencies).
 *
 * Driver "smtp" (default): Cloudflare Email Service over SMTP (implicit TLS, port 465).
 *   Username is the literal string "api_token", the password is a Cloudflare API token
 *   with the "Email Sending: Edit" permission. The sender domain must be onboarded there.
 * Driver "log": writes the raw messages to a file instead of sending (local development).
 *
 * Settings come from the .env file:
 *   EMAIL_SMTP_HOST, EMAIL_SMTP_PORT, EMAIL_API_TOKEN, EMAIL_FROM
 *   optional: EMAIL_DRIVER (smtp|log), EMAIL_LOG_PATH, EMAIL_SMTP_VERIFY_PEER (0 only for tests)
 */

class MailException extends Exception {
  /** SMTP reply code (0 = connection/protocol problem) */
  public int $smtpCode;

  public function __construct(string $message, int $smtpCode = 0) {
    parent::__construct($message);
    $this->smtpCode = $smtpCode;
  }

  /** 5xx = the server refused for good (bad address, rejected content). Retrying makes no sense. */
  public function isPermanent(): bool {
    return $this->smtpCode >= 500 && $this->smtpCode < 600;
  }
}

function mailEnv(string $key, string $default = ''): string {
  $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
  return ($value === false || $value === null || $value === '') ? $default : (string)$value;
}

/** Plain address from "Name <a@b.c>" or "a@b.c" (empty string if invalid). */
function mailAddress(string $value): string {
  if (preg_match('/<([^<>]+)>\s*$/', $value, $m)) {
    $value = $m[1];
  }
  $value = trim($value);
  return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
}

function mailHeaderValue(string $value): string {
  // header injection guard
  return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
}

function mailEncodeHeader(string $value): string {
  $value = mailHeaderValue($value);
  if (preg_match('/^[\x20-\x7e]*$/', $value)) {
    return $value;
  }
  return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
}

/** "Name" <address> with the display name encoded when needed. */
function mailFormatAddress(string $address, string $name = ''): string {
  $name = mailHeaderValue($name);
  if ($name === '') {
    return $address;
  }
  if (preg_match('/^[\x20-\x7e]*$/', $name)) {
    return '"' . addcslashes($name, '"\\') . '" <' . $address . '>';
  }
  return mailEncodeHeader($name) . ' <' . $address . '>';
}

/**
 * Build a complete RFC 5322 message: multipart/alternative (text + html).
 *
 * $msg keys: from, from_name, to, reply_to, subject, text, html, headers (name => value)
 */
function mailBuildMessage(array $msg): string {
  $from = mailAddress($msg['from'] ?? '');
  $to = mailAddress($msg['to'] ?? '');
  if ($from === '' || $to === '') {
    throw new MailException('Invalid sender or recipient address.');
  }

  $domain = substr(strrchr($from, '@'), 1);
  $boundary = '=_ob_' . bin2hex(random_bytes(12));

  $h = [];
  $h[] = 'Date: ' . date('r');
  $h[] = 'From: ' . mailFormatAddress($from, $msg['from_name'] ?? '');
  $h[] = 'To: ' . $to;
  $replyTo = mailAddress($msg['reply_to'] ?? '');
  if ($replyTo !== '') {
    $h[] = 'Reply-To: ' . $replyTo;
  }
  $h[] = 'Subject: ' . mailEncodeHeader($msg['subject'] ?? '');
  $h[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
  $h[] = 'MIME-Version: 1.0';

  foreach (($msg['headers'] ?? []) as $name => $value) {
    if (!preg_match('/^[A-Za-z0-9-]+$/', (string)$name)) {
      continue;
    }
    $h[] = $name . ': ' . mailHeaderValue((string)$value);
  }

  $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

  $qp = function (string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return quoted_printable_encode(str_replace("\n", "\r\n", $s));
  };

  $body  = '--' . $boundary . "\r\n";
  $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
  $body .= $qp((string)($msg['text'] ?? '')) . "\r\n";
  $body .= '--' . $boundary . "\r\n";
  $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
  $body .= $qp((string)($msg['html'] ?? '')) . "\r\n";
  $body .= '--' . $boundary . "--\r\n";

  return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

/** Tiny SMTP client with one reusable connection (a batch of mails shares one login). */
class SmtpConnection {
  private $socket = null;
  private string $host;
  private int $port;
  private string $username;
  private string $password;
  private bool $verifyPeer;

  public function __construct(string $host, int $port, string $username, string $password, bool $verifyPeer = true) {
    $this->host = $host;
    $this->port = $port;
    $this->username = $username;
    $this->password = $password;
    $this->verifyPeer = $verifyPeer;
  }

  public function __destruct() {
    $this->close();
  }

  private function connect(): void {
    $context = stream_context_create(['ssl' => [
      'verify_peer' => $this->verifyPeer,
      'verify_peer_name' => $this->verifyPeer,
      'peer_name' => $this->host,
    ]]);
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
      'ssl://' . $this->host . ':' . $this->port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context
    );
    if (!$socket) {
      throw new MailException('SMTP connection failed: ' . $errstr);
    }
    stream_set_timeout($socket, 30);
    $this->socket = $socket;

    $this->expect([220]);
    $this->command('EHLO ' . (gethostname() ?: 'localhost'), [250]);
    $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235]);
  }

  /** Read one (possibly multi-line) reply and check its code. */
  private function expect(array $okCodes): array {
    $code = 0;
    $lines = [];
    while (($line = fgets($this->socket, 1024)) !== false) {
      $lines[] = rtrim($line);
      if (strlen($line) >= 4 && $line[3] === ' ') {
        $code = (int)substr($line, 0, 3);
        break;
      }
      if (strlen($line) < 4) {
        break;
      }
    }
    if ($code === 0) {
      $this->close();
      throw new MailException('SMTP connection lost.');
    }
    if (!in_array($code, $okCodes, true)) {
      throw new MailException('SMTP error: ' . implode(' | ', $lines), $code);
    }
    return $lines;
  }

  private function command(string $line, array $okCodes): array {
    fwrite($this->socket, $line . "\r\n");
    return $this->expect($okCodes);
  }

  public function send(string $envelopeFrom, string $envelopeTo, string $rawMessage): void {
    if ($this->socket === null) {
      $this->connect();
    }

    try {
      $this->command('MAIL FROM:<' . $envelopeFrom . '>', [250]);
      $this->command('RCPT TO:<' . $envelopeTo . '>', [250, 251]);
      $this->command('DATA', [354]);

      // dot-stuffing, and the message must end with CRLF . CRLF
      $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $rawMessage));
      $data = str_replace("\n", "\r\n", $data);
      fwrite($this->socket, rtrim($data, "\r\n") . "\r\n.\r\n");
      $this->expect([250]);
    } catch (MailException $e) {
      if ($this->socket !== null && $e->smtpCode > 0) {
        // the server said no to this message but the session is still usable
        @fwrite($this->socket, "RSET\r\n");
        @fgets($this->socket, 1024);
      }
      throw $e;
    }
  }

  public function close(): void {
    if ($this->socket) {
      @fwrite($this->socket, "QUIT\r\n");
      @fclose($this->socket);
      $this->socket = null;
    }
  }
}

/**
 * Send one message. Throws MailException (check ->isPermanent()).
 * The connection is reused between calls in the same PHP process.
 */
function sendMail(array $msg): void {
  $driver = strtolower(mailEnv('EMAIL_DRIVER', 'smtp'));

  $from = mailAddress($msg['from'] ?? mailEnv('EMAIL_FROM'));
  if ($from === '') {
    throw new MailException('EMAIL_FROM is missing or invalid in .env');
  }
  $msg['from'] = $from;
  $raw = mailBuildMessage($msg);

  if ($driver === 'log') {
    $path = mailEnv('EMAIL_LOG_PATH', sys_get_temp_dir() . '/openblog-mail.log');
    $entry = "===== " . date('c') . " =====\r\n" . $raw . "\r\n";
    if (@file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
      throw new MailException('Cannot write the mail log: ' . $path);
    }
    return;
  }

  static $connection = null;
  if ($connection === null) {
    $host = mailEnv('EMAIL_SMTP_HOST', 'smtp.mx.cloudflare.net');
    $port = (int)mailEnv('EMAIL_SMTP_PORT', '465');
    $token = mailEnv('EMAIL_API_TOKEN');
    if ($token === '') {
      throw new MailException('EMAIL_API_TOKEN is missing in .env');
    }
    $connection = new SmtpConnection($host, $port, 'api_token', $token, mailEnv('EMAIL_SMTP_VERIFY_PEER', '1') !== '0');
  }

  $connection->send($from, mailAddress($msg['to']), $raw);
}
