<?php

namespace App\Services\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishAttempt;
use App\Models\PublishEvent;
use App\Models\PublishTarget;
use App\Services\GoogleDriveService;
use App\Support\SecretSanitizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PublishingService
{
    public function publishPost(Post $post): void
    {
        $post->refresh();

        if (! in_array($post->status, ['publishing', 'scheduled'], true)) {
            return;
        }

        if ($post->status !== 'publishing') {
            $post->update(['status' => 'publishing']);
        }

        try {
            $this->ensureDriveSyncedForPost($post);
        } catch (Throwable $e) {
            Log::warning('Drive sync failed during publishPost: '.$e->getMessage());
        }

        $targets = $post->targets()
            ->where('status', '!=', 'detached')
            ->orderByRaw("CASE WHEN channel = 'wordpress' THEN 0 ELSE 1 END")
            ->get();

        foreach ($targets as $target) {
            if (in_array($target->status, ['published', 'detached'], true)) {
                continue;
            }

            $this->publishTarget($post, $target);
        }

        $this->updatePostStatus($post);
    }

    public function retryTarget(Post $post, string $channel): PublishTarget
    {
        $target = $post->targets()
            ->where('channel', $channel)
            ->where('status', '!=', 'detached')
            ->firstOrFail();

        if ($target->status === 'published') {
            throw new \InvalidArgumentException('ไม่สามารถลองใหม่ได้ เนื่องจากช่องทางนี้เผยแพร่สำเร็จแล้ว');
        }

        $target->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        $post->update(['status' => 'publishing']);

        $this->publishTarget($post->fresh(), $target->fresh());

        return $target->fresh();
    }

    /**
     * @return array{success:bool,retryable:bool,retry_delay:int}
     */
    public function publishTarget(Post $post, PublishTarget $target): array
    {
        $target->refresh();

        if ($target->status === 'published' || $target->status === 'detached') {
            return ['success' => true, 'retryable' => false, 'retry_delay' => 0];
        }

        $attemptNumber = ((int) $target->publishAttempts()->max('attempt_number')) + 1;
        $correlationId = (string) Str::uuid();
        $startedAt = now();
        $startedMs = microtime(true);

        $attempt = PublishAttempt::query()->create([
            'correlation_id' => $correlationId,
            'post_id' => $post->id,
            'publish_target_id' => $target->id,
            'channel' => $target->channel,
            'attempt_number' => $attemptNumber,
            'status' => 'processing',
            'queued_at' => now(),
            'started_at' => $startedAt,
            'retryable' => false,
        ]);

        $target->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        $this->event(
            $post,
            $target,
            $correlationId,
            'info',
            'publish_started',
            $target->channel === 'wordpress'
                ? 'กำลังเผยแพร่ข่าวไปยัง WordPress'
                : 'กำลังเผยแพร่โพสต์ไปยัง Facebook',
            ['attempt' => $attemptNumber],
        );

        try {
            $this->ensureDriveSyncedForPost($post, $correlationId);

            $publisher = $this->publisherFor($target->channel);
            $result = $target->external_id
                ? $publisher->update($post, $target)
                : $publisher->publish($post, $target);

            if (($result['success'] ?? false) === true) {
                $target->update([
                    'status' => 'published',
                    'external_id' => $result['external_id'] ?? $target->external_id,
                    'external_url' => $result['external_url'] ?? $target->external_url,
                    'error_message' => null,
                ]);

                $attempt->update([
                    'status' => 'published',
                    'finished_at' => now(),
                    'duration_ms' => (int) round((microtime(true) - $startedMs) * 1000),
                    'retryable' => false,
                    'metadata' => [
                        'external_id' => $target->fresh()->external_id,
                    ],
                ]);

                $this->event(
                    $post,
                    $target->fresh(),
                    $correlationId,
                    'info',
                    'publish_succeeded',
                    $target->channel === 'wordpress'
                        ? 'เผยแพร่ข่าวไปยัง WordPress สำเร็จ'
                        : 'เผยแพร่โพสต์ไปยัง Facebook สำเร็จ',
                    ['external_url' => $target->fresh()->external_url],
                );

                $this->updatePostStatus($post);

                return ['success' => true, 'retryable' => false, 'retry_delay' => 0];
            }

            $failure = PublishFailureClassifier::classifyMessage(
                message: (string) ($result['error'] ?? 'Unknown publishing error'),
                channel: $target->channel,
                attempt: $attemptNumber,
                httpStatus: isset($result['http_status']) ? (int) $result['http_status'] : null,
                errorCode: ($result['error_code'] ?? '') !== '' ? (string) $result['error_code'] : null,
                externalSubcode: ($result['error_subcode'] ?? '') !== '' ? (string) $result['error_subcode'] : null,
            );

            $this->recordFailure(
                $post,
                $target,
                $attempt,
                $correlationId,
                $failure,
                $startedMs,
                (string) ($result['error'] ?? ''),
            );

            return [
                'success' => false,
                'retryable' => $failure->isRetryable,
                'retry_delay' => $failure->retryDelaySeconds,
            ];
        } catch (Throwable $exception) {
            $failure = PublishFailureClassifier::classifyThrowable($exception, $target->channel, $attemptNumber);

            $this->recordFailure(
                $post,
                $target,
                $attempt,
                $correlationId,
                $failure,
                $startedMs,
                SecretSanitizer::sanitizeThrowable($exception),
            );

            return [
                'success' => false,
                'retryable' => $failure->isRetryable,
                'retry_delay' => $failure->retryDelaySeconds,
            ];
        }
    }

    public function updatePostStatus(Post $post): void
    {
        $post->refresh();
        $targets = $post->targets()->where('status', '!=', 'detached')->get();

        if ($targets->isEmpty()) {
            return;
        }

        $total = $targets->count();
        $published = $targets->where('status', 'published')->count();
        $failed = $targets->where('status', 'failed')->count();
        $active = $targets->whereIn('status', ['pending', 'queued', 'processing', 'retrying'])->count();

        if ($published === $total) {
            $post->update([
                'status' => 'published',
                'published_at' => $post->published_at ?? now(),
            ]);

            return;
        }

        if ($failed === $total) {
            $post->update(['status' => 'failed']);

            return;
        }

        if ($published > 0 && $active === 0) {
            $post->update([
                'status' => 'partially_published',
                'published_at' => $post->published_at ?? now(),
            ]);

            return;
        }

        $post->update(['status' => 'publishing']);
    }

    public function recoverStaleJobs(int $minutes = 15): int
    {
        $cutoff = now()->subMinutes($minutes);
        $count = 0;

        PublishTarget::query()
            ->where('status', 'processing')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($targets) use (&$count): void {
                foreach ($targets as $target) {
                    $target->update([
                        'status' => 'failed',
                        'error_message' => 'Stale processing job exceeded the allowed processing window.',
                    ]);

                    $this->event(
                        $target->post,
                        $target,
                        (string) Str::uuid(),
                        'error',
                        'stale_job_recovered',
                        'งานเผยแพร่ค้างนานเกินกำหนด ระบบทำเครื่องหมายว่าล้มเหลวเพื่อให้ลองใหม่ได้',
                    );

                    $this->updatePostStatus($target->post);
                    $count++;
                }
            });

        return $count;
    }

    private function recordFailure(
        Post $post,
        PublishTarget $target,
        PublishAttempt $attempt,
        string $correlationId,
        ClassificationResult $failure,
        float $startedMs,
        string $technicalMessage,
    ): void {
        $newRetryCount = $failure->isRetryable
            ? min(5, ((int) $target->retry_count) + 1)
            : (int) $target->retry_count;

        $target->update([
            'status' => $failure->isRetryable && $newRetryCount < 5 ? 'retrying' : 'failed',
            'error_message' => $failure->messageTh,
            'retry_count' => $newRetryCount,
        ]);

        $attempt->update([
            'status' => $target->fresh()->status === 'retrying' ? 'retrying' : 'failed',
            'finished_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $startedMs) * 1000),
            'error_type' => $failure->category,
            'error_code' => $failure->errorCode,
            'error_message' => $failure->messageTh,
            'http_status' => $failure->httpStatus,
            'retryable' => $failure->isRetryable,
            'metadata' => [
                'technical_message' => SecretSanitizer::sanitize($technicalMessage),
                'retry_delay_seconds' => $failure->retryDelaySeconds,
            ],
        ]);

        $this->event(
            $post,
            $target->fresh(),
            $correlationId,
            $failure->isRetryable ? 'warning' : 'error',
            $failure->isRetryable ? 'publish_retrying' : 'publish_failed',
            $failure->messageTh,
            [
                'attempt' => $attempt->attempt_number,
                'retryable' => $failure->isRetryable,
                'retry_delay_seconds' => $failure->retryDelaySeconds,
            ],
        );

        Log::warning('Publishing attempt failed', [
            'correlation_id' => $correlationId,
            'post_id' => $post->id,
            'publish_target_id' => $target->id,
            'channel' => $target->channel,
            'category' => $failure->category,
            'retryable' => $failure->isRetryable,
        ]);

        $this->updatePostStatus($post);
    }

    private function publisherFor(string $channel): PublisherInterface
    {
        return match ($channel) {
            'wordpress' => app(WordPressPublisher::class),
            'facebook' => app(FacebookPublisher::class),
            default => throw new \InvalidArgumentException("Unsupported publishing channel [{$channel}]."),
        };
    }

    public function ensureDriveSyncedForPost(Post $post, ?string $correlationId = null): bool
    {
        if (! $post->pdf_media_id) {
            return false;
        }

        $pdfMedia = $post->pdfMedia ?: PostMedia::find($post->pdf_media_id);
        if (! $pdfMedia) {
            return false;
        }

        $needsDrive = (bool) $post->drive_sync_enabled
            || str_contains((string) $post->fb_caption, '{drive_url}')
            || str_contains((string) $post->fb_caption, '{{drive_url}}')
            || str_contains((string) $post->fb_comment, '{drive_url}')
            || str_contains((string) $post->fb_comment, '{{drive_url}}')
            || str_contains((string) $post->fb_comment_2, '{drive_url}')
            || str_contains((string) $post->fb_comment_2, '{{drive_url}}')
            || str_contains((string) $post->wp_content, '{drive_url}')
            || str_contains((string) $post->wp_content, '{{drive_url}}');

        if (! $needsDrive) {
            return false;
        }

        // Check if already synced and has drive_web_view_link
        if ($pdfMedia->drive_sync_status === 'synced' && $pdfMedia->drive_file_id && $pdfMedia->drive_web_view_link) {
            return true;
        }

        $corr = $correlationId ?: (string) Str::uuid();
        $this->event(
            $post,
            null,
            $corr,
            'info',
            'drive_sync_started',
            'กำลังจัดเก็บไฟล์ประกาศขึ้น Google Drive อัตโนมัติ',
            ['pdf_media_id' => $pdfMedia->id]
        );

        try {
            $driveService = app(GoogleDriveService::class);
            $driveService->syncPdfMedia($pdfMedia, [
                'employee_category' => $post->drive_employee_category ?: $pdfMedia->drive_employee_category,
                'announcement_stage' => $post->drive_announcement_stage ?: $pdfMedia->drive_announcement_stage,
                'document_date' => $post->drive_document_date?->format('Y-m-d') ?: ($pdfMedia->drive_document_date?->format('Y-m-d') ?: date('Y-m-d')),
                'position_title' => $post->drive_position_title ?: $pdfMedia->drive_position_title,
                'custom_filename' => $post->drive_custom_filename ?: $pdfMedia->drive_file_name,
            ]);

            $this->event(
                $post,
                null,
                $corr,
                'info',
                'drive_sync_succeeded',
                'จัดเก็บไฟล์ประกาศขึ้น Google Drive สำเร็จ',
                ['drive_web_view_link' => $pdfMedia->fresh()->drive_web_view_link]
            );

            return true;
        } catch (Throwable $e) {
            $this->event(
                $post,
                null,
                $corr,
                'error',
                'drive_sync_failed',
                'จัดเก็บไฟล์ประกาศขึ้น Google Drive ไม่สำเร็จ: '.$e->getMessage(),
                ['error' => SecretSanitizer::sanitize($e->getMessage())]
            );
            throw $e;
        }
    }

    private function event(
        Post $post,
        ?PublishTarget $target,
        string $correlationId,
        string $level,
        string $eventType,
        string $message,
        array $context = [],
    ): PublishEvent {
        return PublishEvent::query()->create([
            'correlation_id' => $correlationId,
            'post_id' => $post->id,
            'publish_target_id' => $target?->id,
            'channel' => $target?->channel,
            'level' => $level,
            'event_type' => $eventType,
            'message' => $message,
            'context' => SecretSanitizer::sanitizeArray($context),
        ]);
    }
}
