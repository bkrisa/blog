<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';

$root_path = getRootPath();
$uploadFolder = __DIR__ . '/../uploads/';

if (!file_exists($uploadFolder)) {
  mkdir($uploadFolder, 0755, true);
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
    $cleanFileName = $cleanName . '.' . $fileExtension;
    $destPath = $uploadFolder . $cleanFileName;
    if (file_exists($destPath)) {
      $cleanFileName = $cleanName . '-' . substr(uniqid(), -6) . '.' . $fileExtension;
      $destPath = $uploadFolder . $cleanFileName;
    }

    if (move_uploaded_file($fileTmpPath, $destPath)) {
      header('Content-Type: application/json');
      echo json_encode([
        'location' => $root_path . 'uploads/' . $cleanFileName,
        'alt'      => $cleanName
      ]);
      exit;
    }
  }
}

http_response_code(400);
echo json_encode(['error' => 'The image could not be uploaded. Please ensure it is a valid image file and try again.']);