<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Response.php';

$userId = Auth::requireUserId();

Response::success(Conversation::findTotalUnreadCount($userId));
