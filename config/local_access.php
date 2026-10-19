<?php

use App\Core\Env;

return [
    'enabled' => (bool) Env::get('LOCAL_SUPER_ACCESS_ENABLED', false),
    'token_hash' => (string) Env::get('LOCAL_SUPER_TOKEN_HASH', ''),
    'email' => (string) Env::get('LOCAL_SUPER_EMAIL', 'super@sungroup.local'),
    'reset_minutes' => 10,
];
