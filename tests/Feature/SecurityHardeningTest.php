<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\Post;
use App\Models\PublishAttempt;
use App\Models\PublishEvent;
use App\Models\PublishTarget;
use App\Models\User;
use App\Support\SecretSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_setting_hides_encrypted_credentials_in_to_array(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => [
                'password' => 'super-secret-wp-password-12345',
                'api_token' => 'top-secret-token',
            ],
            'status' => 'connected',
        ]);

        $array = $setting->toArray();

        $this->assertArrayNotHasKey('encrypted_credentials', $array);
        $this->assertSame('https://wp.example.com', $array['settings']['url']);
        $this->assertStringNotContainsString('super-secret-wp-password-12345', json_encode($array));
        $this->assertStringNotContainsString('top-secret-token', json_encode($array));
    }

    public function test_integration_setting_hides_encrypted_credentials_in_to_json_and_json_encode(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '987654321', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'EAAPsecret_access_token_xyz_999'],
            'status' => 'connected',
        ]);

        $toJson = $setting->toJson();
        $jsonEncoded = json_encode($setting);

        $this->assertStringNotContainsString('encrypted_credentials', $toJson);
        $this->assertStringNotContainsString('EAAPsecret_access_token_xyz_999', $toJson);
        $this->assertStringNotContainsString('encrypted_credentials', $jsonEncoded);
        $this->assertStringNotContainsString('EAAPsecret_access_token_xyz_999', $jsonEncoded);
    }

    public function test_integration_setting_encrypted_credentials_remains_accessible_via_eloquent_attribute(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com'],
            'encrypted_credentials' => [
                'password' => 'my-accessible-secret',
                'api_token' => 'my-token',
            ],
        ]);

        // Model attribute must be intact for internal server-side use
        $this->assertIsArray($setting->encrypted_credentials);
        $this->assertSame('my-accessible-secret', $setting->encrypted_credentials['password']);
        $this->assertSame('my-token', $setting->encrypted_credentials['api_token']);
    }

    public function test_integration_setting_encrypted_credentials_visible_when_explicitly_requested(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '111'],
            'encrypted_credentials' => ['access_token' => 'explicit-reveal-token'],
        ]);

        $revealed = $setting->makeVisible('encrypted_credentials')->toArray();

        $this->assertArrayHasKey('encrypted_credentials', $revealed);
        $this->assertSame('explicit-reveal-token', $revealed['encrypted_credentials']['access_token']);
    }

    public function test_secret_sanitizer_scrubs_facebook_access_token_query_parameters(): void
    {
        $raw = 'GET https://graph.facebook.com/v26.0/me?fields=id,name&access_token=EAAPsecret123456789abc&locale=th_TH';
        $sanitized = SecretSanitizer::sanitize($raw);

        $this->assertStringNotContainsString('EAAPsecret123456789abc', $sanitized);
        $this->assertStringContainsString('access_token=[REDACTED]', $sanitized);
        $this->assertStringContainsString('fields=id,name', $sanitized);
        $this->assertStringContainsString('locale=th_TH', $sanitized);
    }

    public function test_secret_sanitizer_scrubs_standalone_facebook_tokens(): void
    {
        $raw = 'Token validation failed for EAAGm0PXq150BO7ZAxyz1234567890 while calling API endpoint';
        $sanitized = SecretSanitizer::sanitize($raw);

        $this->assertStringNotContainsString('EAAGm0PXq150BO7ZAxyz1234567890', $sanitized);
        $this->assertStringContainsString('[REDACTED]', $sanitized);
    }

    public function test_secret_sanitizer_scrubs_bearer_authorization_headers(): void
    {
        $raw = 'HTTP Client Request Headers: [Authorization: Bearer EAAP_long_lived_token_token_12345.xyz]';
        $sanitized = SecretSanitizer::sanitize($raw);

        $this->assertStringNotContainsString('EAAP_long_lived_token_token_12345.xyz', $sanitized);
        $this->assertStringContainsString('Authorization: Bearer [REDACTED]', $sanitized);
    }

    public function test_secret_sanitizer_scrubs_basic_auth_credentials(): void
    {
        $header = 'Authorization: Basic dXNlcm5hbWU6bXlfc2VjcmV0X3Bhc3N3b3JkXzEyMw==';
        $sanitizedHeader = SecretSanitizer::sanitize($header);

        $this->assertStringNotContainsString('dXNlcm5hbWU6bXlfc2VjcmV0X3Bhc3N3b3JkXzEyMw==', $sanitizedHeader);
        $this->assertStringContainsString('Authorization: Basic [REDACTED]', $sanitizedHeader);

        $url = 'cURL error 28: Connection to https://personnel:my_raw_wp_pass@wp.example.com/wp-json timed out';
        $sanitizedUrl = SecretSanitizer::sanitize($url);

        $this->assertStringNotContainsString('my_raw_wp_pass', $sanitizedUrl);
        $this->assertStringContainsString('https://personnel:[REDACTED]@wp.example.com', $sanitizedUrl);
    }

    public function test_secret_sanitizer_scrubs_passwords_in_json_and_urls(): void
    {
        $json = '{"error":"invalid_request","password":"my_super_app_password_999","user":"admin"}';
        $sanitizedJson = SecretSanitizer::sanitize($json);

        $this->assertStringNotContainsString('my_super_app_password_999', $sanitizedJson);
        $this->assertStringContainsString('"password":"[REDACTED]"', $sanitizedJson);

        $param = 'POST /login?user=admin&password=unmasked_query_password&remember=1';
        $sanitizedParam = SecretSanitizer::sanitize($param);

        $this->assertStringNotContainsString('unmasked_query_password', $sanitizedParam);
        $this->assertStringContainsString('password=[REDACTED]', $sanitizedParam);
    }

    public function test_secret_sanitizer_preserves_thai_unicode_characters(): void
    {
        $thaiMessage = 'ข้อผิดพลาด: access_token=EAAB1234567890abcdef1234567890 การเชื่อมต่อล้มเหลว';
        $sanitized = SecretSanitizer::sanitize($thaiMessage);

        $this->assertStringNotContainsString('EAAB1234567890abcdef1234567890', $sanitized);
        $this->assertSame('ข้อผิดพลาด: access_token=[REDACTED] การเชื่อมต่อล้มเหลว', $sanitized);
    }

    public function test_secret_sanitizer_recursively_scrubs_nested_arrays_and_keys(): void
    {
        $payload = [
            'status' => 'failed',
            'code' => 401,
            'message' => 'Failed to connect with access_token=EAAP_nested_query_token_123',
            'sensitive_keys' => [
                'password' => 'plaintext_wp_password',
                'access_token' => 'plaintext_fb_token',
                'app_password' => 'secret_app_pass',
            ],
            'safe_metadata' => [
                'channel' => 'wordpress',
                'url' => 'https://example.com',
                'active' => true,
                'count' => 5,
                'nullable' => null,
            ],
        ];

        $sanitized = SecretSanitizer::sanitizeArray($payload);

        $this->assertSame('[REDACTED]', $sanitized['sensitive_keys']['password']);
        $this->assertSame('[REDACTED]', $sanitized['sensitive_keys']['access_token']);
        $this->assertSame('[REDACTED]', $sanitized['sensitive_keys']['app_password']);
        $this->assertSame('wordpress', $sanitized['safe_metadata']['channel']);
        $this->assertTrue($sanitized['safe_metadata']['active']);
        $this->assertSame(5, $sanitized['safe_metadata']['count']);
        $this->assertNull($sanitized['safe_metadata']['nullable']);
        $this->assertStringNotContainsString('EAAP_nested_query_token_123', $sanitized['message']);
        $this->assertStringContainsString('access_token=[REDACTED]', $sanitized['message']);
    }

    public function test_secret_sanitizer_handles_null_numbers_and_safe_text_without_corruption(): void
    {
        $this->assertSame('', SecretSanitizer::sanitize(''));
        $this->assertSame('Clean error message without secrets', SecretSanitizer::sanitize('Clean error message without secrets'));
        $this->assertSame(['key' => 'value'], SecretSanitizer::sanitizeArray(['key' => 'value']));
    }

    public function test_secret_sanitizer_sanitizes_throwable(): void
    {
        $exception = new \RuntimeException('Failed calling https://api.facebook.com?access_token=EAAB1234567890');
        $sanitized = SecretSanitizer::sanitizeThrowable($exception);

        $this->assertStringNotContainsString('EAAB1234567890', $sanitized);
        $this->assertStringContainsString('access_token=[REDACTED]', $sanitized);
    }

    public function test_sanitizer_scrubs_secrets_before_persisting_publish_attempts_and_events(): void
    {
        $user = User::factory()->create();
        $post = Post::query()->create(['title' => 'Test Post', 'slug' => 'test-post', 'author_id' => $user->id]);
        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'failed',
        ]);

        $leakedMessage = 'Facebook API error: access_token=EAAP_secret_leak_in_event token expired';
        $leakedMetadata = [
            'raw_url' => 'https://graph.facebook.com/v26.0/me?access_token=EAAP_secret_leak_in_metadata',
            'headers' => ['Authorization' => 'Bearer EAAP_secret_header'],
            'password' => 'sensitive_pass',
        ];

        $correlationId = (string) Str::uuid();

        $event = PublishEvent::create([
            'correlation_id' => $correlationId,
            'post_id' => $post->id,
            'publish_target_id' => $target->id,
            'channel' => 'facebook',
            'level' => 'error',
            'event_type' => 'publish_failed',
            'message' => SecretSanitizer::sanitize($leakedMessage),
            'context' => SecretSanitizer::sanitizeArray($leakedMetadata),
        ]);

        $attempt = PublishAttempt::create([
            'correlation_id' => $correlationId,
            'post_id' => $post->id,
            'publish_target_id' => $target->id,
            'channel' => 'facebook',
            'attempt_number' => 1,
            'status' => 'failed',
            'error_message' => SecretSanitizer::sanitize($leakedMessage),
            'metadata' => SecretSanitizer::sanitizeArray($leakedMetadata),
        ]);

        $refreshedEvent = PublishEvent::findOrFail($event->id);
        $refreshedAttempt = PublishAttempt::findOrFail($attempt->id);

        $this->assertStringNotContainsString('EAAP_secret_leak_in_event', $refreshedEvent->message);
        $this->assertStringNotContainsString('EAAP_secret_leak_in_metadata', json_encode($refreshedEvent->context));
        $this->assertStringNotContainsString('sensitive_pass', json_encode($refreshedEvent->context));

        $this->assertStringNotContainsString('EAAP_secret_leak_in_event', $refreshedAttempt->error_message);
        $this->assertStringNotContainsString('EAAP_secret_leak_in_metadata', json_encode($refreshedAttempt->metadata));
        $this->assertStringNotContainsString('sensitive_pass', json_encode($refreshedAttempt->metadata));
    }
}
