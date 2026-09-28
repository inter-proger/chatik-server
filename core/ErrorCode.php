<?php

class ErrorCode
{
    // Validation
    const INVALID_LOGIN    = 1001;
    const INVALID_PASSWORD = 1002;
    const INVALID_NAME     = 1003;
    const INVALID_PARAMS   = 1004;

    // Auth
    const LOGIN_TAKEN      = 2001;
    const WRONG_CREDENTIALS = 2002;
    const NOT_AUTHENTICATED = 2003;

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

    // Server
    const INTERNAL_ERROR    = 9001;
}
