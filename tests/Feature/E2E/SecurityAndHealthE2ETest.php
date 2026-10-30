<?php

namespace Tests\Feature\E2E;

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Services\IntegrationSettingsService;
use App\Support\SecretSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityAndHealthE2ETest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // FEATURE 1: Model Hidden Encryption (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f01_integration_setting_hides_encrypted_credentials_in_array_serialization(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'admin'],
            'encrypted_credentials' => ['password' => 'super-secret-password-123'],
        ]);

        $array = $setting->toArray();

        $this->assertArrayNotHasKey(
            'encrypted_credentials',
            $array,
            'Security violation: encrypted_credentials must be hidden in toArray() serialization.'
        );
    }

    public function test_f01_integration_setting_hides_encrypted_credentials_in_json_serialization(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '123456789', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'EAABsecrettoken12345'],
        ]);

        $json = $setting->toJson();

        $this->assertStringNotContainsString(
            'encrypted_credentials',
            $json,
            'Security violation: encrypted_credentials must be hidden in toJson() serialization.'
        );
        $this->assertStringNotContainsString('EAABsecrettoken12345', $json);
    }

    public function test_f01_integration_setting_database_record_is_encrypted_at_rest(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'admin'],
            'encrypted_credentials' => ['password' => 'plaintext-must-never-appear-in-db'],
        ]);

        $rawRecord = DB::table('integration_settings')->where('channel', 'wordpress')->first();
        $this->assertNotNull($rawRecord);
        $this->assertStringNotContainsString(
            'plaintext-must-never-appear-in-db',
            $rawRecord->encrypted_credentials,
            'Security violation: credentials must be encrypted in raw database storage.'
        );
    }

    public function test_f01_integration_setting_handles_null_and_empty_credentials(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com'],
            'encrypted_credentials' => [],
        ]);

        $array = $setting->toArray();
        $this->assertArrayNotHasKey('encrypted_credentials', $array);
        $this->assertSame([], $setting->encrypted_credentials);
    }

    public function test_f01_integration_setting_special_characters_in_credentials_hidden(): void
    {
        $specialPassword = '!@#$%^&*()_+~`|}{[]:;?><,./-= \'"\\';
        $setting = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com'],
            'encrypted_credentials' => ['password' => $specialPassword],
        ]);

        $this->assertArrayNotHasKey('encrypted_credentials', $setting->toArray());
        $this->assertSame($specialPassword, $setting->fresh()->encrypted_credentials['password']);
    }

    // =========================================================================
    // FEATURE 2: Secret Sanitizer Utility (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f02_secret_sanitizer_exists_and_implements_sanitize_method(): void
    {
        $this->assertTrue(
            class_exists(SecretSanitizer::class),
            'Class App\Support\SecretSanitizer must exist.'
        );
        $this->assertTrue(
            method_exists(SecretSanitizer::class, 'sanitize'),
            'Method SecretSanitizer::sanitize() must exist.'
        );
        $this->assertTrue(
            method_exists(SecretSanitizer::class, 'sanitizeArray'),
            'Method SecretSanitizer::sanitizeArray() must exist.'
        );
    }

    public function test_f02_secret_sanitizer_redacts_facebook_access_tokens(): void
    {
        if (! class_exists(SecretSanitizer::class)) {
            $this->fail('Class App\Support\SecretSanitizer does not exist yet (Feature 2).');
        }

        $input = 'Error occurred with token EAAGm0PXq150BO7ZAxyz1234567890 while calling API';
        $sanitized = SecretSanitizer::sanitize($input);

        $this->assertStringNotContainsString('EAAGm0PXq150BO7ZAxyz1234567890', $sanitized);
        $this->assertStringContainsString('[REDACTED', $sanitized);
    }

    public function test_f02_secret_sanitizer_redacts_password_patterns(): void
    {
        if (! class_exists(SecretSanitizer::class)) {
            $this->fail('Class App\Support\SecretSanitizer does not exist yet (Feature 2).');
        }

        $input = 'Failed authentication with password=SuperSecretP@ssword123 and user=admin';
        $sanitized = SecretSanitizer::sanitize($input);

        $this->assertStringNotContainsString('SuperSecretP@ssword123', $sanitized);
        $this->assertStringContainsString('admin', $sanitized);
    }

    public function test_f02_secret_sanitizer_redacts_nested_array_secrets(): void
    {
        if (! class_exists(SecretSanitizer::class)) {
            $this->fail('Class App\Support\SecretSanitizer does not exist yet (Feature 2).');
        }

        $data = [
            'username' => 'editor_user',
            'password' => 'secret_wp_pass',
            'auth' => [
                'access_token' => 'EAAsecretfbtoken',
                'bearer' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.xyz',
            ],
            'meta' => [
                'channel' => 'facebook',
            ],
        ];

        $sanitized = SecretSanitizer::sanitizeArray($data);

        $this->assertSame('editor_user', $sanitized['username']);
        $this->assertNotSame('secret_wp_pass', $sanitized['password']);
        $this->assertNotSame('EAAsecretfbtoken', $sanitized['auth']['access_token']);
        $this->assertSame('facebook', $sanitized['meta']['channel']);
    }

    public function test_f02_secret_sanitizer_preserves_safe_strings_and_empty_inputs(): void
    {
        if (! class_exists(SecretSanitizer::class)) {
            $this->fail('Class App\Support\SecretSanitizer does not exist yet (Feature 2).');
        }

        $this->assertSame('', SecretSanitizer::sanitize(''));
        $safeMessage = 'Successfully published post ID 42 to WordPress';
        $this->assertSame($safeMessage, SecretSanitizer::sanitize($safeMessage));
        $this->assertSame([], SecretSanitizer::sanitizeArray([]));
    }

    public function test_f02_secret_sanitizer_handles_complex_exception_traces(): void
    {
        if (! class_exists(SecretSanitizer::class)) {
            $this->fail('Class App\Support\SecretSanitizer does not exist yet (Feature 2).');
        }

        $stackTrace = "Exception: Connection failed\n#0 FacebookPublisher.php(48): Http::get('https://graph.facebook.com/v26.0/me?access_token=EAABtokenSecret123')\n#1 {main}";
        $sanitized = SecretSanitizer::sanitize($stackTrace);

        $this->assertStringNotContainsString('EAABtokenSecret123', $sanitized);
        $this->assertStringContainsString('FacebookPublisher.php', $sanitized);
    }

    // =========================================================================
    // FEATURE 3: Bearer Token Authentication (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f03_facebook_api_requests_pass_tokens_via_bearer_header_not_query_param(): void
    {
        $token = 'EAABtestbearertoken12345';
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '10001', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => $token],
            'status' => 'connected',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'id' => '10001',
                'name' => 'Poster Page',
            ], 200),
        ]);

        $service = app(IntegrationSettingsService::class);
        if (method_exists($service, 'checkFacebookHealth')) {
            $service->checkFacebookHealth();
        } else {
            // Check manual route if service method not yet added
            $user = User::factory()->create();
            $this->actingAs($user)->post(route('settings.integrations.check', 'facebook'));
        }

        $sentRequests = Http::recorded();
        $this->assertNotEmpty($sentRequests, 'HTTP requests to Facebook must be recorded.');

        foreach ($sentRequests as [$request, $response]) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $this->assertStringNotContainsString(
                    'access_token=',
                    $request->url(),
                    'Feature 3 requirement: Facebook API requests must not pass access_token in query string.'
                );
                $this->assertTrue(
                    $request->hasHeader('Authorization'),
                    'Feature 3 requirement: Facebook API requests must pass Authorization Bearer header.'
                );
            }
        }
    }

    public function test_f03_facebook_graph_post_does_not_leak_bearer_in_url(): void
    {
        $token = 'EAABtestsecret987';
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '10001', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => $token],
            'status' => 'connected',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'post_10001_999'], 200),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.integrations.check', 'facebook'));

        $sentRequests = Http::recorded();
        $this->assertNotEmpty($sentRequests, 'Facebook health check request must be sent.');

        foreach ($sentRequests as [$request, $response]) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $this->assertStringNotContainsString(
                    'EAABtestsecret987',
                    $request->url(),
                    'Security requirement: Access token must never appear in URL query string.'
                );
            }
        }
    }

    // =========================================================================
    // FEATURE 4: Integration Health Checks (Tier 1 & Tier 2)
    // =========================================================================

    public function test_f04_facebook_health_check_returns_healthy_with_valid_token_and_permissions(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '10001', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'valid-page-token'],
            'status' => 'unchecked',
        ]);

        Http::fake([
            'https://graph.facebook.com/*/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                    ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'id' => '10001',
                'name' => 'Poster Page',
            ], 200),
        ]);

        $service = app(IntegrationSettingsService::class);
        if (method_exists($service, 'checkFacebookHealth')) {
            $health = $service->checkFacebookHealth();
            $this->assertSame('healthy', $health['status']);
        } else {
            $user = User::factory()->create();
            $this->actingAs($user)->post(route('settings.integrations.check', 'facebook'))
                ->assertRedirect();
            $this->assertSame('connected', IntegrationSetting::where('channel', 'facebook')->value('status'));
        }
    }

    public function test_f04_facebook_health_check_returns_unhealthy_when_token_expired(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '10001', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'expired-token'],
            'status' => 'connected',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 401),
        ]);

        $service = app(IntegrationSettingsService::class);
        if (method_exists($service, 'checkFacebookHealth')) {
            $health = $service->checkFacebookHealth();
            $this->assertSame('unhealthy', $health['status']);
            $this->assertNotNull($health['error']);
        } else {
            $user = User::factory()->create();
            $this->actingAs($user)->post(route('settings.integrations.check', 'facebook'));
            $this->assertSame('error', IntegrationSetting::where('channel', 'facebook')->value('status'));
        }
    }

    public function test_f04_wordpress_health_check_returns_healthy_with_valid_credentials(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'valid-app-password'],
            'status' => 'unchecked',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me' => Http::response([
                'id' => 1,
                'name' => 'editor',
                'roles' => ['administrator'],
                'capabilities' => ['publish_posts' => true],
            ], 200),
        ]);

        $service = app(IntegrationSettingsService::class);
        if (method_exists($service, 'checkWordPressHealth')) {
            $health = $service->checkWordPressHealth();
            $this->assertSame('healthy', $health['status']);
        } else {
            $user = User::factory()->create();
            $this->actingAs($user)->post(route('settings.integrations.check', 'wordpress'))
                ->assertRedirect();
            $this->assertSame('connected', IntegrationSetting::where('channel', 'wordpress')->value('status'));
        }
    }

    public function test_f04_wordpress_health_check_returns_unhealthy_on_invalid_credentials(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'wrong-password'],
            'status' => 'connected',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me' => Http::response([
                'code' => 'incorrect_password',
                'message' => 'The password you entered is incorrect.',
                'data' => ['status' => 401],
            ], 401),
        ]);

        $service = app(IntegrationSettingsService::class);
        if (method_exists($service, 'checkWordPressHealth')) {
            $health = $service->checkWordPressHealth();
            $this->assertSame('unhealthy', $health['status']);
        } else {
            $user = User::factory()->create();
            $this->actingAs($user)->post(route('settings.integrations.check', 'wordpress'));
            $this->assertSame('error', IntegrationSetting::where('channel', 'wordpress')->value('status'));
        }
    }

    public function test_f04_health_check_handles_connection_timeouts(): void
    {
        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://unreachable.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'password'],
            'status' => 'connected',
        ]);

        Http::fake([
            'https://unreachable.example.com/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.integrations.check', 'wordpress'));

        $status = IntegrationSetting::where('channel', 'wordpress')->value('status');
        $this->assertContains($status, ['error', 'unhealthy']);
    }
}
