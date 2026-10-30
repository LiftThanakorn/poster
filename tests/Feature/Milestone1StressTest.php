<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\IntegrationSettingsService;
use App\Services\Publishers\FacebookPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Milestone1StressTest extends TestCase
{
    use RefreshDatabase;

    protected IntegrationSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IntegrationSettingsService::class);
    }

    // =========================================================================
    // SECTION 1: FACEBOOK HEALTH CHECK ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * Test Facebook health check when token is expired via Graph error code 190.
     */
    public function test_facebook_health_check_handles_expired_token_code_190(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'expired_token_190'], ['page_id' => 'fb_page_100']);

        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 463,
                ],
            ], 400),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('190', $result['error']);

        $setting = IntegrationSetting::where('channel', 'facebook')->first();
        $this->assertSame('error', $setting->status);
        $this->assertStringContainsString('190', $setting->last_error);
    }

    /**
     * Test Facebook health check when /debug_token reports past expiration timestamp.
     */
    public function test_facebook_health_check_handles_expired_token_debug_token_timestamp_past(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'past_token_abc'], ['page_id' => 'fb_page_100']);

        $pastTimestamp = Carbon::now()->subDays(2)->timestamp;

        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::response([
                'id' => 'fb_page_100',
                'name' => 'Valid Page',
                'tasks' => ['CREATE_CONTENT'],
            ], 200),
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                    ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/debug_token*' => Http::response([
                'data' => [
                    'app_id' => '123456',
                    'type' => 'PAGE',
                    'application' => 'Poster App',
                    'expires_at' => $pastTimestamp,
                    'is_valid' => false,
                ],
            ], 200),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('หมดอายุ', $result['error']);
    }

    /**
     * Test Facebook health check when pages_manage_posts is revoked / missing.
     */
    public function test_facebook_health_check_handles_permission_revocation_missing_pages_manage_posts(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'token_without_manage_posts'], ['page_id' => 'fb_page_100']);

        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::response([
                'id' => 'fb_page_100',
                'name' => 'My Page',
                'tasks' => ['CREATE_CONTENT'],
            ], 200),
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                    // pages_manage_posts missing!
                ],
            ], 200),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('pages_manage_posts', $result['error']);
    }

    /**
     * Test Facebook health check when /me/permissions returns 200/283 permission error.
     */
    public function test_facebook_health_check_handles_permission_code_200_and_283(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'token_code_200'], ['page_id' => 'fb_page_100']);

        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::response([
                'id' => 'fb_page_100',
                'name' => 'My Page',
                'tasks' => ['CREATE_CONTENT'],
            ], 200),
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'error' => [
                    'message' => 'Permissions error',
                    'type' => 'OAuthException',
                    'code' => 200,
                ],
            ], 403),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('200', $result['error']);
    }

    /**
     * Test Facebook health check under API rate limiting (code 4, 17, 32, HTTP 429).
     */
    public function test_facebook_health_check_handles_rate_limiting_codes_and_429(): void
    {
        // 1. Rate limit Code 4
        $this->service->saveCredentials('facebook', ['access_token' => 'rate_limited_token'], ['page_id' => 'fb_page_100']);

        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::response([
                'error' => [
                    'message' => 'Application request limit reached',
                    'type' => 'OAuthException',
                    'code' => 4,
                ],
            ], 403),
        ]);

        $result = $this->service->checkFacebookHealth();
        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Application request limit reached', $result['error']);

        // 2. HTTP 429 Too Many Requests
        $this->service->forgetHealthCache('facebook');
        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::response([
                'error' => [
                    'message' => 'Too many requests',
                    'type' => 'OAuthException',
                    'code' => 17,
                ],
            ], 429),
        ]);

        $result2 = $this->service->checkFacebookHealth();
        $this->assertSame('unhealthy', $result2['status']);
        $this->assertNotNull($result2['error']);
        $this->assertStringContainsString('Too many requests', $result2['error']);
    }

    /**
     * Test Facebook health check handling connection timeout without uncaught exceptions.
     */
    public function test_facebook_health_check_handles_connection_timeout_gracefully(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'valid_token'], ['page_id' => 'fb_page_100']);

        Http::fake([
            'https://graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received'),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Facebook Health Check Failed', $result['error']);
        $this->assertStringContainsString('timed out', $result['error']);
    }

    /**
     * Test Facebook health check detects Page ID mismatch.
     */
    public function test_facebook_health_check_page_id_mismatch(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'valid_token'], ['page_id' => 'expected_page_999']);

        Http::fake([
            'https://graph.facebook.com/v26.0/expected_page_999*' => Http::response([
                'id' => 'different_page_111',
                'name' => 'Imposter Page',
            ], 200),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Facebook Page ID ไม่ตรงกับที่กำหนดไว้', $result['error']);
    }

    // =========================================================================
    // SECTION 2: WORDPRESS HEALTH CHECK ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * Test WordPress health check on HTTP 401 Unauthenticated.
     */
    public function test_wordpress_health_check_handles_401_unauthenticated(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'wrong_pass'], [
            'url' => 'https://wp.example.com',
            'username' => 'editor_user',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'code' => 'incorrect_password',
                'message' => 'The password you entered for the username editor_user is incorrect.',
                'data' => ['status' => 401],
            ], 401),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('401', $result['error']);

        $setting = IntegrationSetting::where('channel', 'wordpress')->first();
        $this->assertSame('error', $setting->status);
    }

    /**
     * Test WordPress health check on HTTP 403 Forbidden.
     */
    public function test_wordpress_health_check_handles_403_unauthorized(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'some_pass'], [
            'url' => 'https://wp.example.com',
            'username' => 'editor_user',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'code' => 'rest_forbidden',
                'message' => 'Sorry, you are not allowed to do that.',
                'data' => ['status' => 403],
            ], 403),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('403', $result['error']);
    }

    /**
     * Test WordPress health check when user is subscriber without publish_posts capability.
     */
    public function test_wordpress_health_check_handles_subscriber_without_publish_posts(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'subscriber_pass'], [
            'url' => 'https://wp.example.com',
            'username' => 'sub_user',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 99,
                'name' => 'Subscriber John',
                'roles' => ['subscriber'],
                'capabilities' => [
                    'read' => true,
                    'publish_posts' => false,
                ],
            ], 200),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('publish_posts', $result['error']);
    }

    /**
     * Test WordPress health check handles user without capabilities array fallback to roles.
     */
    public function test_wordpress_health_check_handles_roles_without_publish_capability(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'viewer_pass'], [
            'url' => 'https://wp.example.com',
            'username' => 'viewer_user',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 101,
                'name' => 'Custom Viewer',
                'roles' => ['viewer', 'subscriber', 'customer'],
                // capabilities omitted
            ], 200),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
    }

    /**
     * Test WordPress health check handles network timeout.
     */
    public function test_wordpress_health_check_handles_network_timeout(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'secret_pass'], [
            'url' => 'https://wp.example.com',
            'username' => 'admin_user',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => fn () => throw new ConnectionException('Could not resolve host: wp.example.com'),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('ไม่สามารถเชื่อมต่อไปยังเว็บไซต์ WordPress ได้', $result['error']);
    }

    // =========================================================================
    // SECTION 3: 5-MINUTE CACHE & INVALIDATION ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * Verify 5-minute cache prevents repeated HTTP requests for Facebook.
     */
    public function test_cache_5_minute_ttl_prevents_repeated_facebook_requests(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'cache_token_fb'], ['page_id' => 'fb_page_100']);

        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::response([
                'id' => 'fb_page_100',
                'name' => 'Cache Test Page',
                'tasks' => ['CREATE_CONTENT'],
            ], 200),
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                    ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/debug_token*' => Http::response([
                'data' => ['expires_at' => Carbon::now()->addYear()->timestamp],
            ], 200),
        ]);

        // First call sends HTTP requests
        $res1 = $this->service->checkFacebookHealth();
        $this->assertSame('healthy', $res1['status']);
        $initialCount = count(Http::recorded());
        $this->assertGreaterThan(0, $initialCount);

        // Repeated 5 calls within TTL must hit cache (0 new requests)
        for ($i = 0; $i < 5; $i++) {
            $cachedRes = $this->service->checkFacebookHealth();
            $this->assertSame('healthy', $cachedRes['status']);
            $this->assertSame('Cache Test Page', $cachedRes['page_name']);
        }
        $this->assertCount($initialCount, Http::recorded());

        // Calling with forceFresh=true bypasses cache
        $freshRes = $this->service->checkFacebookHealth(forceFresh: true);
        $this->assertSame('healthy', $freshRes['status']);
        $this->assertGreaterThan($initialCount, count(Http::recorded()));
    }

    /**
     * Verify 5-minute cache prevents repeated HTTP requests for WordPress.
     */
    public function test_cache_5_minute_ttl_prevents_repeated_wordpress_requests(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'cache_pass_wp'], [
            'url' => 'https://wp.example.com',
            'username' => 'cache_user',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 1,
                'name' => 'Cache Admin',
                'roles' => ['administrator'],
                'capabilities' => ['publish_posts' => true],
            ], 200),
        ]);

        // First call sends 1 HTTP request
        $res1 = $this->service->checkWordPressHealth();
        $this->assertSame('healthy', $res1['status']);
        Http::assertSentCount(1);

        // Repeated 5 calls within TTL must hit cache (still exactly 1 request)
        for ($i = 0; $i < 5; $i++) {
            $cachedRes = $this->service->checkWordPressHealth();
            $this->assertSame('healthy', $cachedRes['status']);
            $this->assertSame('Cache Admin', $cachedRes['user']);
        }
        Http::assertSentCount(1);

        // Calling with forceFresh=true bypasses cache
        $freshRes = $this->service->checkWordPressHealth(forceFresh: true);
        $this->assertSame('healthy', $freshRes['status']);
        Http::assertSentCount(2);
    }

    /**
     * Verify saveCredentials() immediately invalidates Facebook health check cache.
     */
    public function test_save_credentials_immediately_invalidates_facebook_cache(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'token_v1'], ['page_id' => 'fb_page_100']);

        Http::fake([
            'https://graph.facebook.com/v26.0/fb_page_100*' => Http::sequence()
                ->push(['id' => 'fb_page_100', 'name' => 'Page Version 1', 'tasks' => ['CREATE_CONTENT']], 200)
                ->push(['id' => 'fb_page_100', 'name' => 'Page Version 2', 'tasks' => ['CREATE_CONTENT']], 200),
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                    ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/debug_token*' => Http::response([
                'data' => ['expires_at' => Carbon::now()->addYear()->timestamp],
            ], 200),
        ]);

        $res1 = $this->service->checkFacebookHealth();
        $this->assertSame('Page Version 1', $res1['page_name']);

        // Cache is present
        $this->assertTrue(Cache::has('integrations.facebook.health_check'));

        // Saving new credentials MUST immediately clear cache
        $this->service->saveCredentials('facebook', ['access_token' => 'token_v2']);
        $this->assertFalse(Cache::has('integrations.facebook.health_check'));
        $this->assertFalse(Cache::has('integrations.facebook.health'));

        // Subsequent check runs fresh HTTP call
        $res2 = $this->service->checkFacebookHealth();
        $this->assertSame('Page Version 2', $res2['page_name']);
    }

    /**
     * Verify saveCredentials() immediately invalidates WordPress health check cache.
     */
    public function test_save_credentials_immediately_invalidates_wordpress_cache(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'pass_v1'], [
            'url' => 'https://wp.example.com',
            'username' => 'user_v1',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::sequence()
                ->push(['id' => 1, 'name' => 'User Version 1', 'roles' => ['administrator'], 'capabilities' => ['publish_posts' => true]], 200)
                ->push(['id' => 2, 'name' => 'User Version 2', 'roles' => ['administrator'], 'capabilities' => ['publish_posts' => true]], 200),
        ]);

        $res1 = $this->service->checkWordPressHealth();
        $this->assertSame('User Version 1', $res1['user']);
        $this->assertTrue(Cache::has('integrations.wordpress.health_check'));

        // Saving new credentials MUST immediately clear cache
        $this->service->saveCredentials('wordpress', ['password' => 'pass_v2']);
        $this->assertFalse(Cache::has('integrations.wordpress.health_check'));

        $res2 = $this->service->checkWordPressHealth();
        $this->assertSame('User Version 2', $res2['user']);
    }

    // =========================================================================
    // SECTION 4: FACEBOOK PUBLISHER TOKEN QUERY PARAM LEAK INSPECTION
    // =========================================================================

    /**
     * Inspect ALL recorded HTTP calls across ALL FacebookPublisher operations to prove
     * access_token NEVER appears in URL query parameters.
     */
    public function test_facebook_publisher_never_leaks_access_token_in_url_query_across_all_operations(): void
    {
        $secretToken = 'EAAB_SUPER_CRITICAL_SECRET_TOKEN_99999';
        $pageId = 'target_fb_page_555';

        $this->service->saveCredentials('facebook', ['access_token' => $secretToken], ['page_id' => $pageId]);

        // Mock all endpoints required by FacebookPublisher
        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => $pageId], 200),
            'https://graph.facebook.com/v26.0/target_fb_page_555/photos*' => Http::response(['id' => 'photo_12345'], 200),
            'https://graph.facebook.com/v26.0/target_fb_page_555/feed*' => Http::response(['id' => "{$pageId}_post_999"], 200),
            'https://graph.facebook.com/v26.0/*/comments*' => Http::response(['id' => 'comment_888'], 200),
            'https://graph.facebook.com/v26.0/target_fb_page_555*' => Http::response(['id' => $pageId, 'name' => 'Test Page'], 200),
            'https://graph.facebook.com/v26.0/*' => Http::response(['id' => "{$pageId}_post_999", 'success' => true], 200),
        ]);

        $publisher = new FacebookPublisher($this->service);

        // 1. Operation: healthCheck()
        $health = $publisher->healthCheck();
        $this->assertTrue($health);

        // 2. Operation: publish() text post with comment
        $user = User::factory()->create();
        $post = Post::create([
            'title' => 'Test Post',
            'slug' => 'test-post-fb',
            'author_id' => $user->id,
            'fb_caption' => 'Exciting news from Poster!',
            'fb_comment' => 'Leave your thoughts below!',
        ]);
        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        $result1 = $publisher->publish($post, $target);
        $this->assertTrue($result1['success']);

        // 3. Operation: publish() photo post
        Storage::fake('public');
        $filePath = 'uploads/media/test_image.jpg';
        Storage::disk('public')->put($filePath, 'fake image content');

        $media = PostMedia::create([
            'user_id' => $user->id,
            'file_name' => 'test_image.jpg',
            'file_path' => $filePath,
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);
        $post->update(['featured_image_id' => $media->id]);

        $result2 = $publisher->publish($post, $target);
        $this->assertTrue($result2['success']);

        // 4. Operation: update()
        $target->update([
            'external_id' => "{$pageId}_post_999",
            'external_url' => "https://facebook.com/{$pageId}/posts/post_999",
        ]);
        $result3 = $publisher->update($post, $target);
        $this->assertTrue($result3['success']);

        // INSPECT ALL RECORDED REQUESTS EMPIRICALLY
        $recorded = Http::recorded();
        $this->assertGreaterThanOrEqual(5, count($recorded), 'Must have executed multiple HTTP operations.');

        foreach ($recorded as [$request, $response]) {
            $url = $request->url();

            if (str_contains($url, 'graph.facebook.com')) {
                // Assert 1: Query string must NEVER contain 'access_token'
                $query = parse_url($url, PHP_URL_QUERY) ?? '';
                parse_str($query, $queryParams);

                $this->assertArrayNotHasKey(
                    'access_token',
                    $queryParams,
                    "CRITICAL SECURITY VIOLATION: access_token query param found in URL: {$url}"
                );
                $this->assertStringNotContainsString(
                    $secretToken,
                    $url,
                    "CRITICAL SECURITY VIOLATION: Secret token found plaintext in URL: {$url}"
                );

                // Assert 2: Request must provide token via Bearer header
                $this->assertTrue(
                    $request->hasHeader('Authorization'),
                    "Request to {$url} must include Authorization header."
                );
                $authHeader = $request->header('Authorization')[0];
                $this->assertSame(
                    "Bearer {$secretToken}",
                    $authHeader,
                    "Request to {$url} must use Bearer token authorization."
                );
            }
        }
    }

    /**
     * Verify that when Facebook returns an error payload containing a token,
     * FacebookPublisher sanitizes the error before returning or logging it.
     */
    public function test_facebook_publisher_sanitizes_errors_when_graph_api_returns_token_in_error_payload(): void
    {
        $secretToken = 'EAAB_LEAK_TOKEN_TEST_777';
        $pageId = 'target_fb_page_555';

        $this->service->saveCredentials('facebook', ['access_token' => $secretToken], ['page_id' => $pageId]);

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => $pageId], 200),
            'https://graph.facebook.com/v26.0/target_fb_page_555/feed*' => Http::response([
                'error' => [
                    'message' => 'Malformed access_token=EAAB_LEAK_TOKEN_TEST_777 or permission missing',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        $publisher = new FacebookPublisher($this->service);

        $user = User::factory()->create();
        $post = Post::create([
            'title' => 'Test Post',
            'slug' => 'test-post-leak',
            'author_id' => $user->id,
            'fb_caption' => 'Test',
        ]);
        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        $result = $publisher->publish($post, $target);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringNotContainsString(
            $secretToken,
            $result['error'],
            'Returned error message must have access token redacted.'
        );
        $this->assertStringContainsString('[REDACTED]', $result['error']);
    }
}
