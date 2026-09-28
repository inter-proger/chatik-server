<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/File.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$upload = $_FILES['file'] ?? null;

if ($upload === null || $upload['error'] === UPLOAD_ERR_NO_FILE) {
    Response::error(ErrorCode::FILE_MISSING);
}

if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
    Response::error(ErrorCode::FILE_TOO_LARGE);
}

if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
    Response::error(ErrorCode::FILE_UPLOAD_FAILED);
}

if ($upload['size'] > UPLOAD_MAX_SIZE) {
    Response::error(ErrorCode::FILE_TOO_LARGE);
}

// Имя файла: из параметра, иначе оригинальное имя загруженного файла
$filename = trim($_POST['filename'] ?? '');
if ($filename === '') {
    $filename = basename($upload['name']);
}
// Невалидные UTF-8 байты заменяем, иначе MySQL отклонит строку
$filename = mb_substr(mb_scrub($filename, 'UTF-8'), 0, 255);

$file = File::storeImage($upload, $filename, $userId);

Response::success($file);
