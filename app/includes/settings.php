<?php
// Load settings from JSON file
function loadSettings(): array {
  static $settings = null;

  if ($settings !== null) {
    return $settings;
  }

  $settingsFile = __DIR__ . '/../settings.json';

  if (!file_exists($settingsFile)) {
    die("Error: The settings.json file is missing. Please create one based on the README instructions.");
  }

  $jsonContent = file_get_contents($settingsFile);
  $settings = json_decode($jsonContent, true);

  if (json_last_error() !== JSON_ERROR_NONE) {
    $errorMsg = json_last_error_msg();
    die("<p>JSON Syntax Error: The settings.json file is invalid. Please check the file for errors.</p>
        <p><strong>Error details:</strong> {$errorMsg}</p>");
  }
  return $settings;
}

// Ensure $root_path always exists
function getRootPath(): string {
  $settings = loadSettings();
  $basePath = $settings['site']['blog_url'] ?? '/';
  return rtrim($basePath, '/') . '/';
}
