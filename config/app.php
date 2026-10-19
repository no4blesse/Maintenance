<?php

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'SUNGROUP Maintenance Center'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/'),
    'key' => (string) Env::get('APP_KEY', ''),
    'locale' => (string) Env::get('APP_LOCALE', 'th'),
    // Display/operations timezone. Storage is always UTC.
    'timezone' => (string) Env::get('APP_TIMEZONE', 'Asia/Bangkok'),
    'version' => '1.0.0',
];
