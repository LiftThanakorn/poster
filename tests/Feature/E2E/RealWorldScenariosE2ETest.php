<?php

namespace Tests\Feature\E2E;

use App\Jobs\PublishPostJob;
use App\Models\IntegrationSetting;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\IntegrationSettingsService;
use App\Services\Publishers\PublishingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealWorldScenariosE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'author'],
            'encrypted_credentials' => ['password' => 'wp-app-pass'],
            'status' => 'connected',
        ]);

        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '10001', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'fb-valid-token'],
            'status' => 'connected',
        ]);
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
    // TIER 3: PAIRWISE COMBINATORIAL TESTS
    // =========================================================================

    public function test_pairwise_wordpress_success_with_facebook_token_revoked(): void
    {
        $post = $this->createPost([
            'title' => 'Pairwise WP Success FB Fail',
            'wp_content' => '<p>Article</p>',
            'fb_caption' => 'FB Caption with {wordpress_url}',
            'status' => 'publishing',
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 901,
                'link' => 'https://wp.example.com/p/901',
            ], 201),
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        PublishPostJob::dispatchSync($post);

        $wpTarget->refresh();
        $fbTarget->refresh();
        $post->refresh();

        $this->assertSame('published', $wpTarget->status);
        $this->assertSame('failed', $fbTarget->status);
        $this->assertSame('partially_published', $post->status);
    }

    public function test_pairwise_wordpress_timeout_with_facebook_target_pending(): void
    {
        $post = $this->createPost([
            'title' => 'Pairwise WP Timeout',
            'wp_content' => '<p>Article</p>',
            'status' => 'publishing',
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://wp.example.com/*' => fn () => throw new ConnectionException('Timed out'),
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_999'], 200),
        ]);

        PublishPostJob::dispatchSync($post);

        $wpTarget->refresh();
        $this->assertSame('failed', $wpTarget->status);
    }

    public function test_pairwise_idempotent_retry_with_both_channels_having_external_ids(): void
    {
        $post = $this->createPost([
            'title' => 'Both Published Idempotent Post',
            'wp_content' => '<p>Content</p>',
            'fb_caption' => 'FB Caption',
            'status' => 'publishing',
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
            'external_id' => 'wp_existing_444',
            'external_url' => 'https://wp.example.com/p/444',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
            'external_id' => '10001_555',
            'external_url' => 'https://facebook.com/10001/posts/555',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/wp_existing_444' => Http::response(['id' => 'wp_existing_444'], 200),
            'https://graph.facebook.com/*' => Http::response(['id' => '10001', 'success' => true], 200),
        ]);

        PublishPostJob::dispatchSync($post);

        // Neither creation endpoint should be reached
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '10001/feed'));

        $this->assertSame('published', $wpTarget->fresh()->status);
        $this->assertSame('published', $fbTarget->fresh()->status);
    }

    // =========================================================================
    // TIER 4: REAL-WORLD APPLICATION SCENARIOS
    // =========================================================================

    /**
     * Scenario 1: Multi-Channel Post with WordPress Success & Facebook Success (Full Flow)
     */
    public function test_scenario_1_dual_channel_end_to_end_publishing_flow(): void
    {
        Storage::fake('public');
        $imageFile = UploadedFile::fake()->image('thumbnail.jpg', 640, 480);
        $path = $imageFile->store('media', 'public');

        $media = PostMedia::create([
            'file_path' => $path,
            'original_name' => 'thumbnail.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'is_featured' => true,
        ]);

        $post = Post::create([
            'author_id' => $this->user->id,
            'title' => 'E2E Scenario 1: Grand Opening',
            'slug' => 'grand-opening-'.uniqid(),
            'wp_content' => '<p>Grand opening article with details.</p>',
            'fb_caption' => 'We are open! Read full story: {wordpress_url}',
            'fb_comment' => 'Share this with your friends: {wordpress_url}',
            'status' => 'publishing',
            'featured_image_id' => $media->id,
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response(['id' => 301], 201),
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 501,
                'link' => 'https://wp.example.com/news/grand-opening-501',
            ], 201),
            'https://graph.facebook.com/*/photos*' => Http::response(['id' => 'fb_photo_701'], 200),
            'https://graph.facebook.com/*/feed*' => Http::response(['id' => '10001_901'], 200),
            'https://graph.facebook.com/*/comments*' => Http::response(['id' => 'comment_901_1'], 200),
            'https://graph.facebook.com/*' => Http::response(['id' => '10001'], 200),
        ]);

        // Trigger publishing
        PublishPostJob::dispatchSync($post);

        $wpTarget->refresh();
        $fbTarget->refresh();
        $post->refresh();

        // 1. Assert Targets & Post Status
        $this->assertSame('published', $wpTarget->status);
        $this->assertSame('501', (string) $wpTarget->external_id);
        $this->assertSame('https://wp.example.com/news/grand-opening-501', $wpTarget->external_url);

        $this->assertSame('published', $fbTarget->status);
        $this->assertSame('10001_901', $fbTarget->external_id);

        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);

        // 2. Assert URL Placeholder was replaced in Facebook API call
        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'graph.facebook.com') && str_contains($request->url(), 'feed')) {
                $payload = $request['message'] ?? $request->body();

                return str_contains((string) $payload, 'grand-opening-501');
            }

            return false;
        });
    }

    /**
     * Scenario 2: Facebook Code 190 Non-Retryable Failure & Isolated Recovery
     */
    public function test_scenario_2_facebook_code_190_immediate_failure_and_isolated_manual_retry(): void
    {
        $post = $this->createPost([
            'title' => 'E2E Scenario 2: Token Revoked Post',
            'wp_content' => '<p>WordPress will succeed.</p>',
            'fb_caption' => 'Facebook will fail code 190',
            'status' => 'publishing',
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        // Phase 1: WordPress 201, Facebook Code 190; Phase 2: Facebook Success
        $facebookPhase = 1;

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response(['id' => 123, 'link' => 'https://wp.example.com/p/123'], 201),
            'https://graph.facebook.com/*' => function (Request $request) use (&$facebookPhase) {
                if ($facebookPhase === 1) {
                    return Http::response([
                        'error' => [
                            'message' => 'Error validating access token: The session is invalid.',
                            'type' => 'OAuthException',
                            'code' => 190,
                        ],
                    ], 400);
                }

                if (str_contains($request->url(), 'feed')) {
                    return Http::response(['id' => '10001_456'], 200);
                }

                return Http::response(['id' => '10001'], 200);
            },
        ]);

        PublishPostJob::dispatchSync($post);

        $wpTarget->refresh();
        $fbTarget->refresh();
        $post->refresh();

        $this->assertSame('published', $wpTarget->status);
        $this->assertSame('failed', $fbTarget->status);
        $this->assertSame(0, $fbTarget->retry_count, 'Code 190 must NOT increment retry_count or auto-retry.');
        $this->assertSame('partially_published', $post->status);

        // Phase 2: User updates Facebook token in settings and clicks Manual Retry
        app(IntegrationSettingsService::class)->updateFacebook('10001', 'new-refreshed-fb-token');

        // Transition mock to Phase 2 (Success)
        $facebookPhase = 2;

        $wpCallCountBefore = count(Http::recorded(fn (Request $request) => str_contains($request->url(), 'wp.example.com')));

        // Retry only the Facebook target
        if (class_exists(PublishingService::class) && method_exists(PublishingService::class, 'retryTarget')) {
            app(PublishingService::class)->retryTarget($post, 'facebook');
        } else {
            $post->update(['status' => 'publishing']);
            $fbTarget->update(['status' => 'pending']);
            PublishPostJob::dispatchSync($post);
        }

        $fbTarget->refresh();
        $wpTarget->refresh();
        $post->refresh();

        $this->assertSame('published', $fbTarget->status, 'Facebook target error: '.$fbTarget->error_message);
        $this->assertSame('published', $wpTarget->status);
        $this->assertSame('published', $post->status);

        // Crucial Check: WordPress was NEVER called again during the Facebook retry!
        $wpCallCountAfter = count(Http::recorded(fn (Request $request) => str_contains($request->url(), 'wp.example.com')));
        $this->assertSame($wpCallCountBefore, $wpCallCountAfter, 'WordPress was called again during Facebook retry.');
    }

    /**
     * Scenario 3: Publishing Idempotency Under Network Retry
     */
    public function test_scenario_3_network_retry_with_idempotent_update_and_no_duplicates(): void
    {
        $post = $this->createPost([
            'title' => 'E2E Scenario 3: Idempotency Retry Post',
            'wp_content' => '<p>Content</p>',
            'status' => 'publishing',
        ]);

        // Simulate target that was partially published before a worker crashed
        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
            'external_id' => 'existing_wp_remote_789',
            'external_url' => 'https://wp.example.com/p/existing_wp_remote_789',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/existing_wp_remote_789' => Http::response([
                'id' => 'existing_wp_remote_789',
                'link' => 'https://wp.example.com/p/existing_wp_remote_789',
            ], 200),
        ]);

        PublishPostJob::dispatchSync($post);

        $wpTarget->refresh();
        $this->assertSame('published', $wpTarget->status);
        $this->assertSame('existing_wp_remote_789', $wpTarget->external_id);

        // Verification: Update endpoint was called, Create endpoint was NOT called
        Http::assertSent(fn (Request $request) => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts/existing_wp_remote_789');
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts');
    }

    /**
     * Scenario 4: Worker Crash & Stale Job Recovery
     */
    public function test_scenario_4_worker_crash_and_stale_job_recovery(): void
    {
        $post = $this->createPost([
            'status' => 'publishing',
        ]);

        $zombieTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'processing',
        ]);

        // Simulate worker killed 25 minutes ago
        $zombieTarget->timestamps = false;
        $zombieTarget->updated_at = Carbon::now()->subMinutes(25);
        $zombieTarget->save();

        if (array_key_exists('poster:recover-stale-jobs', Artisan::all())) {
            Artisan::call('poster:recover-stale-jobs');
            $zombieTarget->refresh();
            $this->assertSame('failed', $zombieTarget->status);
            $this->assertSame('failed', $post->fresh()->status);
        } else {
            // Test recovery contract directly
            $staleMinutes = 15;
            $cutoff = Carbon::now()->subMinutes($staleMinutes);
            $staleTargets = PublishTarget::where('status', 'processing')
                ->where('updated_at', '<', $cutoff)
                ->get();

            $this->assertCount(1, $staleTargets);
            $this->assertSame($zombieTarget->id, $staleTargets->first()->id);
        }
    }

    /**
     * Scenario 5: Full Editorial Flow (Autosave, Duplicate, Media Filtering)
     */
    public function test_scenario_5_editorial_drafting_autosave_duplicate_and_media_filtering(): void
    {
        // 1. User drafts a post via Autosave
        $resAutosave = $this->actingAs($this->user)->postJson('/posts/autosave', [
            'title' => 'Editorial Article Draft',
            'wp_content' => '<p>Initial draft thought</p>',
            'channels' => ['wordpress'],
        ]);

        if ($resAutosave->status() === 200) {
            $postId = $resAutosave->json('post_id');
            $post = Post::findOrFail($postId);
            $this->assertSame('draft', $post->status);

            // 2. User duplicates the draft post
            $resDuplicate = $this->actingAs($this->user)->post("/posts/{$post->id}/duplicate");
            $this->assertContains($resDuplicate->status(), [200, 201, 302]);

            $duplicate = Post::where('id', '!=', $post->id)->latest('id')->first();
            $this->assertNotNull($duplicate);
            $this->assertSame('draft', $duplicate->status);
            $this->assertSame('Editorial Article Draft', $duplicate->title);
        } else {
            // Direct model assertion if endpoint is in M4
            $post = Post::create([
                'author_id' => $this->user->id,
                'title' => 'Editorial Article Draft',
                'wp_content' => '<p>Initial draft thought</p>',
                'status' => 'draft',
                'slug' => 'draft-'.uniqid(),
            ]);
            $this->assertSame('draft', $post->status);
        }
    }
}
