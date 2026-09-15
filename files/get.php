<?php

require_once __DIR__ . '/../core/File.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$fileId = $_REQUEST['file_id'] ?? '';

if (!ctype_digit((string) $fileId) || (int) $fileId <= 0) {
    Response::error(ErrorCode::FILE_NOT_FOUND);
}

$file = File::findById((int) $fileId);

if ($file === null) {
    Response::error(ErrorCode::FILE_NOT_FOUND);
}

Response::success($file);
