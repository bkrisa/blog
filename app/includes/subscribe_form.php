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
  <lable class="subscribe-text"><?php echo nl_h($__nl['form_text']); ?></lable>
  <div class="subscribe-row">
    <input type="email" name="email" placeholder="Type your email..." required autocomplete="email" aria-label="Email address">
    <button type="submit"><?php echo nl_h($__nl['form_button']); ?></button>
  </div>
  <!-- honeypot: real visitors never see or fill it -->
  <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
  <?php if ($__nl['form_note'] !== ''): ?>
    <p class="subscribe-note"><?php echo nl_h($__nl['form_note']); ?></p>
  <?php endif; ?>
</form>
<?php endif; ?>
