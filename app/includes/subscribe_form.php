<?php
/**
 * Subscribe form partial: a short text, one input and a button.
 * Include it where you want it (e.g. under a post): require __DIR__ . '/includes/subscribe_form.php';
 */
require_once __DIR__ . '/newsletter.php';
$__nl = newsletterSettings();
if ($__nl['enabled']):
?>
<form class="subscribe" action="<?php echo nl_h(($root_path ?? '/') . 'newsletter'); ?>" method="POST">
  <p class="subscribe-text"><?php echo nl_h($__nl['form_text']); ?></p>
  <div class="subscribe-row">
    <input type="email" name="email" placeholder="you@example.com" required autocomplete="email" aria-label="Email address">
    <button type="submit"><?php echo nl_h($__nl['form_button']); ?></button>
  </div>
  <!-- honeypot: real visitors never see or fill it -->
  <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
  <?php if ($__nl['form_note'] !== ''): ?>
    <p class="subscribe-note"><?php echo nl_h($__nl['form_note']); ?></p>
  <?php endif; ?>
</form>
<script>
// Shows the thank-you message in place of the form. Without JavaScript the form still works (it redirects to a result page).
(function () {
  var form = document.currentScript.previousElementSibling;
  if (!form || !window.fetch || !window.FormData) { return; }
  var msg = document.createElement('p');
  msg.className = 'subscribe-message';
  msg.setAttribute('role', 'status');
  msg.hidden = true;
  form.parentNode.insertBefore(msg, form.nextSibling);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var button = form.querySelector('button');
    button.disabled = true;
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        msg.textContent = data.message;
        msg.hidden = false;
        if (data.ok) { form.hidden = true; } else { button.disabled = false; }
      })
      .catch(function () { form.submit(); }); // network trouble: fall back to the normal form post
  });
})();
</script>
<?php endif; ?>
