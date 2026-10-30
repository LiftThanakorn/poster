<?php

namespace Tests\Feature;

use App\Jobs\DeleteReplacedFacebookPostJob;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\IntegrationSettingsService;
use App\Services\Publishers\FacebookPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FacebookRepostFlowTest extends TestCase
{
    use RefreshDatabase;

    private function publishedPost(User $user): Post
    {
        $post = Post::query()->create([
            'title' => 'Original announcement',
            'slug' => 'original-announcement',
            'fb_caption' => 'Original Facebook text {wordpress_url}',
            'wp_content' => 'Original WordPress story',
            'author_id' => $user->id,
            'status' => 'published',
        ]);
        $post->targets()->create([
            'channel' => 'facebook',
            'status' => 'published',
            'external_id' => 'page_123',
            'external_url' => 'https://facebook.com/page/posts/123',
        ]);
        $post->targets()->create([
            'channel' => 'wordpress',
            'status' => 'published',
            'external_id' => '99',
            'external_url' => 'https://example.org/news/99',
        ]);

        return $post;
    }

    public function test_replacement_draft_preserves_original_and_only_targets_facebook(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        $user = User::factory()->create();
        $original = $this->publishedPost($user);

        $response = $this->actingAs($user)->postJson(route('posts.facebook-replacement', $original), [
            'title' => 'Corrected announcement',
            'fb_caption' => 'Updated Facebook caption {wordpress_url}',
            'channels' => ['facebook', 'wordpress'],
            'file' => UploadedFile::fake()->image('corrected.png'),
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $newPost = Post::query()->findOrFail($response->json('post_id'));
        $this->assertSame('draft', $newPost->status);
        $this->assertSame($original->id, $newPost->replacement_of_post_id);
        $this->assertSame('Corrected announcement', $newPost->title);
        $this->assertSame(['facebook'], $newPost->targets()->pluck('channel')->all());
        $this->assertSame('Original announcement', $original->fresh()->title);
        $this->assertSame('published', $original->targets()->where('channel', 'facebook')->first()->status);
        $this->assertNotNull($newPost->featured_image_id);
        $this->assertSame($newPost->id, PostMedia::query()->findOrFail($newPost->featured_image_id)->post_id);
        Queue::assertNotPushed(PublishPostJob::class);
        Queue::assertNotPushed(DeleteReplacedFacebookPostJob::class);
    }

    public function test_replacement_rejects_unpublished_source_and_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $original = $this->publishedPost($user);

        $this->actingAs($otherUser)->postJson(route('posts.facebook-replacement', $original), [
            'title' => 'New title',
        ])->assertForbidden();

        $original->targets()->where('channel', 'facebook')->update(['status' => 'failed']);
        $this->actingAs($user)->postJson(route('posts.facebook-replacement', $original), [
            'title' => 'New title',
        ])->assertNotFound();

        $this->assertSame(1, Post::query()->count());
    }

    public function test_original_cannot_publish_a_new_photo_as_an_update(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = User::factory()->create();
        $original = $this->publishedPost($user);

        $this->actingAs($user)->putJson(route('posts.update', $original), [
            'title' => 'A replacement image',
            'channels' => ['facebook', 'wordpress'],
            'publish_action' => 'publish',
            'featured_image_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('featured_image_id');

        $this->assertSame('Original announcement', $original->fresh()->title);
        Queue::assertNotPushed(PublishPostJob::class);
    }

    public function test_published_original_cannot_save_changed_facebook_image_as_local_draft(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $original = $this->publishedPost($user);

        $this->actingAs($user)->post(route('posts.update', $original), [
            '_method' => 'put',
            'title' => 'Changed photo',
            'channels' => ['facebook', 'wordpress'],
            'publish_action' => 'draft',
            'file' => UploadedFile::fake()->image('changed.jpg'),
        ])->assertSessionHasErrors('featured_image_id');

        $this->assertSame('Original announcement', $original->fresh()->title);
        $this->assertSame(0, PostMedia::query()->count());
    }

    public function test_facebook_replacement_uses_original_wordpress_link_without_reposting_wordpress(): void
    {
        $user = User::factory()->create();
        $original = $this->publishedPost($user);
        $replacement = Post::query()->create([
            'title' => 'Corrected announcement',
            'slug' => 'corrected-announcement',
            'replacement_of_post_id' => $original->id,
            'fb_caption' => 'Read the details: {wordpress_url}',
            'author_id' => $user->id,
            'status' => 'draft',
        ]);
        $target = $replacement->targets()->create(['channel' => 'facebook', 'status' => 'pending']);

        app(IntegrationSettingsService::class)->saveCredentials(
            'facebook',
            ['access_token' => 'fake-fb-token'],
            ['page_id' => 'test-page', 'version' => 'v26.0'],
        );

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => 'test-page'], 200),
            'https://graph.facebook.com/v26.0/test-page/feed*' => Http::response(['id' => 'test-page_456'], 200),
        ]);

        $response = app(FacebookPublisher::class)->publish($replacement, $target);

        $this->assertTrue($response['success']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/test-page/feed')
            && str_contains((string) $request['message'], 'https://example.org/news/99'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'example.org'));
    }

    public function test_replacement_is_not_allowed_to_publish_to_wordpress(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $original = $this->publishedPost($user);
        $replacement = Post::query()->create([
            'title' => 'Replacement',
            'slug' => 'replacement',
            'replacement_of_post_id' => $original->id,
            'author_id' => $user->id,
            'status' => 'draft',
        ]);
        $replacement->targets()->create(['channel' => 'facebook', 'status' => 'pending']);

        $this->actingAs($user)->putJson(route('posts.update', $replacement), [
            'title' => 'Replacement',
            'channels' => ['facebook', 'wordpress'],
            'publish_action' => 'publish',
        ])->assertUnprocessable()->assertJsonValidationErrors('channels');

        Queue::assertNotPushed(PublishPostJob::class);
        $this->assertSame(['facebook'], $replacement->targets()->pluck('channel')->all());
    }

    public function test_original_can_only_be_queued_for_deletion_after_replacement_publishes(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $original = $this->publishedPost($user);
        $replacement = Post::query()->create([
            'title' => 'Replacement',
            'slug' => 'replacement',
            'replacement_of_post_id' => $original->id,
            'author_id' => $user->id,
            'status' => 'draft',
        ]);
        $target = $replacement->targets()->create(['channel' => 'facebook', 'status' => 'pending']);

        $this->actingAs($user)->postJson(route('posts.facebook-replacement.delete-original', $replacement))
            ->assertNotFound();
        Queue::assertNotPushed(DeleteReplacedFacebookPostJob::class);

        $target->update(['status' => 'published', 'external_id' => 'page_456', 'external_url' => 'https://facebook.com/page/posts/456']);
        $this->actingAs($user)->postJson(route('posts.facebook-replacement.delete-original', $replacement))
            ->assertOk()->assertJsonPath('success', true);

        $originalFacebook = $original->targets()->where('channel', 'facebook')->first();
        $this->assertSame('deleting', $originalFacebook->status);
        $this->assertSame('published', $original->targets()->where('channel', 'wordpress')->first()->status);
        $this->assertSame('published', $target->fresh()->status);
        Queue::assertPushed(DeleteReplacedFacebookPostJob::class, 1);
        Queue::assertPushed(DeleteReplacedFacebookPostJob::class, fn ($job) => $job->targetId === $originalFacebook->id && $job->externalId === 'page_123');

        $this->actingAs($user)->postJson(route('posts.facebook-replacement.delete-original', $replacement))
            ->assertUnprocessable();
    }

    public function test_deletion_job_only_marks_original_detached_after_remote_success(): void
    {
        $user = User::factory()->create();
        $original = $this->publishedPost($user);
        $originalFacebook = $original->targets()->where('channel', 'facebook')->first();
        $originalFacebook->update(['status' => 'deleting']);

        $publisher = $this->mock(FacebookPublisher::class);
        $publisher->shouldReceive('delete')->once()->andReturn(true);

        $job = new DeleteReplacedFacebookPostJob($originalFacebook->id, 'page_123');
        $job->handle($publisher);

        $this->assertSame('detached', $originalFacebook->fresh()->status);
        $this->assertSame('published', $original->targets()->where('channel', 'wordpress')->first()->status);
    }
}
