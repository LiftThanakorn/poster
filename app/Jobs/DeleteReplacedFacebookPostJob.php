<?php

namespace App\Jobs;

use App\Models\PublishTarget;
use App\Services\Publishers\FacebookPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeleteReplacedFacebookPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $targetId,
        public string $externalId,
    ) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(FacebookPublisher $publisher): void
    {
        $target = PublishTarget::query()->find($this->targetId);

        if (! $target || $target->status === 'detached') {
            return;
        }

        if ($target->channel !== 'facebook' || $target->status !== 'deleting' || $target->external_id !== $this->externalId) {
            throw new RuntimeException('Original Facebook target changed before deletion.');
        }

        if (! $publisher->delete($target)) {
            throw new RuntimeException('Facebook has not confirmed removal of the original post.');
        }

        $target->update(['status' => 'detached', 'error_message' => null]);

        Log::info('Replaced Facebook post deleted', ['publish_target_id' => $target->id]);
    }

    public function failed(?Throwable $exception): void
    {
        $target = PublishTarget::query()->find($this->targetId);
        if ($target && $target->status === 'deleting' && $target->external_id === $this->externalId) {
            $target->update([
                'status' => 'published',
                'error_message' => 'ลบโพสต์ Facebook เดิมไม่สำเร็จ โปรดตรวจสอบก่อนลองอีกครั้ง',
            ]);
        }

        Log::warning('Failed to delete replaced Facebook post', ['publish_target_id' => $this->targetId]);
    }
}
