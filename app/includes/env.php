<?php
function loadEnv($path) {
  if (!is_readable($path)) {
    throw new Exception(".env file not found or not readable at: $path");
  }

  // Read file content safely
  $content = file_get_contents($path);
  if ($content === false) {
    throw new Exception("Failed to read .env file at: $path");
  }

  $lines = preg_split('/\\r\\n|\\r|\\n/', $content);
  
  foreach ($lines as $line) {
    $line = trim($line);
    
    // Skip empty lines and comments
    if (empty($line) || strpos($line, '#') === 0) {
      continue;
    }

    // Parse key-value pairs
    if (strpos($line, '=') !== false) {
      list($key, $value) = array_map('trim', explode('=', $line, 2));
      
      // Validate key format
      if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
        error_log("Warning: Invalid environment variable key format: $key");
        continue;
      }

      // Remove quotes if present
      $value = preg_replace('/^[\'"](.*)[\'"]\z/', '$1', $value);
      
      // Set in $_ENV and $_SERVER
      $_ENV[$key] = $value;
      $_SERVER[$key] = $value;
    }
  }
}

// Usage:
try {
  loadEnv(__DIR__ . '/../../.env');
} catch (Exception $e) {
  error_log('Error loading .env file: ' . $e->getMessage());
}