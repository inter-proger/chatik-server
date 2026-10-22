<?php

class ErrorCode
{
    // Validation
    const INVALID_LOGIN    = 1001;
    const INVALID_PASSWORD = 1002;
    const INVALID_NAME     = 1003;
    const INVALID_PARAMS   = 1004;
    const INVALID_TEXT     = 1005;
    const INVALID_CLIENT_ID = 1006;
    const INVALID_DESCRIPTION = 1007;

    // Auth
    const LOGIN_TAKEN      = 2001;
    const WRONG_CREDENTIALS = 2002;
    const NOT_AUTHENTICATED = 2003;
    const WS_TOKEN_INVALID  = 2004;

    // Files
    const FILE_MISSING      = 3001;
    const FILE_TOO_LARGE    = 3002;
    const FILE_NOT_IMAGE    = 3003;
    const FILE_NOT_FOUND    = 3004;
    const FILE_UPLOAD_FAILED = 3005;

    // Users
    const USER_NOT_FOUND    = 4001;

    // Conversations
    const CONVERSATION_WITH_SELF = 5001;
    const CONVERSATION_NOT_FOUND = 5002;
    const CONVERSATION_NOT_GROUP = 5003;
    const GROUP_FORBIDDEN        = 5004;
    const GROUP_MEMBERS_LIMIT    = 5005;
    const GROUP_ALREADY_MEMBER   = 5006;
    const GROUP_NOT_MEMBER       = 5007;
    const GROUP_OWNER_TARGET     = 5008;

    // Messages
    const MESSAGE_NOT_FOUND       = 6001;
    const MESSAGE_BLOCK_NOT_FOUND = 6002;
    const MESSAGE_EDIT_FORBIDDEN  = 6003;
    const MESSAGE_CLIENT_ID_TAKEN = 6004;

    // Server
    const INTERNAL_ERROR    = 9001;
}
