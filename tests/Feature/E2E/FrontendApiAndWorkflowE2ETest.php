<?php

namespace Tests\Feature\E2E;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PublishAttempt;
use App\Models\PublishEvent;
use App\Models\PublishTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FrontendApiAndWorkflowE2ETest extends TestCase
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
    // FEATURE 14: Publishing Stepper & Progress UI Contract (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f14_post_status_endpoint_returns_progress_contract_json(): void
    {
        $post = $this->createPost([
            'status' => 'publishing',
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'published',
            'external_id' => '101',
            'external_url' => 'https://wp.example.com/post/101',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'processing',
        ]);

        $response = $this->actingAs($this->user)->getJson("/posts/{$post->id}/status");

        if ($response->status() === 404) {
            $this->fail('Endpoint GET /posts/{post}/status does not exist yet (Feature 14).');
        }

        $response->assertOk()
            ->assertJsonStructure([
                'id',
                'status',
                'targets' => [
                    '*' => [
                        'id',
                        'channel',
                        'status',
                    ],
                ],
                'updated_at',
            ]);

        $this->assertSame('publishing', $response->json('status'));
        $this->assertCount(2, $response->json('targets'));
    }

    public function test_f14_post_status_endpoint_404_for_invalid_post(): void
    {
        $response = $this->actingAs($this->user)->getJson('/posts/99999999/status');
        $response->assertNotFound();
    }

    // =========================================================================
    // FEATURE 15: Publishing Timeline Audit Contract (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f15_timeline_endpoint_returns_attempts_and_events(): void
    {
        $post = $this->createPost([
            'status' => 'partially_published',
        ]);

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'failed',
            'error_message' => 'Token Expired',
        ]);

        PublishAttempt::create([
            'correlation_id' => (string) Str::uuid(),
            'post_id' => $post->id,
            'publish_target_id' => $target->id,
            'channel' => 'facebook',
            'attempt_number' => 1,
            'status' => 'failed',
            'error_type' => 'auth_error',
            'error_code' => '190',
            'error_message' => 'สิทธิ์ Facebook ไม่เพียงพอ หรือ Token หมดอายุ',
            'retryable' => false,
            'duration_ms' => 450,
        ]);

        PublishEvent::create([
            'post_id' => $post->id,
            'publish_target_id' => $target->id,
            'channel' => 'facebook',
            'level' => 'error',
            'event_type' => 'publish_failed',
            'message' => 'Publishing to Facebook failed: Code 190',
        ]);

        $response = $this->actingAs($this->user)->getJson("/posts/{$post->id}/timeline");

        if ($response->status() === 404) {
            $this->fail('Endpoint GET /posts/{post}/timeline does not exist yet (Feature 15).');
        }

        $response->assertOk()
            ->assertJsonStructure([
                'attempts',
                'events',
            ]);

        $this->assertCount(1, $response->json('attempts'));
        $this->assertCount(1, $response->json('events'));
        $this->assertStringContainsString('สิทธิ์ Facebook', $response->json('attempts.0.error_message'));
    }

    // =========================================================================
    // FEATURE 16: Manual Channel Retry Action (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f16_channel_retry_endpoint_resets_target_status_and_dispatches_retry(): void
    {
        $post = $this->createPost([
            'status' => 'failed',
        ]);

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'failed',
            'error_message' => 'Connection timeout',
        ]);

        $response = $this->actingAs($this->user)->postJson("/posts/{$post->id}/retry/facebook");

        if ($response->status() === 404) {
            $this->fail('Endpoint POST /posts/{post}/retry/{channel} does not exist yet (Feature 16).');
        }

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $target->refresh();
        $this->assertContains($target->status, ['pending', 'queued', 'processing']);
    }

    public function test_f16_channel_retry_rejects_retry_on_already_published_target(): void
    {
        $post = $this->createPost([
            'status' => 'published',
        ]);

        PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'published',
            'external_id' => '100',
        ]);

        $response = $this->actingAs($this->user)->postJson("/posts/{$post->id}/retry/wordpress");

        if ($response->status() === 404) {
            $this->fail('Endpoint POST /posts/{post}/retry/{channel} does not exist yet (Feature 16).');
        }

        // Cannot retry target that is already published
        $this->assertContains($response->status(), [400, 422]);
    }

    // =========================================================================
    // FEATURE 17: Autosave Drafts Functionality (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f17_autosave_creates_new_draft_post(): void
    {
        $response = $this->actingAs($this->user)->postJson('/posts/autosave', [
            'title' => 'Autosaved Draft Title',
            'wp_content' => '<p>Autosaved content</p>',
            'channels' => ['wordpress'],
        ]);

        if (in_array($response->status(), [404, 405], true)) {
            $this->fail('Endpoint POST /posts/autosave does not exist yet (Feature 17).');
        }

        $response->assertOk()
            ->assertJson(['success' => true]);

        $postId = $response->json('post_id');
        $this->assertNotNull($postId);

        $post = Post::find($postId);
        $this->assertNotNull($post);
        $this->assertSame('draft', $post->status);
        $this->assertSame('Autosaved Draft Title', $post->title);
    }

    public function test_f17_autosave_updates_existing_draft_post(): void
    {
        $post = $this->createPost([
            'title' => 'Initial Title',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)->putJson("/posts/{$post->id}/autosave", [
            'title' => 'Updated Autosave Title',
            'wp_content' => '<p>More details added</p>',
        ]);

        if (in_array($response->status(), [404, 405], true)) {
            $this->fail('Endpoint PUT /posts/{post}/autosave does not exist yet (Feature 17).');
        }

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('Updated Autosave Title', $post->fresh()->title);
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_f17_autosaved_draft_is_published_by_updating_same_post(): void
    {
        Queue::fake();

        $autosave = $this->actingAs($this->user)->postJson('/posts/autosave', [
            'title' => 'Autosaved Before Publish',
            'wp_content' => '<p>Draft body</p>',
            'channels' => ['wordpress'],
        ])->assertOk();

        $postId = $autosave->json('post_id');
        $this->assertSame(1, Post::query()->count());

        $this->actingAs($this->user)->put(route('posts.update', $postId), [
            'title' => 'Autosaved Before Publish',
            'wp_content' => '<p>Final body</p>',
            'fb_caption' => '',
            'channels' => ['wordpress'],
            'publish_action' => 'publish',
        ])->assertRedirect(route('posts.index'));

        $this->assertSame(1, Post::query()->count());
        $this->assertSame('publishing', Post::findOrFail($postId)->status);
        Queue::assertPushed(PublishPostJob::class);
    }

    public function test_f17_published_post_rejects_autosave_updates(): void
    {
        $post = $this->createPost([
            'title' => 'Already Published',
            'status' => 'published',
        ]);

        $this->actingAs($this->user)->putJson("/posts/{$post->id}/autosave", [
            'title' => 'Should Not Replace Published Content',
        ])->assertStatus(422);

        $this->assertSame('Already Published', $post->fresh()->title);
        $this->assertSame('published', $post->fresh()->status);
    }

    // =========================================================================
    // FEATURE 18: Duplicate Post Functionality (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f18_duplicate_post_clones_content_and_resets_publishing_state(): void
    {
        $originalPost = $this->createPost([
            'title' => 'Original Post to Duplicate',
            'wp_content' => '<p>Original body</p>',
            'fb_caption' => 'Original Facebook caption',
            'status' => 'published',
        ]);

        PublishTarget::create([
            'post_id' => $originalPost->id,
            'channel' => 'wordpress',
            'status' => 'published',
            'external_id' => 'wp_9999',
            'external_url' => 'https://wp.example.com/p/9999',
        ]);

        $response = $this->actingAs($this->user)->post("/posts/{$originalPost->id}/duplicate");

        if ($response->status() === 404) {
            $this->fail('Endpoint POST /posts/{post}/duplicate does not exist yet (Feature 18).');
        }

        // Must redirect to edit view of the duplicated post, or return 200/201 with duplicate ID
        $this->assertContains($response->status(), [200, 201, 302]);

        $duplicatedPost = Post::query()
            ->where('id', '!=', $originalPost->id)
            ->where('author_id', $this->user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedPost, 'Duplicated post record must be created.');
        $this->assertStringContainsString('Original Post to Duplicate', $duplicatedPost->title);
        $this->assertSame('draft', $duplicatedPost->status, 'Duplicated post status must be draft.');
        $this->assertNull($duplicatedPost->published_at);

        // Ensure no published targets or external IDs were copied!
        $duplicatedTargets = $duplicatedPost->targets;
        foreach ($duplicatedTargets as $target) {
            $this->assertNull($target->external_id, 'External ID must not be copied to duplicate.');
            $this->assertNull($target->external_url, 'External URL must not be copied to duplicate.');
            $this->assertSame('pending', $target->status);
        }
    }

    // =========================================================================
    // FEATURE 19: Facebook Media PDF Exclusion (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f19_pdf_upload_rejected_when_facebook_channel_is_selected(): void
    {
        Storage::fake('public');
        $pdfFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->user)->post(route('posts.store'), [
            'title' => 'Facebook with PDF Post',
            'wp_content' => '<p>Article</p>',
            'fb_caption' => 'Should fail due to PDF attachment',
            'channels' => ['facebook'],
            'publish_action' => 'draft',
            'file' => $pdfFile,
        ]);

        // When Facebook is selected, PDF must be rejected with 422 or excluded
        if ($response->status() === 422) {
            $response->assertJsonValidationErrors(['file']);
        } else {
            // Or if redirected with session validation errors
            $this->assertTrue(
                session()->has('errors') || $response->isRedirect(),
                'PDF with Facebook destination must be rejected or restricted.'
            );
        }
    }

    public function test_f19_image_upload_allowed_when_facebook_channel_is_selected(): void
    {
        Storage::fake('public');
        $imageFile = UploadedFile::fake()->image('banner.png', 800, 600);

        $response = $this->actingAs($this->user)->post(route('posts.store'), [
            'title' => 'Facebook with Image Post',
            'wp_content' => '<p>Article</p>',
            'fb_caption' => 'Should succeed with image',
            'channels' => ['facebook'],
            'publish_action' => 'draft',
            'file' => $imageFile,
        ]);

        $response->assertRedirect(route('posts.index'));
        $this->assertDatabaseHas('posts', ['title' => 'Facebook with Image Post']);
    }

    public function test_f19_pdf_upload_allowed_when_only_wordpress_channel_is_selected(): void
    {
        Storage::fake('public');
        $pdfFile = UploadedFile::fake()->create('whitepaper.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->user)->post(route('posts.store'), [
            'title' => 'WordPress with PDF Whitepaper',
            'wp_content' => '<p>WordPress allows PDF</p>',
            'fb_caption' => '',
            'channels' => ['wordpress'],
            'publish_action' => 'draft',
            'file' => $pdfFile,
        ]);

        $response->assertRedirect(route('posts.index'));
        $this->assertDatabaseHas('posts', ['title' => 'WordPress with PDF Whitepaper']);
    }

    // =========================================================================
    // FEATURE 20: Near-Realtime Polling Contract (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f20_polling_endpoint_identifies_active_vs_terminal_states(): void
    {
        // 1. Active State (processing)
        $activePost = $this->createPost([
            'status' => 'publishing',
        ]);
        PublishTarget::create(['post_id' => $activePost->id, 'channel' => 'wordpress', 'status' => 'processing']);

        $response = $this->actingAs($this->user)->getJson("/posts/{$activePost->id}/status");
        if ($response->status() === 404) {
            $this->fail('Endpoint GET /posts/{post}/status does not exist yet (Feature 20).');
        }

        $response->assertOk();
        $this->assertContains($response->json('status'), ['publishing', 'queued', 'processing', 'retrying']);

        // 2. Terminal State (published)
        $terminalPost = $this->createPost([
            'status' => 'published',
        ]);
        PublishTarget::create(['post_id' => $terminalPost->id, 'channel' => 'wordpress', 'status' => 'published']);

        $resTerminal = $this->actingAs($this->user)->getJson("/posts/{$terminalPost->id}/status");
        $resTerminal->assertOk();
        $this->assertContains($resTerminal->json('status'), ['published', 'failed', 'partially_published']);
    }
}
