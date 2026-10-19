<?php

use App\Core\Env;

return [
    'name' => (string) Env::get('SESSION_NAME', 'sgmc_session'),
    'lifetime_minutes' => (int) Env::get('SESSION_LIFETIME_MINUTES', 120),
    'secure_cookie' => (bool) Env::get('SESSION_SECURE_COOKIE', true),
    // Private session storage outside public root
    'save_path' => BASE_PATH . '/storage/sessions',
    'login_max_attempts' => (int) Env::get('LOGIN_MAX_ATTEMPTS', 5),
    'login_lock_minutes' => (int) Env::get('LOGIN_LOCK_MINUTES', 15),
];
