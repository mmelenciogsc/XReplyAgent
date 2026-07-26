<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/wp-load.php';

use XReplyAgent\Domain\BrowserAutomation;

$jobId = 0;
foreach ($argv ?? [] as $index => $argument) {
    if ($index === 0) {
        continue;
    }

    if (str_starts_with((string) $argument, '--job-id=')) {
        $jobId = (int) substr((string) $argument, 9);
        break;
    }
}

if ($jobId <= 0) {
    fwrite(STDERR, "Missing job id.\n");
    exit(1);
}

BrowserAutomation::workerMain($jobId);
