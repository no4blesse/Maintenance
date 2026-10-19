<?php

require __DIR__.'/bootstrap.php';
run_job('notifications', fn () => \App\Services\NotificationService::process((int)config('line.max_per_run', 50)));
