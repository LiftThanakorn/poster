<?php

namespace App\Jobs;

use App\Models\PublishTarget;
use App\Services\Publishers\FacebookPublisher;
use App\Services\Publishers\PublisherInterface;
use App\Services\Publishers\WordPressPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Deletes a post on WordPress or Facebook after the local post has been removed.
 *
 * Receives plain values instead of a PublishTarget model because the local
 * post (and its targets, via cascade) is already deleted when this job runs.
 */
class DeleteExternalPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $channel,
        public string $externalId,
        public int $postId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $target = (new PublishTarget)->forceFill([
            'channel' => $this->channel,
            'external_id' => $this->externalId,
        ]);

        if (! $this->publisher()->delete($target)) {
            throw new RuntimeException("Unable to delete {$this->channel} post {$this->externalId}.");
        }

        Log::info('External post deleted after local post removal', [
            'post_id' => $this->postId,
            'channel' => $this->channel,
            'external_id' => $this->externalId,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('External post deletion failed permanently', [
            'post_id' => $this->postId,
            'channel' => $this->channel,
            'external_id' => $this->externalId,
        ]);
    }

    private function publisher(): PublisherInterface
    {
        return match ($this->channel) {
            'wordpress' => app(WordPressPublisher::class),
            'facebook' => app(FacebookPublisher::class),
            default => throw new InvalidArgumentException("Unsupported channel: {$this->channel}"),
        };
    }
}
