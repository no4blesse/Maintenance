<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/bootstrap/app.php';
/** One lock per job on the hosting filesystem; DB-level locks protect shared records. */
function run_job(string $name, callable $job): void
{
    $dir = BASE_PATH.'/storage/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $lock = fopen($dir.'/cron-'.$name.'.lock', 'c');
    if (!$lock) {
        fwrite(STDERR, "Cannot open job lock\n");
        exit(1);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        echo "Already running\n";
        return;
    }
    try {
        echo json_encode($job(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $e) {
        \App\Core\Logger::error('Cron failed', ['job' => $name,'error' => $e->getMessage()]);
        fwrite(STDERR, "Job failed; inspect private logs\n");
        exit(1);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
