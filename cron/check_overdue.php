<?php

require __DIR__.'/bootstrap.php';
run_job('overdue', fn () => ['overdue' => \App\Services\AlertService::overdue(),'pm_upcoming' => \App\Services\AlertService::upcomingPm(),'daily' => \App\Services\AlertService::daily()]);
