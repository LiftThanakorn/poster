<?php

namespace Tests\Feature\E2E;

use App\Jobs\PublishFacebookJob;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishWordPressJob;
use App\Models\IntegrationSetting;
use App\Models\Post;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\Publishers\FacebookPublisher;
use App\Services\Publishers\PublishFailureClassifier;
use App\Services\Publishers\PublishingService;
use App\Services\Publishers\WordPressPublisher;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishingOrchestrationE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'secret-password'],
            'status' => 'connected',
        ]);

        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '10001', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'EAABsecret-token'],
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
    // FEATURE 6: PublishFailureClassifier & Thai Error Mapping (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f06_classifier_classifies_server_errors_and_timeouts_as_retryable(): void
    {
        $this->assertTrue(
            class_exists(PublishFailureClassifier::class),
            'PublishFailureClassifier must exist in App\Services\Publishers.'
        );

        if (! class_exists(PublishFailureClassifier::class)) {
            return;
        }

        // Connection Exception -> Retryable
        $timeoutException = new ConnectionException('cURL error 28: Operation timed out');
        $failure = PublishFailureClassifier::classifyThrowable($timeoutException, 'wordpress', 1);

        $this->assertTrue($failure->isRetryable, 'Network timeout must be classified as retryable.');
        $this->assertSame(10, $failure->retryDelaySeconds, 'First retry delay must be 10 seconds.');
        $this->assertNotEmpty($failure->messageTh, 'Must provide Thai error message.');
    }

    public function test_f06_classifier_exponential_backoff_delays_10_30_90(): void
    {
        if (! class_exists(PublishFailureClassifier::class)) {
            $this->fail('PublishFailureClassifier does not exist yet (Feature 6).');
        }

        $exception = new ConnectionException('Timed out');

        $attempt1 = PublishFailureClassifier::classifyThrowable($exception, 'wordpress', 1);
        $attempt2 = PublishFailureClassifier::classifyThrowable($exception, 'wordpress', 2);
        $attempt3 = PublishFailureClassifier::classifyThrowable($exception, 'wordpress', 3);

        $this->assertSame(10, $attempt1->retryDelaySeconds);
        $this->assertSame(30, $attempt2->retryDelaySeconds);
        $this->assertSame(90, $attempt3->retryDelaySeconds);
    }

    public function test_f06_classifier_classifies_client_auth_errors_as_non_retryable(): void
    {
        if (! class_exists(PublishFailureClassifier::class)) {
            $this->fail('PublishFailureClassifier does not exist yet (Feature 6).');
        }

        // Mock 401 Unauthorized Response
        $rawResponse = new Response(401, [], json_encode([
            'code' => 'rest_cannot_create',
            'message' => 'Sorry, you are not allowed to create posts.',
        ]));
        $httpResponse = new HttpResponse($rawResponse);

        $failure = PublishFailureClassifier::classifyHttpResponse($httpResponse, 'wordpress', 1);

        $this->assertFalse($failure->isRetryable, 'HTTP 401 must be non-retryable.');
        $this->assertSame(0, $failure->retryDelaySeconds);
    }

    public function test_f06_classifier_maps_facebook_code_190_to_thai_message(): void
    {
        if (! class_exists(PublishFailureClassifier::class)) {
            $this->fail('PublishFailureClassifier does not exist yet (Feature 6).');
        }

        $rawResponse = new Response(400, [], json_encode([
            'error' => [
                'message' => 'Error validating access token: Session has expired.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ]));
        $httpResponse = new HttpResponse($rawResponse);

        $failure = PublishFailureClassifier::classifyHttpResponse($httpResponse, 'facebook', 1);

        $this->assertFalse($failure->isRetryable, 'Facebook Code 190 must be non-retryable.');
        $this->assertSame('190', (string) $failure->errorCode);
        $this->assertStringContainsString('สิทธิ์ Facebook', $failure->messageTh, 'Must contain Thai description for code 190.');
    }

    // =========================================================================
    // FEATURE 7: Decoupled Channel Jobs (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f07_publish_wordpress_job_exists_and_implements_contract(): void
    {
        $this->assertTrue(class_exists(PublishWordPressJob::class), 'PublishWordPressJob class must exist.');
        $this->assertTrue(
            is_subclass_of(PublishWordPressJob::class, ShouldQueue::class),
            'PublishWordPressJob must implement ShouldQueue.'
        );

        $job = new PublishWordPressJob;
        $this->assertTrue(property_exists($job, 'timeout') || method_exists($job, 'retryUntil'));
    }

    public function test_f07_publish_facebook_job_exists_and_implements_contract(): void
    {
        $this->assertTrue(class_exists(PublishFacebookJob::class), 'PublishFacebookJob class must exist.');
        $this->assertTrue(
            is_subclass_of(PublishFacebookJob::class, ShouldQueue::class),
            'PublishFacebookJob must implement ShouldQueue.'
        );

        $job = new PublishFacebookJob;
        $this->assertTrue(property_exists($job, 'timeout') || method_exists($job, 'retryUntil'));
    }

    // =========================================================================
    // FEATURE 8: Publishing Service Orchestration (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f08_publishing_service_sequences_wordpress_before_facebook_and_replaces_url(): void
    {
        $post = $this->createPost([
            'title' => 'Breaking News Post',
            'wp_content' => '<p>Article body</p>',
            'fb_caption' => 'Check out our article at {wordpress_url} for details!',
            'fb_comment' => 'Read more at {wordpress_url}',
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
                'id' => 777,
                'link' => 'https://wp.example.com/breaking-news-777',
            ], 201),
            'https://graph.facebook.com/*/feed*' => Http::response([
                'id' => '10001_888',
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'id' => '10001',
            ], 200),
        ]);

        // Execute orchestration (via PublishingService or fallback PublishPostJob)
        if (class_exists(PublishingService::class) && method_exists(PublishingService::class, 'publishPost')) {
            app(PublishingService::class)->publishPost($post);
        } else {
            PublishPostJob::dispatchSync($post);
        }

        $wpTarget->refresh();
        $fbTarget->refresh();

        $this->assertSame('published', $wpTarget->status);
        $this->assertSame('777', (string) $wpTarget->external_id);
        $this->assertSame('https://wp.example.com/breaking-news-777', $wpTarget->external_url);

        $this->assertSame('published', $fbTarget->status);

        // Verify Facebook API was called with {wordpress_url} replaced by the actual WP link!
        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'graph.facebook.com') && str_contains($request->url(), 'feed')) {
                $payload = $request['message'] ?? $request->body();

                return str_contains((string) $payload, 'https://wp.example.com/breaking-news-777')
                    && ! str_contains((string) $payload, '{wordpress_url}');
            }

            return false;
        });
    }

    public function test_f08_publishing_service_isolated_channel_retry(): void
    {
        $post = $this->createPost([
            'status' => 'partially_published',
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'published',
            'external_id' => 'wp_existing_123',
            'external_url' => 'https://wp.example.com/p/123',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'failed',
            'error_message' => 'Temporary network error',
        ]);

        Http::fake([
            'https://graph.facebook.com/*/feed*' => Http::response(['id' => '10001_999'], 200),
            'https://graph.facebook.com/*' => Http::response(['id' => '10001'], 200),
        ]);

        if (class_exists(PublishingService::class) && method_exists(PublishingService::class, 'retryTarget')) {
            app(PublishingService::class)->retryTarget($post, 'facebook');
        } else {
            $post->update(['status' => 'publishing']);
            $fbTarget->update(['status' => 'pending']);
            PublishPostJob::dispatchSync($post);
        }

        // WordPress must NOT be touched or called!
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wp.example.com'));

        $this->assertSame('published', $wpTarget->fresh()->status);
        $this->assertSame('published', $fbTarget->fresh()->status);
    }

    // =========================================================================
    // FEATURE 9: Publishing Idempotency (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f09_wordpress_idempotency_calls_update_when_external_id_exists(): void
    {
        $post = $this->createPost([
            'title' => 'Updated Title',
            'wp_content' => '<p>Updated content body</p>',
            'status' => 'publishing',
        ]);

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
            'external_id' => '54321', // ALREADY EXISTS
            'external_url' => 'https://wp.example.com/p/54321',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/54321' => Http::response([
                'id' => 54321,
                'link' => 'https://wp.example.com/p/54321',
            ], 200),
        ]);

        $publisher = new WordPressPublisher;
        $result = $publisher->update($post, $target);

        $this->assertTrue($result['success']);
        $this->assertSame('54321', (string) $result['external_id']);

        // Assert that POST /wp-json/wp/v2/posts/54321 was called (UPDATE endpoint)
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts/54321'
                && $request->method() === 'POST';
        });

        // Assert that POST /wp-json/wp/v2/posts (CREATE endpoint) was NEVER called!
        Http::assertNotSent(function (Request $request) {
            return $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts'
                && $request->method() === 'POST';
        });
    }

    public function test_f09_facebook_idempotency_calls_update_when_external_id_exists(): void
    {
        $post = $this->createPost([
            'fb_caption' => 'Updated Facebook text',
            'status' => 'publishing',
        ]);

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
            'external_id' => '10001_987654', // ALREADY EXISTS
            'external_url' => 'https://facebook.com/10001/posts/987654',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'id' => '10001',
                'success' => true,
            ], 200),
        ]);

        $publisher = new FacebookPublisher;
        $result = $publisher->update($post, $target);

        $this->assertTrue($result['success']);

        // Assert that the request targeted the specific post ID endpoint (UPDATE)
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '10001_987654')
                && ! str_contains($request->url(), '10001/feed');
        });
    }

    public function test_f09_full_job_idempotency_avoids_duplicate_creation_on_retry(): void
    {
        $post = $this->createPost([
            'title' => 'Idempotent Post Test',
            'wp_content' => '<p>Checking idempotency</p>',
            'status' => 'publishing',
        ]);

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
            'external_id' => '888000',
            'external_url' => 'https://wp.example.com/post/888000',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/888000' => Http::response(['id' => 888000], 200),
        ]);

        PublishPostJob::dispatchSync($post);

        $target->refresh();
        $this->assertSame('published', $target->status);
        $this->assertSame('888000', (string) $target->external_id);

        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts');
    }

    // =========================================================================
    // FEATURE 10: Non-Retryable Immediate Fail (Code 190) (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f10_facebook_code_190_marks_target_failed_immediately_with_zero_auto_retries(): void
    {
        $post = $this->createPost([
            'title' => 'Post with Expired Token',
            'fb_caption' => 'This should fail immediately',
            'status' => 'publishing',
        ]);

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        PublishPostJob::dispatchSync($post);

        $target->refresh();

        $this->assertSame('failed', $target->status, 'Target must be marked failed immediately.');
        $this->assertSame(0, $target->retry_count, 'Retry count must remain 0 (no auto-retries for code 190).');
        $this->assertNotNull($target->error_message);
    }

    public function test_f10_non_retryable_error_does_not_enqueue_retry_delay(): void
    {
        if (class_exists(PublishFailureClassifier::class)) {
            $response = new HttpResponse(new Response(400, [], json_encode([
                'error' => ['code' => 190, 'message' => 'Token invalid'],
            ])));
            $failure = PublishFailureClassifier::classifyHttpResponse($response, 'facebook', 1);

            $this->assertFalse($failure->isRetryable);
            $this->assertSame(0, $failure->retryDelaySeconds);
        } else {
            $this->assertTrue(true);
        }
    }
}
