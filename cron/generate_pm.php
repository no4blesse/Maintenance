<?php

require __DIR__.'/bootstrap.php';
run_job('pm', fn () => \App\Services\PMService::generateDue());
