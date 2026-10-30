<?php

namespace Tests\Feature;

use App\Jobs\DeleteExternalPostJob;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function publishedPost(User $user): Post
    {
        $post = Post::query()->create([
            'title' => 'Published news',
            'slug' => 'published-news',
            'author_id' => $user->id,
            'status' => 'published',
        ]);

        $post->targets()->forceCreate(['channel' => 'wordpress', 'status' => 'published', 'external_id' => '101']);
        $post->targets()->forceCreate(['channel' => 'facebook', 'status' => 'published', 'external_id' => '555_777']);

        return $post;
    }

    public function test_deleting_with_external_flag_queues_remote_deletion_without_calling_apis(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $post = $this->publishedPost($user);

        $this->actingAs($user)
            ->delete(route('posts.destroy', $post), ['delete_external' => 1])
            ->assertRedirect();

        $this->assertModelMissing($post);
        Queue::assertPushed(DeleteExternalPostJob::class, 2);
        Queue::assertPushed(DeleteExternalPostJob::class, fn ($job) => $job->channel === 'wordpress' && $job->externalId === '101');
        Queue::assertPushed(DeleteExternalPostJob::class, fn ($job) => $job->channel === 'facebook' && $job->externalId === '555_777');
    }

    public function test_deleting_with_external_query_parameter_queues_remote_deletion(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $post = $this->publishedPost($user);

        $this->actingAs($user)
            ->delete(route('posts.destroy', ['post' => $post, 'delete_external' => 1]))
            ->assertRedirect();

        $this->assertModelMissing($post);
        Queue::assertPushed(DeleteExternalPostJob::class, 2);
        Queue::assertPushed(DeleteExternalPostJob::class, fn ($job) => $job->channel === 'wordpress' && $job->externalId === '101');
    }

    public function test_deleting_without_external_flag_keeps_remote_posts(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $post = $this->publishedPost($user);

        $this->actingAs($user)
            ->delete(route('posts.destroy', $post), ['delete_external' => 0])
            ->assertRedirect();

        $this->assertModelMissing($post);
        Queue::assertNotPushed(DeleteExternalPostJob::class);
    }

    public function test_deleting_target_with_null_external_id_or_failed_status_does_not_queue_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $post = Post::query()->create([
            'title' => 'Failed news',
            'slug' => 'failed-news',
            'author_id' => $user->id,
            'status' => 'failed',
        ]);
        $post->targets()->forceCreate(['channel' => 'wordpress', 'status' => 'failed', 'external_id' => null]);

        $this->actingAs($user)
            ->delete(route('posts.destroy', $post), ['delete_external' => 1])
            ->assertRedirect();

        $this->assertModelMissing($post);
        Queue::assertNotPushed(DeleteExternalPostJob::class);
    }

    public function test_job_deletes_wordpress_post_via_rest_api(): void
    {
        config([
            'services.wordpress.url' => 'https://news.example.com',
            'services.wordpress.username' => 'editor',
            'services.wordpress.password' => 'app-password',
        ]);
        Http::fake(['news.example.com/*' => Http::response(['deleted' => true], 200)]);

        (new DeleteExternalPostJob('wordpress', '101', 1))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://news.example.com/wp-json/wp/v2/posts/101?force=true'
            && $request->hasHeader('X-HTTP-Method-Override', 'DELETE'));
    }

    public function test_job_deletes_wordpress_post_via_post_override_when_delete_verb_is_blocked(): void
    {
        config([
            'services.wordpress.url' => 'https://news.example.com',
            'services.wordpress.username' => 'editor',
            'services.wordpress.password' => 'app-password',
        ]);

        Http::fake([
            'https://news.example.com/wp-json/wp/v2/posts/101?force=true' => Http::response(['message' => 'Method not allowed'], 405),
            'https://news.example.com/wp-json/wp/v2/posts/101?force=true&_method=DELETE' => Http::response(['deleted' => true], 200),
        ]);

        (new DeleteExternalPostJob('wordpress', '101', 1))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://news.example.com/wp-json/wp/v2/posts/101?force=true&_method=DELETE'
            && $request->hasHeader('X-HTTP-Method-Override', 'DELETE'));
    }

    public function test_job_succeeds_idempotently_if_post_already_deleted_remotely(): void
    {
        config([
            'services.wordpress.url' => 'https://news.example.com',
            'services.wordpress.username' => 'editor',
            'services.wordpress.password' => 'app-password',
        ]);

        Http::fake([
            'news.example.com/*' => Http::response(['code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.'], 404),
        ]);

        // Should complete without throwing RuntimeException
        (new DeleteExternalPostJob('wordpress', '101', 1))->handle();

        $this->assertTrue(true);
    }

    public function test_job_throws_so_queue_retries_when_remote_delete_fails(): void
    {
        config([
            'services.wordpress.url' => 'https://news.example.com',
            'services.wordpress.username' => 'editor',
            'services.wordpress.password' => 'app-password',
        ]);
        Http::fake(['news.example.com/*' => Http::response(['code' => 'server_error'], 500)]);

        $this->expectException(\RuntimeException::class);

        (new DeleteExternalPostJob('wordpress', '101', 1))->handle();
    }
}
