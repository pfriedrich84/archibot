#!/usr/bin/env php
<?php

use App\Models\EntityApproval;
use App\Models\User;
use App\Services\EntityApprovalDecisionService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $entityId, $userId, $action, $startAt] = $argv;
$delay = ((float) $startAt) - microtime(true);
if ($delay > 0) {
    usleep((int) ($delay * 1_000_000));
}

try {
    $command = $app->make(EntityApprovalDecisionService::class)->enqueue(
        EntityApproval::query()->findOrFail((int) $entityId),
        $action,
        User::query()->findOrFail((int) $userId),
    );
    fwrite(STDOUT, "OK:{$action}:{$command->id}\n");
} catch (DomainException $exception) {
    fwrite(STDOUT, "CONFLICT:{$action}:{$exception->getMessage()}\n");
}
