<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostMedia;
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

class PublishFacebookJob implements ShouldQueue
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

        if (! $post || ! $target || $target->channel !== 'facebook') {
            return;
        }

        if (in_array($target->status, ['published', 'detached'], true)) {
            return;
        }

        $wordpress = $post->targets()
            ->where('channel', 'wordpress')
            ->where('status', '!=', 'detached')
            ->first();

        $needsWordPressUrl = str_contains((string) $post->fb_caption, '{wordpress_url}')
            || str_contains((string) $post->fb_caption, '{{wordpress_url}}')
            || str_contains((string) $post->fb_comment, '{wordpress_url}')
            || str_contains((string) $post->fb_comment, '{{wordpress_url}}')
            || str_contains((string) $post->fb_comment_2, '{wordpress_url}')
            || str_contains((string) $post->fb_comment_2, '{{wordpress_url}}');

        if ($wordpress && $needsWordPressUrl && $wordpress->status === 'failed') {
            $target->update([
                'status' => 'failed',
                'error_message' => 'ไม่สามารถเผยแพร่ Facebook ได้ เนื่องจากลิงก์ WordPress ยังไม่พร้อมใช้งาน',
            ]);
            $publishing->updatePostStatus($post);

            return;
        }

        if (
            $wordpress
            && $needsWordPressUrl
            && in_array($wordpress->status, ['pending', 'queued', 'processing', 'retrying'], true)
        ) {
            if ($this->job !== null) {
                $this->release(10);
            }

            return;
        }

        $needsDriveUrl = str_contains((string) $post->fb_caption, '{drive_url}')
            || str_contains((string) $post->fb_caption, '{{drive_url}}')
            || str_contains((string) $post->fb_comment, '{drive_url}')
            || str_contains((string) $post->fb_comment, '{{drive_url}}')
            || str_contains((string) $post->fb_comment_2, '{drive_url}')
            || str_contains((string) $post->fb_comment_2, '{{drive_url}}');

        if ($post->pdf_media_id && ($needsDriveUrl || $post->drive_sync_enabled)) {
            $pdfMedia = $post->pdfMedia ?: PostMedia::find($post->pdf_media_id);
            if ($pdfMedia && $pdfMedia->drive_sync_status === 'uploading') {
                if ($this->job !== null) {
                    $this->release(10);
                }

                return;
            }

            if ($pdfMedia && $needsDriveUrl && $pdfMedia->drive_sync_status === 'failed') {
                $target->update([
                    'status' => 'failed',
                    'error_message' => 'ไม่สามารถเผยแพร่ Facebook ได้ เนื่องจากไฟล์ใน Google Drive ยังไม่พร้อมใช้งาน',
                ]);
                $publishing->updatePostStatus($post);

                return;
            }
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
            'error_message' => 'Facebook queue job failed before publishing completed.',
        ]);

        PublishEvent::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'post_id' => $target->post_id,
            'publish_target_id' => $target->id,
            'channel' => 'facebook',
            'level' => 'error',
            'event_type' => 'job_failed',
            'message' => 'งานเผยแพร่ Facebook ล้มเหลวและหยุดการลองอัตโนมัติ',
        ]);

        app(PublishingService::class)->updatePostStatus($target->post);
    }
}
