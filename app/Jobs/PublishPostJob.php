<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\Publishers\PublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public Post $post) {}

    public function uniqueId(): string
    {
        return (string) $this->post->getKey();
    }

    public function handle(PublishingService $publishing): void
    {
        $this->post->refresh();

        if (! in_array($this->post->status, ['publishing', 'scheduled'], true)) {
            return;
        }

        if ($this->post->status !== 'publishing') {
            $this->post->update(['status' => 'publishing']);
        }

        try {
            $publishing->ensureDriveSyncedForPost($this->post);
        } catch (\Throwable $e) {
            Log::warning('Drive sync failed in PublishPostJob: '.$e->getMessage());
        }

        $wordpressTarget = $this->post->targets()
            ->where('channel', 'wordpress')
            ->whereIn('status', ['pending', 'queued', 'processing', 'retrying', 'failed'])
            ->first();

        if ($wordpressTarget && $wordpressTarget->status !== 'published') {
            if ($wordpressTarget->status === 'failed') {
                $wordpressTarget->update([
                    'status' => 'pending',
                    'error_message' => null,
                ]);
            }

            PublishWordPressJob::dispatch($this->post->id, $wordpressTarget->id);
        }

        $facebookTarget = $this->post->targets()
            ->where('channel', 'facebook')
            ->whereIn('status', ['pending', 'queued', 'processing', 'retrying', 'failed'])
            ->first();

        if ($facebookTarget && $facebookTarget->status !== 'published') {
            if ($facebookTarget->status === 'failed') {
                $facebookTarget->update([
                    'status' => 'pending',
                    'error_message' => null,
                ]);
            }

            PublishFacebookJob::dispatch($this->post->id, $facebookTarget->id);
        }
    }
}
