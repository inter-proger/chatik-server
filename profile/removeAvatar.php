<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Response.php';

$userId = Auth::requireUserId();

$stmt = DB::get()->prepare('UPDATE users SET avatar_file_id = NULL WHERE id = ?');
$stmt->execute([$userId]);

Response::success(User::findById($userId));
