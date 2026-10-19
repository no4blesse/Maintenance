<?php

use App\Core\Env;

return [
    'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => (string) Env::get('DB_DATABASE', 'hotel_maintenance'),
    'username' => (string) Env::get('DB_USERNAME', 'root'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'test_database' => (string) Env::get('DB_TEST_DATABASE', 'hotel_maintenance_test'),
];
