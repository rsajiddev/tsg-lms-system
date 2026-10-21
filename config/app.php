<?php
declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'TSG Academy LMS',
    'env' => getenv('APP_ENV') ?: 'local',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim(getenv('APP_URL') ?: 'http://localhost/TSG-Academy-LMS', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Karachi',
    'session_name' => getenv('SESSION_NAME') ?: 'tsg_lms_session',
];
