<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../../tailscale_auth.php';
verifyTailscaleAccess();

$root_path = getRootPath();
$uploadFolder = __DIR__ . '/../uploads/';

if (!file_exists($uploadFolder)) {
  mkdir($uploadFolder, 0755, true);
}

// The saveAsWebp function attempts to convert an uploaded image to WebP format
function saveAsWebp(string $tmpPath, string $extension, string $destFolder, string $baseName): array {
  if ($extension === 'gif' && isAnimatedGif($tmpPath)) {
    $filename = $baseName . '.gif';
    return ['filename' => $filename, 'skippedConversion' => true];
  }

  if (!function_exists('imagewebp')) {
    $filename = $baseName . '.' . $extension;
    return ['filename' => $filename, 'skippedConversion' => true];
  }

  $imageData = file_get_contents($tmpPath);
  $image = @imagecreatefromstring($imageData);

  if ($image === false) {
    $filename = $baseName . '.' . $extension;
    return ['filename' => $filename, 'skippedConversion' => true];
  }

  // Ensure the image is in true color and has alpha blending enabled for transparency
  imagepalettetotruecolor($image);
  imagealphablending($image, true);
  imagesavealpha($image, true);

  $filename = $baseName . '.webp';
  $destPath = $destFolder . $filename;

  $success = imagewebp($image, $destPath, 82);
  imagedestroy($image);

  if (!$success) {
    $filename = $baseName . '.' . $extension;
    return ['filename' => $filename, 'skippedConversion' => true];
  }

  return ['filename' => $filename, 'skippedConversion' => false];
}

function isAnimatedGif(string $path): bool {
  $contents = file_get_contents($path);
  $frameCount = substr_count($contents, "\x00\x2C");
  return $frameCount > 1;
}

if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
  $fileTmpPath = $_FILES['file']['tmp_name'];
  $fileName    = $_FILES['file']['name'];

  $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

  if (in_array($fileExtension, $allowedExtensions)) {
    $rawName = pathinfo($fileName, PATHINFO_FILENAME);

    $unaccented = strtr($rawName, [
      'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ö'=>'o', 'ő'=>'o', 'ú'=>'u', 'ü'=>'u', 'ű'=>'u',
      'Á'=>'A', 'É'=>'E', 'Í'=>'I', 'Ó'=>'O', 'Ö'=>'O', 'Ő'=>'O', 'Ú'=>'U', 'Ü'=>'U', 'Ű'=>'U'
    ]);

    $slug = preg_replace('/\s+/', '-', $unaccented);
    $cleanName = preg_replace('/[^a-zA-Z0-9\-_]/', '', $slug);

    $baseName = $cleanName;
    $counter = 2;
    while (
      file_exists($uploadFolder . $baseName . '.webp')
      || file_exists($uploadFolder . $baseName . '.' . $fileExtension)
    ) {
      $baseName = $cleanName . '-' . substr(uniqid(), -6);
      if ($counter++ > 5) {
        break;
      }
    }

    $result = saveAsWebp($fileTmpPath, $fileExtension, $uploadFolder, $baseName);
    $finalFileName = $result['filename'];
    $destPath = $uploadFolder . $finalFileName;

    if ($result['skippedConversion']) {
      $moved = move_uploaded_file($fileTmpPath, $destPath);
    } else {
      $moved = file_exists($destPath);
    }

    if ($moved) {
      header('Content-Type: application/json');
      echo json_encode([
        'location' => $root_path . 'uploads/' . $finalFileName,
        'alt'      => $cleanName
      ]);
      exit;
    }
  }
}

http_response_code(400);
echo json_encode(['error' => 'The image could not be uploaded. Please ensure it is a valid image file and try again.']);