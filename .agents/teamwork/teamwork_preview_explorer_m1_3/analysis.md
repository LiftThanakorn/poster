# Milestone 1: Migration Strategy & Test Specifications Analysis Report

**Date**: 2026-10-02  
**Author**: Explorer 3 (`teamwork_preview_explorer_m1_3`)  
**Scope**: Feature 5 (`publish_targets.status` migration) & Milestone 1 Test Specifications (`SecurityHardeningTest`, `IntegrationHealthCheckTest`)  
**Project**: Poster (Laravel 13 + React + Inertia)  
**File Location**: `C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_explorer_m1_3\analysis.md`  

---

## 1. Executive Summary

This report delivers the technical specifications and implementation blueprints for two critical deliverables of **Milestone 1 (Security, Integration & Health Foundation)**:

1. **Feature 5 Schema Migration**: Converting `publish_targets.status` from `enum(['pending', 'processing', 'published', 'failed'])` to `string('status', 32)->default('pending')`. This resolves a latent MySQL strict mode crash when unselected channels are marked `'detached'` (`PostController.php:203`), unlocks the `'retrying'` status required by near-real-time polling (R4), and provides clean, zero-data-loss rollback safety on both MySQL 8+ production and SQLite in-memory test environments.
2. **Milestone 1 Test Specifications**: Defining complete test classes:
   - `tests/Feature/SecurityHardeningTest.php` (11 comprehensive test methods): Verifies model serialization quarantine (`$hidden = ['encrypted_credentials']`), property accessibility, and multi-pattern scrubbing by `SecretSanitizer` (Bearer tokens, Basic auth headers, URL parameters, JSON payloads, and recursive metadata arrays).
   - `tests/Feature/IntegrationHealthCheckTest.php` (15 comprehensive test methods): Employs `Http::fake()` to verify Facebook Graph API and WordPress REST API health probes across valid credentials, token expiration (FB code 190), permission denial (FB code 200/283), invalid application passwords (WP 401), insufficient user capabilities (WP subscriber role), network timeouts, unconfigured environments, and 5-minute health caching with automatic invalidation.

---

## 2. Feature 5: `publish_targets.status` Migration Strategy

### 2.1 Problem Analysis & Current Codebase Audit

In `database/migrations/2026_10_01_042855_create_publish_targets_table.php` (lines 14–27):
```php
Schema::create('publish_targets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
    $table->string('channel'); // wordpress, facebook
    $table->enum('status', ['pending', 'processing', 'published', 'failed'])->default('pending');
    $table->string('external_id')->nullable();
    $table->string('external_url')->nullable();
    $table->text('error_message')->nullable();
    $table->integer('retry_count')->default(0);
    $table->timestamps();

    $table->unique(['post_id', 'channel']);
    $table->index('status');
});
```

#### Defect 1: MySQL Strict Mode Rejection on `'detached'`
In `app/Http/Controllers/PostController.php` (line 201–203):
```php
$post->targets()
    ->whereNotIn('channel', $validated['channels'])
    ->update(['status' => 'detached']);
```
In MySQL with strict SQL mode (`STRICT_TRANS_TABLES`), setting an `enum` column to any value not defined in the enum declaration immediately throws:
`SQLSTATE[22001]: String data, right truncated: 1265 Data truncated for column 'status' at row X`
Because `'detached'` is absent from `['pending', 'processing', 'published', 'failed']`, updating posts with deselected channels crashes in production MySQL environments.

#### Defect 2: Real-time Polling & Exponential Backoff Support
Milestones 2 & 4 (R1, R4) introduce status `'retrying'` to represent active exponential backoff sleep without marking the job as permanently failed or prematurely pending. An enum column cannot accommodate `'retrying'` without continuous schema alterations.

### 2.2 Database Compatibility: MySQL Production vs. SQLite Testing

| Dimension | MySQL 8.x (Production / Local) | SQLite 3 (PHPUnit Test Suite) |
|---|---|---|
| **Altering Mechanism** | Native `ALTER TABLE publish_targets MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'` | Table rebuild: Laravel creates temporary table with new schema, copies data, drops old table, renames temp table, and restores indexes/foreign keys |
| **Laravel 13 Support** | Native schema grammar (`change()` does not require `doctrine/dbal`) | Native schema grammar (`change()` does not require `doctrine/dbal`) |
| **Index Handling** | Existing index `publish_targets_status_index` is preserved in place | Index `publish_targets_status_index` is recreated during table rebuild |
| **Rollback Risk** | **HIGH**: If rows have `status = 'detached'` or `'retrying'`, rolling back to `enum` fails with SQL error 1265 (Data truncated) | **MEDIUM**: Check constraints on recreated table fail if existing rows violate new check constraint |
| **Mitigation** | Clean pre-rollback data normalization in `down()` | Clean pre-rollback data normalization in `down()` |

### 2.3 Proposed Migration Implementation

- **File Path**: `database/migrations/2026_10_02_030000_alter_status_column_on_publish_targets_table.php`
- **Class Structure**:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('publish_targets', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Pre-condition: Any status values outside the original enum ('detached', 'retrying', etc.)
     * must be normalized to 'pending' before reverting to the enum type to prevent
     * MySQL strict mode truncation errors (SQLSTATE[22001] / Error 1265) and SQLite check failures.
     */
    public function down(): void
    {
        DB::table('publish_targets')
            ->whereNotIn('status', ['pending', 'processing', 'published', 'failed'])
            ->update(['status' => 'pending']);

        Schema::table('publish_targets', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'published', 'failed'])
                ->default('pending')
                ->change();
        });
    }
};
```

### 2.4 Empirical Verification Findings
Empirical verification was conducted on both active database drivers:
1. **SQLite (`:memory:`)**:
   - `Schema::table('test_targets', fn ($t) => $t->string('status', 32)->default('pending')->change());` executed cleanly.
   - Inserting `status = 'retrying'` succeeded.
   - Pre-normalization and reverting back to enum succeeded, returning `DOWN_RESULT: pending`.
2. **MySQL (`poster` database)**:
   - Creation of test table with enum and status index succeeded.
   - Changing column to `string(32)` succeeded without dropping or invalidating `test_targets_status_index`.
   - Reverting back to enum after updating non-standard values to `'pending'` succeeded with exit code 0 (`MYSQL_DOWN_RESULT: pending`).

---

## 3. Milestone 1 Test Specification 1: `SecurityHardeningTest`

### 3.1 Target Test Location
- **File**: `tests/Feature/SecurityHardeningTest.php`
- **Namespace**: `Tests\Feature`
- **Base Class**: `Tests\TestCase` with `Illuminate\Foundation\Testing\RefreshDatabase`

### 3.2 Required Test Coverage Matrix

| # | Test Method Name | Component Under Test | Tested Behavior / Edge Case | Expected Assertion |
|---|---|---|---|---|
| 1 | `test_integration_setting_hides_encrypted_credentials_in_to_array` | `IntegrationSetting` | Eloquent serialization via `toArray()` | `'encrypted_credentials'` key does not exist; secret values absent from array |
| 2 | `test_integration_setting_hides_encrypted_credentials_in_to_json_and_json_encode` | `IntegrationSetting` | Model JSON serialization via `toJson()` & `json_encode()` | String does not contain `'encrypted_credentials'` or decrypted secret string |
| 3 | `test_integration_setting_encrypted_credentials_remains_accessible_via_eloquent_attribute` | `IntegrationSetting` | Model attribute accessor in internal PHP code | Direct access `$setting->encrypted_credentials` returns decrypted array |
| 4 | `test_integration_setting_encrypted_credentials_visible_when_explicitly_requested` | `IntegrationSetting` | Eloquent `makeVisible()` override | When explicitly called, `$setting->makeVisible('encrypted_credentials')->toArray()` reveals field |
| 5 | `test_secret_sanitizer_scrubs_facebook_access_token_query_parameters` | `SecretSanitizer` | URL query string with `access_token=...` | Replaced with `access_token=***REDACTED***` |
| 6 | `test_secret_sanitizer_scrubs_bearer_authorization_headers` | `SecretSanitizer` | Header string with `Bearer <token>` | Replaced with `Bearer ***REDACTED***` |
| 7 | `test_secret_sanitizer_scrubs_basic_auth_credentials` | `SecretSanitizer` | `Authorization: Basic <base64>` & `http://user:pass@host` | Credentials scrubbed to `***REDACTED***` |
| 8 | `test_secret_sanitizer_scrubs_passwords_in_json_and_urls` | `SecretSanitizer` | JSON payloads containing `"password": "..."` | Password string scrubbed without destroying JSON validity |
| 9 | `test_secret_sanitizer_recursively_scrubs_nested_arrays_and_keys` | `SecretSanitizer` | Array with sensitive keys (`password`, `access_token`) and nested strings | Sensitive keys replaced with `***REDACTED***`; non-sensitive keys untouched |
| 10 | `test_secret_sanitizer_handles_null_numbers_and_safe_text_without_corruption` | `SecretSanitizer` | Non-string / non-secret values (integers, null, safe URLs) | Returns original values unaltered |
| 11 | `test_sanitizer_scrubs_secrets_before_persisting_publish_attempts_and_events` | Integration | Persisting error traces containing secrets to `publish_attempts.metadata` & `publish_events.context` | Database records contain zero raw tokens or passwords |

### 3.3 Concrete Test Class Blueprint (`tests/Feature/SecurityHardeningTest.php`)

```php
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
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_setting_hides_encrypted_credentials_in_to_array(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'super-secret-wp-pass-12345'],
            'status' => 'connected',
        ]);

        $array = $setting->toArray();

        $this->assertArrayNotHasKey('encrypted_credentials', $array);
        $this->assertSame('https://wp.example.com', $array['settings']['url']);
        $this->assertStringNotContainsString('super-secret-wp-pass-12345', json_encode($array));
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
            'encrypted_credentials' => ['password' => 'my-accessible-secret'],
        ]);

        // Model attribute must be intact for internal server-side use
        $this->assertIsArray($setting->encrypted_credentials);
        $this->assertSame('my-accessible-secret', $setting->encrypted_credentials['password']);
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
        $this->assertStringContainsString('access_token=***REDACTED***', $sanitized);
        $this->assertStringContainsString('fields=id,name', $sanitized);
        $this->assertStringContainsString('locale=th_TH', $sanitized);
    }

    public function test_secret_sanitizer_scrubs_bearer_authorization_headers(): void
    {
        $raw = 'HTTP Client Request Headers: [Authorization: Bearer EAAP_long_lived_token_token_12345.xyz]';
        $sanitized = SecretSanitizer::sanitize($raw);

        $this->assertStringNotContainsString('EAAP_long_lived_token_token_12345.xyz', $sanitized);
        $this->assertStringContainsString('Authorization: Bearer ***REDACTED***', $sanitized);
    }

    public function test_secret_sanitizer_scrubs_basic_auth_credentials(): void
    {
        $header = 'Authorization: Basic dXNlcm5hbWU6bXlfc2VjcmV0X3Bhc3N3b3JkXzEyMw==';
        $sanitizedHeader = SecretSanitizer::sanitize($header);

        $this->assertStringNotContainsString('dXNlcm5hbWU6bXlfc2VjcmV0X3Bhc3N3b3JkXzEyMw==', $sanitizedHeader);
        $this->assertStringContainsString('Authorization: Basic ***REDACTED***', $sanitizedHeader);

        $url = 'cURL error 28: Connection to https://personnel:my_raw_wp_pass@wp.example.com/wp-json timed out';
        $sanitizedUrl = SecretSanitizer::sanitize($url);

        $this->assertStringNotContainsString('my_raw_wp_pass', $sanitizedUrl);
        $this->assertStringContainsString('https://personnel:***REDACTED***@wp.example.com', $sanitizedUrl);
    }

    public function test_secret_sanitizer_scrubs_passwords_in_json_and_urls(): void
    {
        $json = '{"error":"invalid_request","password":"my_super_app_password_999","user":"admin"}';
        $sanitizedJson = SecretSanitizer::sanitize($json);

        $this->assertStringNotContainsString('my_super_app_password_999', $sanitizedJson);
        $this->assertStringContainsString('"password":"***REDACTED***"', $sanitizedJson);

        $param = 'POST /login?user=admin&password=unmasked_query_password&remember=1';
        $sanitizedParam = SecretSanitizer::sanitize($param);

        $this->assertStringNotContainsString('unmasked_query_password', $sanitizedParam);
        $this->assertStringContainsString('password=***REDACTED***', $sanitizedParam);
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
            ],
        ];

        $sanitized = SecretSanitizer::sanitizeArray($payload);

        $this->assertSame('***REDACTED***', $sanitized['sensitive_keys']['password']);
        $this->assertSame('***REDACTED***', $sanitized['sensitive_keys']['access_token']);
        $this->assertSame('***REDACTED***', $sanitized['sensitive_keys']['app_password']);
        $this->assertSame('wordpress', $sanitized['safe_metadata']['channel']);
        $this->assertStringNotContainsString('EAAP_nested_query_token_123', $sanitized['message']);
        $this->assertStringContainsString('access_token=***REDACTED***', $sanitized['message']);
    }

    public function test_secret_sanitizer_handles_null_numbers_and_safe_text_without_corruption(): void
    {
        $this->assertSame('', SecretSanitizer::sanitize(''));
        $this->assertSame('Clean error message without secrets', SecretSanitizer::sanitize('Clean error message without secrets'));
        $this->assertSame(['key' => 'value'], SecretSanitizer::sanitizeArray(['key' => 'value']));
    }

    public function test_sanitizer_scrubs_secrets_before_persisting_publish_attempts_and_events(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['author_id' => $user->id]);
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

        $event = PublishEvent::create([
            'post_id' => $post->id,
            'publish_target_id' => $target->id,
            'channel' => 'facebook',
            'level' => 'error',
            'event_type' => 'publish_failed',
            'message' => SecretSanitizer::sanitize($leakedMessage),
            'context' => SecretSanitizer::sanitizeArray($leakedMetadata),
        ]);

        $attempt = PublishAttempt::create([
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
```

---

## 4. Milestone 1 Test Specification 2: `IntegrationHealthCheckTest`

### 4.1 Target Test Location
- **File**: `tests/Feature/IntegrationHealthCheckTest.php`
- **Namespace**: `Tests\Feature`
- **Base Class**: `Tests\TestCase` with `Illuminate\Foundation\Testing\RefreshDatabase`

### 4.2 Required Health Probe Contract Specification

According to `PROJECT.md:73-74` and survey requirements:
```php
namespace App\Services;

class IntegrationSettingsService
{
    /**
     * @return array{
     *     status: 'healthy'|'unhealthy',
     *     page_id: ?string,
     *     page_name: ?string,
     *     expires_at: ?string,
     *     permissions: array<string>,
     *     error: ?string
     * }
     */
    public function checkFacebookHealth(): array;

    /**
     * @return array{
     *     status: 'healthy'|'unhealthy',
     *     user: ?string,
     *     can_publish: bool,
     *     roles: array<string>,
     *     error: ?string
     * }
     */
    public function checkWordPressHealth(): array;
}
```

### 4.3 Required Test Scenarios Matrix

| # | Test Method Name | Channel | Fake HTTP Setup | Target Assertions |
|---|---|---|---|---|
| 1 | `test_facebook_health_check_healthy_with_valid_token_and_page_tasks` | Facebook | HTTP 200 `/{page_id}` with `id,name,tasks` (`CREATE_CONTENT`) | `status === 'healthy'`, `error === null`, `permissions` populated |
| 2 | `test_facebook_health_check_uses_bearer_header_instead_of_query_param` | Facebook | HTTP 200 with inspectable request | `$request->hasHeader('Authorization', 'Bearer <token>')`, URL query string does NOT contain `access_token` |
| 3 | `test_facebook_health_check_unhealthy_when_token_is_expired_code_190` | Facebook | HTTP 400 with FB code 190, subcode 463 | `status === 'unhealthy'`, `error` contains session expired message |
| 4 | `test_facebook_health_check_unhealthy_when_permissions_are_missing_code_200` | Facebook | HTTP 403 with FB code 200 or empty tasks array | `status === 'unhealthy'`, `error` mentions insufficient permissions |
| 5 | `test_facebook_health_check_unhealthy_when_page_id_does_not_match` | Facebook | HTTP 200 returning different `id` from configured `page_id` | `status === 'unhealthy'`, `error` flags page mismatch |
| 6 | `test_facebook_health_check_unhealthy_on_connection_timeout` | Facebook | `ConnectionException('Connection timed out')` | `status === 'unhealthy'`, no uncaught crash, `error` describes timeout |
| 7 | `test_facebook_health_check_unhealthy_when_credentials_not_configured` | Facebook | Empty settings in database | `status === 'unhealthy'`, `Http::assertNothingSent()` |
| 8 | `test_wordpress_health_check_healthy_with_valid_credentials_and_publish_capability` | WordPress | HTTP 200 `/wp-json/wp/v2/users/me` with `capabilities.publish_posts = true` | `status === 'healthy'`, `can_publish === true`, `user` populated, `error === null` |
| 9 | `test_wordpress_health_check_unhealthy_when_password_is_invalid_401` | WordPress | HTTP 401 with `code: 'rest_not_logged_in'` | `status === 'unhealthy'`, `can_publish === false`, `error` mentions unauthorized |
| 10 | `test_wordpress_health_check_unhealthy_when_user_cannot_publish_posts` | WordPress | HTTP 200 with `capabilities.publish_posts = false` (subscriber) | `status === 'unhealthy'`, `can_publish === false`, `error` mentions missing publish rights |
| 11 | `test_wordpress_health_check_unhealthy_on_server_error_500` | WordPress | HTTP 500 Internal Server Error | `status === 'unhealthy'`, `error` reflects server error |
| 12 | `test_wordpress_health_check_unhealthy_on_connection_timeout` | WordPress | `ConnectionException('Could not resolve host')` | `status === 'unhealthy'`, no uncaught crash, `error` reflects connection issue |
| 13 | `test_wordpress_health_check_unhealthy_when_credentials_not_configured` | WordPress | Missing URL or password | `status === 'unhealthy'`, `Http::assertNothingSent()` |
| 14 | `test_health_checks_update_database_model_status_and_timestamps` | Both | Trigger health checks | `IntegrationSetting.status` updated to `'connected'` or `'error'`; `last_checked_at` populated |
| 15 | `test_health_check_caching_and_cache_invalidation_on_settings_update` | Both | Consecutive checks + settings update | Cache prevents duplicate HTTP requests within 5 minutes; updating settings invalidates cache |

### 4.4 Concrete Test Class Blueprint (`tests/Feature/IntegrationHealthCheckTest.php`)

```php
<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Services\IntegrationSettingsService;
use App\Services\Publishers\FacebookPublisher;
use App\Services\Publishers\WordPressPublisher;
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

    public function test_facebook_health_check_healthy_with_valid_token_and_page_tasks(): void
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
                'tasks' => ['CREATE_CONTENT', 'MANAGE', 'MODERATE'],
            ], 200),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('healthy', $result['status']);
        $this->assertNull($result['error']);
        $this->assertSame('128159403529342', $result['page_id']);
        $this->assertSame('Official Poster Page', $result['page_name']);
        $this->assertContains('CREATE_CONTENT', $result['permissions']);
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
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Permissions error: requires pages_manage_posts',
                    'type' => 'OAuthException',
                    'code' => 200,
                ],
            ], 403),
        ]);

        $result = $this->service->checkFacebookHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertTrue(
            str_contains($result['error'], '200') ||
            str_contains($result['error'], 'permission') ||
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
            str_contains($result['error'], 'หมดเวลา')
        );
    }

    public function test_facebook_health_check_unhealthy_when_credentials_not_configured(): void
    {
        IntegrationSetting::query()->where('channel', 'facebook')->delete();

        $result = $this->service->checkFacebookHealth();

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

        $result = $this->service->checkWordPressHealth();

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
        $user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'secret-app-pass'],
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 1,
                'name' => 'Admin',
                'roles' => ['administrator'],
                'capabilities' => ['publish_posts' => true],
            ], 200),
        ]);

        // First manual check via controller
        $this->actingAs($user)->post(route('settings.integrations.check', 'wordpress'));
        Http::assertSentCount(1);

        // Access integrations page (should use cached result without re-fetching)
        $this->actingAs($user)->get(route('settings.integrations'));
        Http::assertSentCount(1);

        // Updating settings invalidates cache
        $this->actingAs($user)->post(route('settings.integrations.update'), [
            'wordpress_url' => 'https://new-wp.example.com',
            'wordpress_username' => 'new_editor',
            'wordpress_password' => 'new_secret_password',
        ]);

        $this->assertFalse(Cache::has('integrations.wordpress.health'));
    }
}
```

---

## 5. Architectural Alignment & Implementer Guidance

### 5.1 Backward Compatibility Safeguards
1. **`PublisherInterface` & `IntegrationController`**:
   - `WordPressPublisher::healthCheck(): bool` and `FacebookPublisher::healthCheck(): bool` should be maintained. Internally, each can delegate:
     ```php
     public function healthCheck(): bool
     {
         $result = app(IntegrationSettingsService::class)->checkWordPressHealth();
         return $result['status'] === 'healthy';
     }
     ```
   - This ensures existing routes (`/settings/integrations/check/{channel}`) and existing tests (`IntegrationSettingsTest.php`) continue to pass with zero breakage.
2. **`IntegrationSetting` Model**:
   - Add `protected $hidden = ['encrypted_credentials'];`.
   - No modifications to database columns in `integration_settings` table are required.
3. **`SecretSanitizer` Placement**:
   - Place in `app/Support/SecretSanitizer.php` as a static utility.
   - Inject into `PublishFailureClassifier`, `PublishAttempt`, and `PublishEvent` listeners.
4. **Facebook Bearer Authorization**:
   - In `FacebookPublisher::pageAccessToken()` and Graph API HTTP calls, replace `'access_token' => $this->accessToken` in query parameters with `Http::withToken($this->accessToken)->...`.
   - If Facebook's batch endpoints or legacy endpoints require query string fallback, ensure all logging layers route through `SecretSanitizer::sanitize()`.
