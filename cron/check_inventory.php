<?php

require __DIR__.'/bootstrap.php';
run_job('inventory', fn () => ['low_stock' => \App\Services\AlertService::inventory()]);
