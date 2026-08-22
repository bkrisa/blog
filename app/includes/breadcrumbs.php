<?php
function generate_breadcrumbs($current_page = '', $current_title = '', $section = array(), $framework = null) {
  // Input validation
  $current_page = strip_tags(trim($current_page));
  $current_title = strip_tags(trim($current_title));

  // Base URL
  $base_url = 'https://' . $GLOBALS['config']['site_domain'];

  // Root path validation
  $root_path = isset($GLOBALS['root_path']) ? filter_var($GLOBALS['root_path'], FILTER_SANITIZE_URL) : '';

  // Section array validation
  if (!is_array($section)) {
    $section = array('title' => '', 'path' => '');
  }
  $section['title'] = isset($section['title']) ? strip_tags(trim($section['title'])) : '';
  $section['path'] = isset($section['path']) ? filter_var($section['path'], FILTER_SANITIZE_URL) : '';

  // If the section path already contains the domain name, use it, otherwise build it
  $section_url = (strpos($section['path'], 'http') === 0) ?
  $section['path'] :
  $base_url . '/' . ltrim($section['path'], '/');

  // Prepare breadcrumb items
  $breadcrumbItems = [
    [
      'title' => 'Home',
      'url' => $base_url,
      'position' => 1
    ]
  ];

  // Add section (Categories, Components, etc.)
  $breadcrumbItems[] = [
    'title' => $section['title'],
    'url' => $section_url,
    'position' => 2,
    'current' => ($current_page === 'index' && !$framework)
  ];

  // Add current page title if not index
  if ($current_page !== 'index' && $current_title) {
    // If there is a framework, this will be the intermediate element (linkable)
    $breadcrumbItems[] = [
      'title' => $current_title,
      'url' => $framework ? ($section_url . '/' . strtolower(str_replace(' ', '-', $current_title))) : htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'),
      'position' => 3,
      'current' => !$framework
    ];
  }
  
  // Generate HTML breadcrumbs
  echo '<nav class="breadcrumbs" aria-label="Breadcrumb">';
  echo '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';
  foreach ($breadcrumbItems as $item) {
    echo '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
    if (isset($item['current']) && $item['current']) {
      echo '<span itemprop="name">' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</span>';
    } else {
      echo '<a href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '" itemprop="item">';
      echo '<span itemprop="name">' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
    echo '<meta itemprop="position" content="' . $item['position'] . '" />';
    echo '</li>';
  }
  echo '</ol>';
  echo '</nav>';
}