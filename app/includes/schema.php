<?php
function schema_script(array $json): string {
  return '<script type="application/ld+json">'
    . json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . '</script>' . PHP_EOL;
}

function schema_iso($date): string {
  return $date ? (new DateTimeImmutable($date))->format(DateTimeInterface::ATOM) : '';
}

function schema_person(array $settings): array {
  $site = $settings['site'];
  $sameAs = array_values(array_filter(array_column($settings['social_links'] ?? [], 'url')));

  $person = [
    '@type' => 'Person',
    'name'  => $settings['author']['name'],
    'url'   => rtrim($site['blog_url'], '/'),
    'image' => $site['author_img'] ?? $site['logo'] ?? null,
  ];

  if (!empty($site['twitter_handle'])) {
    $person['alternateName'] = '@' . ltrim($site['twitter_handle'], '@');
  }
  if ($sameAs) {
    $person['sameAs'] = $sameAs;
  }
  if (!empty($settings['author']['description'])) {
    $person['description'] = $settings['author']['description'];
  }

  return $person;
}

function generate_blog_index_schema(array $settings, array $posts): string {
  $blogUrl = rtrim($settings['site']['blog_url'], '/');
  $items = [];

  foreach ($posts as $i => $post) {
    $items[] = [
      '@type'    => 'ListItem',
      'position' => $i + 1,
      'url'      => $blogUrl . '/' . $post['slug'],
      'name'     => $post['title'],
    ];
  }

  return schema_script([
    '@context'    => 'https://schema.org',
    '@type'       => 'Blog',
    'name'        => $settings['site']['name'],
    'description' => $settings['author']['description'] ?? '',
    'url'         => $blogUrl,
    'inLanguage'  => $settings['site']['language'] ?? 'en',
    'image'       => $settings['site']['logo'] ?? null,
    'author'      => schema_person($settings),
    'publisher'   => [
      '@type' => 'Organization',
      'name'  => $settings['site']['name'],
      'logo'  => [
        '@type' => 'ImageObject',
        'url'   => $settings['site']['logo'],
      ],
    ],
    'blogPost' => array_map(function ($post) use ($blogUrl) {
      return [
        '@type'         => 'BlogPosting',
        'headline'      => $post['title'],
        'url'           => $blogUrl . '/' . $post['slug'],
        'datePublished' => schema_iso($post['created_at'] ?? null),
      ];
    }, $posts),
    'mainEntity' => [
      '@type'           => 'ItemList',
      'itemListElement' => $items,
    ],
  ]);
}

function generate_blog_post_schema(array $settings, array $post, array $tags = []): string
{
  $blogUrl = rtrim($settings['site']['blog_url'], '/');
  $url = $blogUrl . '/' . $post['slug'];

  $json = [
    '@context'         => 'https://schema.org',
    '@type'            => 'BlogPosting',
    'headline'         => $post['title'],
    'description'      => $post['excerpt'] ?? '',
    'url'              => $url,
    'mainEntityOfPage' => $url,
    'image'            => $post['image'] ?? $settings['site']['logo'] ?? null,
    'datePublished'    => schema_iso($post['created_at'] ?? null),
    'dateModified'     => schema_iso($post['updated_at'] ?? $post['created_at'] ?? null),
    'inLanguage'       => $settings['site']['language'] ?? 'en',
    'author'           => schema_person($settings),
    'publisher'        => [
      '@type' => 'Organization',
      'name'  => $settings['site']['name'],
      'logo'  => [
        '@type' => 'ImageObject',
        'url'   => $settings['site']['logo'],
      ],
    ],
  ];

  if ($tags) {
    $json['keywords'] = implode(', ', array_column($tags, 'title'));
  }

  return schema_script($json);
}

function generate_blog_tag_schema(array $settings, array $tag, array $posts = []): string {
  $blogUrl = rtrim($settings['site']['blog_url'], '/');
  $origin = preg_replace('#/blog/?$#', '', $blogUrl);
  $url = $origin . '/tags/' . $tag['slug'];
  $items = [];

  foreach ($posts as $i => $post) {
    $items[] = [
      '@type'    => 'ListItem',
      'position' => $i + 1,
      'url'      => $blogUrl . '/' . $post['slug'],
      'name'     => $post['title'],
    ];
  }

  return schema_script([
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => $tag['title'],
    'url'         => $url,
    'inLanguage'  => $settings['site']['language'] ?? 'en',
    'author'      => schema_person($settings),
    'isPartOf'    => [
      '@type' => 'Blog',
      'name'  => $settings['site']['name'],
      'url'   => $blogUrl,
    ],
    'mainEntity' => [
      '@type'           => 'ItemList',
      'itemListElement' => $items,
    ],
  ]);
}