<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\PublishTarget;
use App\Services\Publishers\PublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class PublishWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 5;

    public function __construct(
        public ?int $postId = null,
        public ?int $targetId = null,
    ) {}

    public function middleware(): array
    {
        if ($this->targetId === null) {
            return [];
        }

        return [
            (new WithoutOverlapping("publish-target:{$this->targetId}"))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    public function handle(PublishingService $publishing): void
    {
        if ($this->postId === null || $this->targetId === null) {
            return;
        }

        $post = Post::query()->find($this->postId);
        $target = PublishTarget::query()->find($this->targetId);

        if (! $post || ! $target || $target->channel !== 'wordpress') {
            return;
        }

        if (in_array($target->status, ['published', 'detached'], true)) {
            return;
        }

        $result = $publishing->publishTarget($post, $target);

        if (! $result['success'] && $result['retryable'] && $target->fresh()->status === 'retrying') {
            if (config('queue.default') === 'sync') {
                $target->refresh()->update([
                    'status' => 'failed',
                    'error_message' => $target->error_message ?: 'Retryable publishing error requires a background queue worker.',
                ]);
                $publishing->updatePostStatus($post);

                return;
            }

            if ($this->job !== null) {
                $this->release($result['retry_delay']);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->targetId === null) {
            return;
        }

        $target = PublishTarget::query()->find($this->targetId);
        if (! $target || in_array($target->status, ['published', 'detached'], true)) {
            return;
        }

        $target->update([
            'status' => 'failed',
            'error_message' => 'WordPress queue job failed before publishing completed.',
        ]);

        PublishEvent::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'post_id' => $target->post_id,
            'publish_target_id' => $target->id,
            'channel' => 'wordpress',
            'level' => 'error',
            'event_type' => 'job_failed',
            'message' => 'งานเผยแพร่ WordPress ล้มเหลวและหยุดการลองอัตโนมัติ',
        ]);

        app(PublishingService::class)->updatePostStatus($target->post);
    }
}
