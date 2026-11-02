<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/same_origin.php';
require_once __DIR__ . '/../../tailscale_auth.php';
verifyTailscaleAccess();
requireSameOrigin();

// Helpers
function st_h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a trimmed string from $_POST[a][k1][k2]... ('' if missing). */
function st_str(string $a, string ...$keys): string {
  $v = $_POST[$a] ?? null;
  foreach ($keys as $k) {
    if (!is_array($v)) return '';
    $v = $v[$k] ?? null;
  }
  return is_string($v) ? str_replace("\r\n", "\n", trim($v)) : '';
}

/** Read a repeater (array of rows) from $_POST, skipping completely empty rows. */
function st_rows(string $key, array $fields): array {
  $rows = $_POST[$key] ?? [];
  if (!is_array($rows)) return [];
  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $item = [];
    foreach ($fields as $f) {
      $v = $row[$f] ?? '';
      $item[$f] = is_string($v) ? trim($v) : '';
    }
    if (implode('', $item) === '') continue;
    $out[] = $item;
  }
  return $out;
}

function st_valid_url(string $v, bool $allowRelative = false): bool {
  if ($v === '' || preg_match('/[\s<>"\']/', $v)) return false;
  if (preg_match('#^https?://#i', $v)) {
    $p = parse_url($v);
    return isset($p['host']) && $p['host'] !== '';
  }
  if ($allowRelative) {
    if (preg_match('#^/(?!/)#', $v)) return true;
    if (preg_match('#^mailto:\S+$#i', $v)) return true;
  }
  return false;
}

function st_valid_color(string $v): bool {
  return (bool)preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v);
}

/** Atomically write settings.json (pretty-printed, 2-space indent). Returns an error string or null. */
function st_save_file(array $data, string $file): ?string {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return 'Could not encode settings: ' . json_last_error_msg();
  }
  // PHP pretty-prints with 4 spaces; the project's file uses 2.
  $json = preg_replace_callback('/^( +)/m', fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json) . "\n";

  $dir = dirname($file);
  if (is_writable($dir)) {
    @copy($file, $file . '.bak');
    $tmp = tempnam($dir, 'settings_');
    if ($tmp === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
      if ($tmp !== false) @unlink($tmp);
      return 'Could not write a temporary file next to settings.json.';
    }
    @chmod($tmp, fileperms($file) & 0777);
    if (!rename($tmp, $file)) {
      @unlink($tmp);
      return 'Could not replace settings.json.';
    }
    return null;
  }

  if (is_writable($file)) {
    return file_put_contents($file, $json, LOCK_EX) === false ? 'Could not write settings.json.' : null;
  }

  return 'settings.json is not writable by the web server user. Make the file (or the app/ folder) writable for it, e.g. chown www-data app/settings.json';
}

function st_nav_row($i, string $label, string $url): void { ?>
  <div class="row">
    <input type="text" name="navigation[<?= st_h($i) ?>][label]" value="<?= st_h($label) ?>" placeholder="label">
    <input type="text" name="navigation[<?= st_h($i) ?>][url]" value="<?= st_h($url) ?>" placeholder="https://example.com or /path">
    <button type="button" data-remove aria-label="Remove">✕</button>
  </div>
<?php }

function st_social_row($i, string $platform, string $url): void { ?>
  <div class="row">
    <input type="text" name="social_links[<?= st_h($i) ?>][platform]" value="<?= st_h($platform) ?>" placeholder="platform (e.g. X, github)">
    <input type="text" name="social_links[<?= st_h($i) ?>][url]" value="<?= st_h($url) ?>" placeholder="https://x.com/yourhandle">
    <button type="button" data-remove aria-label="Remove">✕</button>
  </div>
<?php }

function st_head_row($i, string $html): void { ?>
  <div class="row">
    <textarea name="head[<?= st_h($i) ?>]" rows="2" placeholder="&lt;script src=&quot;...&quot; async&gt;&lt;/script&gt;"><?= st_h($html) ?></textarea>
    <button type="button" data-remove aria-label="Remove">✕</button>
  </div>
<?php }

function st_color_field(string $name, string $label, string $value): void {
  $picker = preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '#000000'; ?>
  <div>
    <label for="c_<?= st_h($name) ?>"><?= st_h($label) ?></label>
    <div class="color-field">
      <input type="color" value="<?= st_h($picker) ?>" aria-label="<?= st_h($label) ?> picker">
      <input type="text" id="c_<?= st_h($name) ?>" name="design[colors][<?= st_h($name) ?>]" value="<?= st_h($value) ?>" placeholder="#rrggbb">
    </div>
  </div>
<?php }

// Load + handle POST
$settingsFile = __DIR__ . '/../settings.json';
$current = loadSettings();
$data = $current;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Build the candidate settings. Start from the current file so any keys this
  // form doesn't know about are preserved.
  $candidate = $current;

  $candidate['site'] = array_merge($current['site'] ?? [], [
    'name'           => st_str('site', 'name'),
    'base_url'       => st_str('site', 'base_url'),
    'blog_url'       => st_str('site', 'blog_url'),
    'logo'           => st_str('site', 'logo'),
    'author_img'     => st_str('site', 'author_img'),
    'twitter_handle' => ltrim(st_str('site', 'twitter_handle'), '@'),
  ]);

  $candidate['author'] = array_merge($current['author'] ?? [], [
    'name'        => st_str('author', 'name'),
    'description' => st_str('author', 'description'),
  ]);

  $design = $current['design'] ?? [];
  $design['colors'] = array_merge($design['colors'] ?? [], [
    'main'    => st_str('design', 'colors', 'main'),
    'heading' => st_str('design', 'colors', 'heading'),
    'text'    => st_str('design', 'colors', 'text'),
    'bg'      => st_str('design', 'colors', 'bg'),
  ]);
  $design['fonts'] = array_merge($design['fonts'] ?? [], [
    'primary' => st_str('design', 'fonts', 'primary'),
  ]);
  $candidate['design'] = $design;

  $head = [];
  $postedHead = $_POST['head'] ?? [];
  if (is_array($postedHead)) {
    foreach ($postedHead as $html) {
      if (!is_string($html)) continue;
      $html = str_replace("\r\n", "\n", trim($html));
      if ($html !== '') $head[] = ['html' => $html];
    }
  }
  $candidate['head'] = $head;
  $candidate['navigation'] = st_rows('navigation', ['label', 'url']);
  $candidate['social_links'] = st_rows('social_links', ['platform', 'url']);

  // Validation
  $s = $candidate['site'];
  if ($s['name'] === '' || mb_strlen($s['name']) > 100) $errors[] = 'Site name is required (max 100 characters).';
  if (!st_valid_url($s['blog_url'])) $errors[] = 'Blog URL must be a full http(s) URL, e.g. https://example.com/blog';
  if ($s['base_url'] !== '' && !st_valid_url($s['base_url'])) $errors[] = 'Base URL must be a full http(s) URL.';
  if ($s['logo'] !== '' && !st_valid_url($s['logo'])) $errors[] = 'Logo must be a full http(s) URL.';
  if ($s['author_img'] !== '' && !st_valid_url($s['author_img'])) $errors[] = 'Author image must be a full http(s) URL.';
  if (!preg_match('/^[A-Za-z0-9_]{0,15}$/', $s['twitter_handle'])) $errors[] = 'Twitter/X handle may only contain letters, numbers and underscores (max 15).';

  if (mb_strlen($candidate['author']['name']) > 100) $errors[] = 'Author name is too long (max 100 characters).';
  if (mb_strlen($candidate['author']['description']) > 2000) $errors[] = 'Author description is too long (max 2000 characters).';

  foreach (['main' => 'Main', 'heading' => 'Heading', 'text' => 'Text', 'bg' => 'Background'] as $k => $label) {
    if (!st_valid_color($candidate['design']['colors'][$k])) $errors[] = "$label color must be a hex value like #188ddb.";
  }
  if (!preg_match('/^[A-Za-z0-9 ,\-]{1,100}$/', $candidate['design']['fonts']['primary'])) {
    $errors[] = 'Font may only contain letters, numbers, spaces, commas and hyphens.';
  }

  foreach ($candidate['head'] as $item) {
    if (mb_strlen($item['html']) > 5000) { $errors[] = 'A head snippet is too long (max 5000 characters).'; break; }
  }
  foreach ($candidate['navigation'] as $i => $item) {
    $n = $i + 1;
    if ($item['label'] === '' || mb_strlen($item['label']) > 50) $errors[] = "Navigation item $n needs a label (max 50 characters).";
    if (!st_valid_url($item['url'], true)) $errors[] = "Navigation item $n needs a valid URL (http(s), /path or mailto:).";
  }
  foreach ($candidate['social_links'] as $i => $item) {
    $n = $i + 1;
    if ($item['platform'] === '' || mb_strlen($item['platform']) > 50) $errors[] = "Social link $n needs a platform name (max 50 characters).";
    if (!st_valid_url($item['url'])) $errors[] = "Social link $n needs a full http(s) URL.";
  }

  if (!$errors) {
    $err = st_save_file($candidate, $settingsFile);
    if ($err === null) {
      header('Location: settings.php?saved=1');
      exit;
    }
    $errors[] = $err;
  }

  // Re-render the form with what the user submitted so nothing is lost.
  $data = $candidate;
}

$v = fn(string ...$keys) => (function () use ($data, $keys) {
  $x = $data;
  foreach ($keys as $k) {
    if (!is_array($x) || !array_key_exists($k, $x)) return '';
    $x = $x[$k];
  }
  return is_scalar($x) ? (string)$x : '';
})();

$page_title = "Settings";
$page_description = "";
$page_url = "";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
  .settings fieldset { border: 1px solid #ddd; border-radius: 6px; margin: 0 0 20px; padding: 6px 16px 16px; }
  .settings legend { font-weight: 600; padding: 0 6px; }
  .settings label { display: block; font-size: 14px; margin: 12px 0 4px; }
  .settings input[type=text], .settings textarea { width: 100%; font: inherit; color: #333; padding: 5px 8px; border: 1px solid #eee; border-radius: 10px;}
  .settings .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
  .settings .row { display: flex; gap: 8px; margin-bottom: 8px; align-items: flex-start; }
  .settings .row input, .settings .row textarea { flex: 1; min-width: 0; }
  .settings .color-field { display: flex; gap: 8px; align-items: center; }
  .settings .color-field input[type=color] { width: 40px; height: 34px; padding: 0; flex: none; }
  .settings .hint { font-size: 12px; opacity: .7; margin: 6px 0 0; }
  .settings .errors { border: 1px solid #d33; background: #fff3f3; color: #a00; border-radius: 6px; padding: 10px 16px; margin-bottom: 20px; font-size: 14px; }
  .settings .errors ul { margin: 0; padding-left: 18px; }
  .settings [data-remove], .settings [data-add] {font-size: 15px;color: var(--text);background: transparent;padding: 3px 4px;border: 1px solid var(--main);border-radius: 8px;cursor: pointer;}
  .settings [type=submit] {font-size: 16px;color: var(--text);background: var(--main);padding: 6px 10px;border: 1px solid var(--main);border-radius: 8px;cursor: pointer;}
  @media (max-width: 600px) { .settings .grid { grid-template-columns: 1fr; } }
</style>

<div class="editor settings">
  <a href="./" style="font-size: 14px;margin-bottom: 10px;">← Back dashboard</a>

  <?php if (isset($_GET['saved']) && !$errors): ?>
    <p class="save-notice">✅ Settings saved</p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="errors">
      <strong>Settings were not saved:</strong>
      <ul>
        <?php foreach ($errors as $e): ?><li><?= st_h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form action="settings.php" method="POST" autocomplete="off">
    <fieldset>
      <legend>Site</legend>
      <label for="site_name">Blog name</label>
      <input type="text" id="site_name" name="site[name]" value="<?= st_h($v('site', 'name')) ?>" required>

      <label for="site_blog_url">Blog URL</label>
      <input type="text" id="site_blog_url" name="site[blog_url]" value="<?= st_h($v('site', 'blog_url')) ?>" required>
      <p class="hint">The exact, full URL where this blog is installed. Every internal link is built from it. A wrong value can break the whole site.</p>

      <label for="site_base_url">Main website URL</label>
      <input type="text" id="site_base_url" name="site[base_url]" value="<?= st_h($v('site', 'base_url')) ?>">

      <div class="grid">
        <div>
          <label for="site_logo">Logo URL</label>
          <input type="text" id="site_logo" name="site[logo]" value="<?= st_h($v('site', 'logo')) ?>">
        </div>
        <div>
          <label for="site_author_img">Author image URL</label>
          <input type="text" id="site_author_img" name="site[author_img]" value="<?= st_h($v('site', 'author_img')) ?>">
        </div>
      </div>

      <label for="site_twitter">X / Twitter handle (without @)</label>
      <input type="text" id="site_twitter" name="site[twitter_handle]" value="<?= st_h($v('site', 'twitter_handle')) ?>">
    </fieldset>

    <fieldset>
      <legend>Author</legend>
      <label for="author_name">Name</label>
      <input type="text" id="author_name" name="author[name]" value="<?= st_h($v('author', 'name')) ?>">

      <label for="author_description">Short bio</label>
      <textarea id="author_description" name="author[description]" rows="4"><?= st_h($v('author', 'description')) ?></textarea>
      <p class="hint">HTML allowed, but only &lt;a&gt; tags survive on the public page.</p>
    </fieldset>

    <fieldset>
      <legend>Design</legend>
      <div class="grid">
        <?php
        st_color_field('main', 'Main color', $v('design', 'colors', 'main'));
        st_color_field('heading', 'Heading color', $v('design', 'colors', 'heading'));
        st_color_field('text', 'Text color', $v('design', 'colors', 'text'));
        st_color_field('bg', 'Background color', $v('design', 'colors', 'bg'));
        ?>
      </div>
      <label for="font_primary">Primary font</label>
      <input type="text" id="font_primary" name="design[fonts][primary]" value="<?= st_h($v('design', 'fonts', 'primary')) ?>">
      <p class="hint">Letters, numbers, spaces, commas and hyphens only (e.g. Arial or Inter, sans-serif).</p>
    </fieldset>

    <fieldset data-repeater>
      <legend>Navigation</legend>
      <div class="rows">
        <?php foreach (array_values($data['navigation'] ?? []) as $i => $item): ?>
          <?php st_nav_row($i, (string)($item['label'] ?? ''), (string)($item['url'] ?? '')); ?>
        <?php endforeach; ?>
      </div>
      <template><?php st_nav_row('__i__', '', ''); ?></template>
      <button type="button" data-add>+ Add link</button>
    </fieldset>

    <fieldset data-repeater>
      <legend>Social links</legend>
      <div class="rows">
        <?php foreach (array_values($data['social_links'] ?? []) as $i => $item): ?>
          <?php st_social_row($i, (string)($item['platform'] ?? ''), (string)($item['url'] ?? '')); ?>
        <?php endforeach; ?>
      </div>
      <template><?php st_social_row('__i__', '', ''); ?></template>
      <button type="button" data-add>+ Add link</button>
    </fieldset>

    <fieldset data-repeater>
      <legend>Head snippets</legend>
      <p class="hint">Raw HTML added to the &lt;head&gt; of every page (analytics scripts etc.). Only paste code you trust.</p>
      <div class="rows">
        <?php foreach (array_values($data['head'] ?? []) as $i => $item): ?>
          <?php st_head_row($i, (string)($item['html'] ?? '')); ?>
        <?php endforeach; ?>
      </div>
      <template><?php st_head_row('__i__', ''); ?></template>
      <button type="button" data-add>+ Add snippet</button>
    </fieldset>

    <button type="submit">Save settings</button>
  </form>

  <script>
    // Repeaters (navigation, social links, head snippets)
    document.querySelectorAll('[data-repeater]').forEach(function (rep) {
      var tpl = rep.querySelector('template');
      var list = rep.querySelector('.rows');
      var n = Date.now();
      rep.querySelector('[data-add]').addEventListener('click', function () {
        list.insertAdjacentHTML('beforeend', tpl.innerHTML.split('__i__').join(String(n++)));
      });
      rep.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove]');
        if (btn) btn.closest('.row').remove();
      });
    });

    // Keep color pickers and hex fields in sync
    document.querySelectorAll('.color-field').forEach(function (f) {
      var picker = f.querySelector('input[type=color]');
      var text = f.querySelector('input[type=text]');
      picker.addEventListener('input', function () { text.value = picker.value; });
      text.addEventListener('input', function () {
        if (/^#[0-9a-f]{6}$/i.test(text.value)) picker.value = text.value;
      });
    });
  </script>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
