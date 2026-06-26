<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

if (empty($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'Nenhuma imagem enviada ou erro no upload'], 400);
}

$file = $_FILES['imagen'];
$maxSize = 5 * 1024 * 1024;

if ($file['size'] > $maxSize) {
    jsonResponse(['error' => 'Imagem muito grande (máx. 5 MB)'], 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

if (!isset($allowed[$mime])) {
    jsonResponse(['error' => 'Formato não permitido. Use JPG, PNG, WEBP ou GIF'], 400);
}

ensureDataDir();
$filename = 'sorteo_' . uniqid() . '.' . $allowed[$mime];
$destino = UPLOADS_DIR . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destino)) {
    jsonResponse(['error' => 'Não foi possível salvar a imagem. Verifique permissões da pasta uploads/'], 500);
}

jsonResponse([
    'ok' => true,
    'url' => 'uploads/sorteos/' . $filename,
]);
