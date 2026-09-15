<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/File.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$fileId = $_POST['fileId'] ?? '';

if (!ctype_digit((string) $fileId) || (int) $fileId <= 0) {
    Response::error(ErrorCode::FILE_NOT_FOUND);
}

$db = DB::get();

// Файл должен существовать и принадлежать текущему пользователю
$stmt = $db->prepare('SELECT type FROM files WHERE id = ? AND user_id = ?');
$stmt->execute([(int) $fileId, $userId]);
$file = $stmt->fetch();

if (!$file) {
    Response::error(ErrorCode::FILE_NOT_FOUND);
}

if ($file['type'] !== File::TYPE_IMAGE) {
    Response::error(ErrorCode::FILE_NOT_IMAGE);
}

$stmt = $db->prepare('UPDATE users SET avatar_file_id = ? WHERE id = ?');
$stmt->execute([(int) $fileId, $userId]);

Response::success(User::findById($userId));
