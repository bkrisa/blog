</main>
  <footer>
    <ul>
      <?php foreach ($settings['social_links'] as $item): ?>
        <li>
          <a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank" rel="noopener">
            <?php echo htmlspecialchars($item['platform']); ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($settings['site']['name']); ?> - All rights reserved.</p>
  </footer>
</body>
</html>
