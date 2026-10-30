<?php

namespace App\Console\Commands;

use App\Models\PublishTarget;
use App\Services\FacebookDiagnosticsService;
use App\Support\SecretSanitizer;
use Illuminate\Console\Command;

class CollectFacebookDiagnosticsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'facebook:collect-diagnostics {--limit=20 : Maximum number of latest published targets to collect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect read-only diagnostics (metadata + insights) for published Facebook targets';

    /**
     * Execute the console command.
     */
    public function handle(FacebookDiagnosticsService $service): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: 20));

        $targets = PublishTarget::query()
            ->where('channel', 'facebook')
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($targets->isEmpty()) {
            $this->info('No published Facebook targets found.');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Examined', 0],
                    ['Collected (Success)', 0],
                    ['Collected (Partial)', 0],
                    ['Skipped', 0],
                    ['Failed', 0],
                ]
            );

            return self::SUCCESS;
        }

        $this->info("Found {$targets->count()} Facebook target(s) to inspect. Collecting diagnostics...");

        $collectedSuccess = 0;
        $collectedPartial = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($targets as $target) {
            $externalId = (string) $target->external_id;

            if ($externalId === '') {
                $skipped++;
                $this->line("Target #{$target->id}: Skipped (empty external_id)");

                continue;
            }

            try {
                $snapshot = $service->collectForTarget($target);

                if ($snapshot->status === 'success') {
                    $collectedSuccess++;
                    $this->line("Target #{$target->id} [{$externalId}]: Success");
                } elseif ($snapshot->status === 'partial') {
                    $collectedPartial++;
                    $this->line("Target #{$target->id} [{$externalId}]: Partial (some metrics or metadata unavailable)");
                } else {
                    $failed++;
                    $sanitizedError = SecretSanitizer::sanitize((string) $snapshot->error);
                    $this->warn("Target #{$target->id} [{$externalId}]: Failed - {$sanitizedError}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $sanitizedError = SecretSanitizer::sanitizeThrowable($e);
                $this->error("Target #{$target->id} [{$externalId}]: Unexpected error - {$sanitizedError}");
            }
        }

        $this->newLine();
        $this->info('Diagnostics Collection Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Examined', $targets->count()],
                ['Collected (Success)', $collectedSuccess],
                ['Collected (Partial)', $collectedPartial],
                ['Skipped', $skipped],
                ['Failed', $failed],
            ]
        );

        return self::SUCCESS;
    }
}
