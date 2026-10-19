<?php

declare(strict_types=1);

/*
 * Application bootstrap shared by web (public/index.php), CLI scripts,
 * cron jobs and the test suite.
 */

use App\Core\Config;
use App\Core\Env;
use App\Core\Lang;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    // Fallback PSR-4 autoloader so the app also runs without Composer's vendor/
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
    require_once BASE_PATH . '/app/Helpers/functions.php';
}

if (!defined('SGMC_TESTING')) {
    Env::load(BASE_PATH . '/.env');
}
Config::load(BASE_PATH . '/config');

// Internal clock is UTC; display conversion happens in App\Helpers\Time
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
Lang::setLocale((string) Config::get('app.locale', 'th'));

if (Config::get('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
