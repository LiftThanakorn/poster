<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Services\IntegrationSettingsService;
use App\Services\Publishers\FacebookPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected IntegrationSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IntegrationSettingsService::class);
    }

    public function test_facebook_health_check_healthy_without_page_tasks_field(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '128159403529342', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'valid-page-token-123'],
        ]);

        Http::fake([
            'https://graph.facebook.com/v26.0/128159403529342*' => Http::response([
                'id' => '128159403529342',
                'name' => 'Official Poster Page',
            ], 200),
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                    ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                    ['permission' => 'read_insights', 'status' => 'granted'],
                ],
            ], 200),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('healthy', $result['status']);
        $this->assertNull($result['error']);
        $this->assertSame('128159403529342', $result['page_id']);
        $this->assertSame('Official Poster Page', $result['page_name']);
        $this->assertContains('read_insights', $result['permissions']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/128159403529342?')) {
                return true;
            }

            return str_contains($request->url(), 'fields=id%2Cname')
                && ! str_contains($request->url(), 'tasks');
        });
    }

    public function test_facebook_health_check_uses_bearer_header_instead_of_query_param(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '128159403529342', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'secret-bearer-token-abc'],
        ]);

        Http::fake([
            'https://graph.facebook.com/v26.0/128159403529342*' => Http::response([
                'id' => '128159403529342',
                'name' => 'Official Poster Page',
                'tasks' => ['CREATE_CONTENT'],
            ], 200),
        ]);

        $this->service->checkFacebookHealth();

        Http::assertSent(function (Request $request) {
            $isTargetUrl = str_contains($request->url(), '128159403529342');
            $hasBearer = $request->hasHeader('Authorization', 'Bearer secret-bearer-token-abc');
            $noTokenInQuery = ! str_contains($request->url(), 'access_token=');

            return $isTargetUrl && $hasBearer && $noTokenInQuery;
        });
    }

    public function test_facebook_publisher_sends_bearer_token_and_omits_query_param(): void
    {
        $this->service->saveCredentials('facebook', ['access_token' => 'fb-secret-token'], ['page_id' => 'page-123']);

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => 'page-123'], 200),
            'https://graph.facebook.com/v26.0/page-123*' => Http::response(['id' => 'page-123', 'name' => 'My Page'], 200),
        ]);

        $publisher = new FacebookPublisher($this->service);
        $isHealthy = $publisher->healthCheck();

        $this->assertTrue($isHealthy);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $hasBearer = $request->hasHeader('Authorization', 'Bearer fb-secret-token');
                $query = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($query, $params);
                $hasNoQueryToken = ! isset($params['access_token']);

                return $hasBearer && $hasNoQueryToken;
            }

            return true;
        });
    }

    public function test_facebook_health_check_unhealthy_when_token_is_expired_code_190(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '128159403529342', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'expired-token-xyz'],
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
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
        $this->assertTrue(
            str_contains($result['error'], '190') ||
            str_contains($result['error'], 'expired') ||
            str_contains($result['error'], 'หมดอายุ')
        );
    }

    public function test_facebook_health_check_unhealthy_when_permissions_are_missing_code_200(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '128159403529342', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'insufficient-permission-token'],
        ]);

        Http::fake([
            'https://graph.facebook.com/v26.0/128159403529342*' => Http::response([
                'id' => '128159403529342',
                'name' => 'Official Poster Page',
                'tasks' => ['CREATE_CONTENT'],
            ], 200),
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_show_list', 'status' => 'granted'],
                    // pages_manage_posts missing!
                ],
            ], 200),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertTrue(
            str_contains($result['error'], 'pages_manage_posts') ||
            str_contains($result['error'], 'สิทธิ์')
        );
    }

    public function test_facebook_health_check_unhealthy_when_page_id_does_not_match(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '128159403529342', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'wrong-page-token'],
        ]);

        Http::fake([
            'https://graph.facebook.com/v26.0/128159403529342*' => Http::response([
                'id' => '999999999999999',
                'name' => 'Foreign Page',
            ], 200),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
    }

    public function test_facebook_health_check_unhealthy_on_connection_timeout(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '128159403529342', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'valid-page-token'],
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 28: Connection timed out'),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertTrue(
            str_contains($result['error'], 'timed out') ||
            str_contains($result['error'], 'หมดเวลา') ||
            str_contains($result['error'], 'Failed')
        );
    }

    public function test_facebook_health_check_unhealthy_when_credentials_not_configured(): void
    {
        IntegrationSetting::query()->where('channel', 'facebook')->delete();
        config(['services.facebook.page_id' => '', 'services.facebook.access_token' => '']);

        $result = $this->service->checkFacebookHealth(forceFresh: true);

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        Http::assertNothingSent();
    }

    public function test_wordpress_health_check_healthy_with_valid_credentials_and_publish_capability(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'secret-app-password'],
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 42,
                'name' => 'Editor In Chief',
                'roles' => ['editor'],
                'capabilities' => [
                    'publish_posts' => true,
                    'edit_posts' => true,
                ],
            ], 200),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('healthy', $result['status']);
        $this->assertTrue($result['can_publish']);
        $this->assertSame('Editor In Chief', $result['user']);
        $this->assertNull($result['error']);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_wordpress_health_check_unhealthy_when_password_is_invalid_401(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'wrong-app-password'],
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'code' => 'rest_not_logged_in',
                'message' => 'You are not currently logged in.',
                'data' => ['status' => 401],
            ], 401),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
        $this->assertTrue(
            str_contains($result['error'], '401') ||
            str_contains($result['error'], 'rest_not_logged_in') ||
            str_contains($result['error'], 'ไม่สามารถเข้าสู่ระบบ')
        );
    }

    public function test_wordpress_health_check_unhealthy_when_user_cannot_publish_posts(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'subscriber_user'],
            'encrypted_credentials' => ['password' => 'subscriber-password'],
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 99,
                'name' => 'ReadOnly User',
                'roles' => ['subscriber'],
                'capabilities' => [
                    'publish_posts' => false,
                    'read' => true,
                ],
            ], 200),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
        $this->assertTrue(
            str_contains($result['error'], 'publish') ||
            str_contains($result['error'], 'สิทธิ์')
        );
    }

    public function test_wordpress_health_check_unhealthy_on_server_error_500(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'app-password'],
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response('Internal Server Error', 500),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
    }

    public function test_wordpress_health_check_unhealthy_on_connection_timeout(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'app-password'],
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => fn () => throw new ConnectionException('Could not resolve host: wp.example.com'),
        ]);

        $result = $this->service->checkWordPressHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
    }

    public function test_wordpress_health_check_unhealthy_when_credentials_not_configured(): void
    {
        IntegrationSetting::query()->where('channel', 'wordpress')->delete();
        config(['services.wordpress.url' => '', 'services.wordpress.username' => '', 'services.wordpress.password' => '']);

        $result = $this->service->checkWordPressHealth(forceFresh: true);

        $this->assertSame('unhealthy', $result['status']);
        $this->assertFalse($result['can_publish']);
        $this->assertNotNull($result['error']);
        Http::assertNothingSent();
    }

    public function test_health_checks_update_database_model_status_and_timestamps(): void
    {
        $wp = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'secret-app-pass'],
            'status' => 'unchecked',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 1,
                'name' => 'Admin',
                'roles' => ['administrator'],
                'capabilities' => ['publish_posts' => true],
            ], 200),
        ]);

        $this->service->checkWordPressHealth();

        $wp->refresh();
        $this->assertSame('connected', $wp->status);
        $this->assertNotNull($wp->last_checked_at);
        $this->assertNull($wp->last_error);
    }

    public function test_health_check_caching_and_cache_invalidation_on_settings_update(): void
    {
        $this->service->saveCredentials('wordpress', ['password' => 'secret-app-pass'], [
            'url' => 'https://wp.example.com',
            'username' => 'editor',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::sequence()
                ->push([
                    'id' => 1,
                    'name' => 'Admin User',
                    'roles' => ['administrator'],
                    'capabilities' => ['publish_posts' => true],
                ], 200)
                ->push([
                    'id' => 2,
                    'name' => 'Changed User',
                    'roles' => ['administrator'],
                    'capabilities' => ['publish_posts' => true],
                ], 200),
        ]);

        // 1. First probe hits HTTP
        $health1 = $this->service->checkWordPressHealth();
        $this->assertSame('Admin User', $health1['user']);
        Http::assertSentCount(1);

        // 2. Second probe is cached within 5 minutes (no new HTTP call)
        $health2 = $this->service->checkWordPressHealth();
        $this->assertSame('Admin User', $health2['user']);
        Http::assertSentCount(1);

        // 3. Saving credentials invalidates the cache
        $this->service->saveCredentials('wordpress', ['password' => 'new-pass']);
        $this->assertFalse(Cache::has('integrations.wordpress.health_check'));

        // 4. Next probe makes a new HTTP request
        $health3 = $this->service->checkWordPressHealth();
        $this->assertSame('Changed User', $health3['user']);
        Http::assertSentCount(2);
    }
}
