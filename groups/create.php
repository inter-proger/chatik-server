<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/File.php';
require_once __DIR__ . '/../core/Group.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$name = Group::normalizeName($_POST['name'] ?? '');
if ($name === null) {
    Response::error(ErrorCode::INVALID_NAME);
}

$description = Group::normalizeDescription($_POST['description'] ?? null);
if ($description === null) {
    Response::error(ErrorCode::INVALID_DESCRIPTION);
}

$avatarFileId = $_POST['avatarFileId'] ?? '';
if ($avatarFileId !== '') {
    $avatarFileId = Request::parseUint($avatarFileId) ?? 0;

    // Файл должен принадлежать текущему пользователю — как в /profile/setAvatar: чужой fileId
    // нельзя подставить в свою группу перебором
    $stmt = DB::get()->prepare('SELECT type FROM files WHERE id = ? AND user_id = ?');
    $stmt->execute([$avatarFileId, $userId]);
    $type = $stmt->fetchColumn();

    if ($type === false) {
        Response::error(ErrorCode::FILE_NOT_FOUND);
    }
    if ($type !== File::TYPE_IMAGE) {
        Response::error(ErrorCode::FILE_NOT_IMAGE);
    }
}

$id = Group::create($userId, $name, $description !== '' ? $description : null, $avatarFileId ?: null);

$conversation = Conversation::findById($id, $userId);
$groupInfo    = Group::findInfo($id);

Group::publishCreated($conversation, $groupInfo, $userId);

Response::success([
    'conversation' => $conversation,
    'groupInfo'    => $groupInfo,
], [
    Action::users([$userId]),
]);
