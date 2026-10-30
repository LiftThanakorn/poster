<?php

namespace Tests\Feature\E2E;

use App\Models\Post;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\Publishers\PublishingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkerAndRecoveryE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function createPost(array $attributes = []): Post
    {
        return Post::create(array_merge([
            'title' => 'Test Post '.uniqid(),
            'slug' => 'test-post-'.uniqid(),
            'wp_content' => '<p>Article body</p>',
            'fb_caption' => 'Facebook caption',
            'fb_comment' => null,
            'status' => 'publishing',
            'author_id' => $this->user->id,
            'published_at' => null,
        ], $attributes));
    }

    // =========================================================================
    // FEATURE 5: Target Status Migration (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f05_publish_target_accepts_retrying_and_detached_statuses(): void
    {
        $post = $this->createPost();

        try {
            $target1 = PublishTarget::create([
                'post_id' => $post->id,
                'channel' => 'wordpress',
                'status' => 'retrying',
            ]);

            $this->assertSame('retrying', $target1->fresh()->status);

            $target1->update(['status' => 'detached']);
            $this->assertSame('detached', $target1->fresh()->status);
        } catch (QueryException $e) {
            $this->fail('Target status migration (Feature 5) not applied yet: publish_targets.status still restricts values via enum constraint.');
        }
    }

    public function test_f05_publish_target_accepts_queued_status(): void
    {
        $post = $this->createPost();

        try {
            $target = PublishTarget::create([
                'post_id' => $post->id,
                'channel' => 'facebook',
                'status' => 'queued',
            ]);

            $this->assertSame('queued', $target->fresh()->status);
        } catch (QueryException $e) {
            $this->fail('Target status migration (Feature 5) not applied yet: publish_targets.status still restricts values via enum constraint.');
        }
    }

    public function test_f05_publish_target_status_defaults_to_pending(): void
    {
        $post = $this->createPost();

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
        ]);

        $this->assertSame('pending', $target->fresh()->status);
    }

    // =========================================================================
    // FEATURE 11: Windows Worker Auto-Start Script (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f11_start_worker_script_exists_in_scripts_directory(): void
    {
        $scriptPath = base_path('scripts/start-worker.ps1');
        $this->assertTrue(
            File::exists($scriptPath),
            "Operational artifact scripts/start-worker.ps1 must exist at [{$scriptPath}]."
        );
    }

    public function test_f11_start_worker_script_contains_php_detection_and_queue_work(): void
    {
        $scriptPath = base_path('scripts/start-worker.ps1');
        if (! File::exists($scriptPath)) {
            $this->fail('scripts/start-worker.ps1 does not exist yet (Feature 11).');
        }

        $content = File::get($scriptPath);

        $this->assertTrue(
            str_contains($content, 'queue:work') || str_contains($content, 'queue:listen'),
            'start-worker.ps1 must execute artisan queue:work.'
        );

        $this->assertTrue(
            str_contains($content, 'php.exe') || str_contains($content, 'laragon'),
            'start-worker.ps1 must support Laragon or dynamic PHP detection.'
        );
    }

    // =========================================================================
    // FEATURE 12: Linux Supervisor Documentation (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f12_supervisor_documentation_exists(): void
    {
        $docPath = base_path('docs/supervisor.md');
        $this->assertTrue(
            File::exists($docPath),
            "Operational artifact docs/supervisor.md must exist at [{$docPath}]."
        );
    }

    public function test_f12_supervisor_documentation_defines_production_worker_config(): void
    {
        $docPath = base_path('docs/supervisor.md');
        if (! File::exists($docPath)) {
            $this->fail('docs/supervisor.md does not exist yet (Feature 12).');
        }

        $content = File::get($docPath);

        $this->assertStringContainsString('program:', $content, 'Must contain supervisor [program:...] block.');
        $this->assertStringContainsString('artisan queue:work', $content, 'Must specify artisan queue:work command.');
        $this->assertStringContainsString('autostart', $content);
        $this->assertStringContainsString('autorestart', $content);
    }

    // =========================================================================
    // FEATURE 13: Stale Job Recovery Command (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f13_recover_stale_jobs_command_registered_in_artisan(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey(
            'poster:recover-stale-jobs',
            $commands,
            'Artisan command poster:recover-stale-jobs must be registered.'
        );
    }

    public function test_f13_stale_job_recovery_marks_processing_target_over_15m_as_failed(): void
    {
        $post = $this->createPost([
            'status' => 'publishing',
        ]);

        $staleTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'processing',
        ]);

        // Manually age target updated_at to 20 minutes ago
        $staleTarget->timestamps = false;
        $staleTarget->updated_at = Carbon::now()->subMinutes(20);
        $staleTarget->save();

        if (array_key_exists('poster:recover-stale-jobs', Artisan::all())) {
            Artisan::call('poster:recover-stale-jobs');
        } elseif (class_exists(PublishingService::class) && method_exists(PublishingService::class, 'recoverStaleJobs')) {
            app(PublishingService::class)->recoverStaleJobs(15);
        } else {
            $this->fail('Command poster:recover-stale-jobs not registered yet (Feature 13).');
        }

        $staleTarget->refresh();
        $this->assertSame('failed', $staleTarget->status, 'Stale target (>15m) must be marked as failed.');
        $this->assertStringContainsString('stale', strtolower($staleTarget->error_message ?? 'stale'));
    }

    public function test_f13_stale_job_recovery_ignores_recently_updated_targets(): void
    {
        $post = $this->createPost([
            'status' => 'publishing',
        ]);

        $freshTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'processing',
        ]);

        // Active target running for only 5 minutes
        $freshTarget->timestamps = false;
        $freshTarget->updated_at = Carbon::now()->subMinutes(5);
        $freshTarget->save();

        if (array_key_exists('poster:recover-stale-jobs', Artisan::all())) {
            Artisan::call('poster:recover-stale-jobs');
            $freshTarget->refresh();
            $this->assertSame('processing', $freshTarget->status, 'Active target (<15m) must remain processing.');
        } else {
            $this->fail('Command poster:recover-stale-jobs not registered yet (Feature 13).');
        }
    }

    public function test_f13_stale_job_recovery_boundary_14m59s_vs_15m01s(): void
    {
        $post = $this->createPost(['status' => 'publishing']);

        $justUnder = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'processing',
        ]);
        $justUnder->timestamps = false;
        $justUnder->updated_at = Carbon::now()->subSeconds(14 * 60 + 59); // 14m 59s
        $justUnder->save();

        $post2 = $this->createPost(['status' => 'publishing']);
        $justOver = PublishTarget::create([
            'post_id' => $post2->id,
            'channel' => 'facebook',
            'status' => 'processing',
        ]);
        $justOver->timestamps = false;
        $justOver->updated_at = Carbon::now()->subSeconds(15 * 60 + 1); // 15m 01s
        $justOver->save();

        if (array_key_exists('poster:recover-stale-jobs', Artisan::all())) {
            Artisan::call('poster:recover-stale-jobs');

            $this->assertSame('processing', $justUnder->fresh()->status, 'Target under 15m must remain processing.');
            $this->assertSame('failed', $justOver->fresh()->status, 'Target over 15m must transition to failed.');
        } else {
            $this->fail('Command poster:recover-stale-jobs not registered yet (Feature 13).');
        }
    }
}
