<?php

namespace App\Console\Commands;

use App\Services\Publishers\PublishingService;
use Illuminate\Console\Command;

class RecoverStalePublishingJobs extends Command
{
    protected $signature = 'poster:recover-stale-jobs {--minutes=15 : Processing age in minutes before a target is considered stale}';

    protected $description = 'Mark stale publishing targets as failed so they can be retried safely.';

    public function handle(PublishingService $publishing): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $recovered = $publishing->recoverStaleJobs($minutes);

        $this->info("Recovered {$recovered} stale publishing target(s).");

        return self::SUCCESS;
    }
}
