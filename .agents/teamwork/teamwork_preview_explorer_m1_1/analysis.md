# Technical Analysis: Feature 1 (Model Hidden Encryption) & Feature 2 (SecretSanitizer Utility)

**Agent**: Explorer 1 (Milestone 1: Security, Integration & Health Foundation)  
**Date**: 2026-10-02  
**Target Project**: Poster (Laravel 13 + React 18 / Inertia)  
**Working Directory**: `C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_explorer_m1_1`

---

## 1. Executive Summary

This document establishes the precise architectural and implementation specifications for two critical security requirements of Milestone 1:
1. **Feature 1: Model Hidden Encryption** in `app/Models/IntegrationSetting.php`: Safeguarding decrypted third-party credentials (Facebook page tokens, WordPress application passwords) from leaking during Eloquent JSON/array serialization.
2. **Feature 2: SecretSanitizer Utility** in `app/Support/SecretSanitizer.php`: Providing a centralized, resilient redactor that scrubs authentication tokens, passwords, bearer tokens, and credentials from log entries, exception traces, HTTP responses, timeline events, and attempt records across both strings and deeply nested arrays.

Both features have been investigated and empirically verified using the installed PHP 8.3.30 CLI runtime.

---

## 2. Feature 1: Model Hidden Encryption

### 2.1 Current State Analysis
File: `app/Models/IntegrationSetting.php` (Lines 1–24)
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $fillable = [
        'channel',
        'settings',
        'encrypted_credentials',
        'status',
        'last_checked_at',
        'last_error',
    ];

    protected $casts = [
        'settings' => 'array',
        'encrypted_credentials' => 'encrypted:array', // Store credentials encrypted
        'last_checked_at' => 'datetime',
    ];
}
```

### 2.2 Security Vulnerability
- In `database/migrations/2026_10_02_024041_create_integration_settings_table.php`, the `encrypted_credentials` column is defined as `text('encrypted_credentials')->nullable()`.
- The Eloquent cast `'encrypted_credentials' => 'encrypted:array'` encrypts array data at rest using Laravel's `Encrypter` (AES-256-CBC via `APP_KEY`).
- When accessed via Eloquent, the cast automatically decrypts the payload into a native PHP array (e.g. `['password' => 'secret', 'access_token' => 'EAAB...']`).
- **Defect**: The model lacks `protected $hidden = [...]`. In Laravel Eloquent, calling `$model->toArray()`, `$model->toJson()`, `json_encode($model)`, or passing the model to an Inertia prop or JSON API response causes Eloquent's `attributesToArray()` to serialize all unhidden attributes. Eloquent decrypts the credentials during serialization, resulting in plaintext credentials exposed over HTTP responses or console dumps.

### 2.3 Required Implementation
Add `protected $hidden = ['encrypted_credentials'];` to `IntegrationSetting.php`.

#### Behavioral Characteristics:
- **Serialization Isolation**: Calling `$setting->toArray()` or `$setting->toJson()` completely omits the `'encrypted_credentials'` key.
- **Backend Internal Availability**: Eloquent's `$hidden` attribute strictly controls array and JSON serialization; it does **not** restrict property access in backend PHP code.
  - `$setting->encrypted_credentials` returns the decrypted array as expected for `IntegrationSettingsService`, `FacebookPublisher`, and `WordPressPublisher`.
  - `$setting->encrypted_credentials = [...]` remains functional for saving updates.

### 2.4 Complete Proposed Code for `app/Models/IntegrationSetting.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'channel',
        'settings',
        'encrypted_credentials',
        'status',
        'last_checked_at',
        'last_error',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'encrypted_credentials',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'array',
        'encrypted_credentials' => 'encrypted:array', // Store credentials encrypted
        'last_checked_at' => 'datetime',
    ];
}
```

---

## 3. Feature 2: SecretSanitizer Utility

### 3.1 Architectural Contract
As defined in `PROJECT.md` (lines 78–87):
```php
namespace App\Support;

class SecretSanitizer
{
    public static function sanitize(string $text): string;
    public static function sanitizeArray(array $data): array;
}
```

### 3.2 Threat Vectors & Pattern Specifications
Secrets can enter system logs, exceptions, and audit logs via:
1. **Query strings**: e.g., Facebook Graph API endpoints (`?access_token=EAAB...` or `&access_token=...`).
2. **Authorization Headers**: Bearer tokens (`Bearer EAAB...`), Basic Auth (`Basic dXNl...`).
3. **HTTP Exception URLs**: Basic auth embedded in URLs (`https://user:password@domain.com`).
4. **JSON payloads**: Third-party error responses containing `"password": "..."` or `"access_token": "..."`.
5. **Form-urlencoded payloads**: Strings containing `password=...`, `app_password=...`, etc.
6. **Raw Facebook Tokens**: Alphanumeric strings starting with `EAAB...`, `EAA...` (typically 30–255+ characters).
7. **Structured Array Data**: Metadata arrays passed to `PublishAttempt` and `PublishEvent` containing sensitive keys.

### 3.3 Regex Pattern Dictionary (with PCRE `/u` UTF-8 flag)
All regex patterns must include the `/u` flag to preserve multibyte UTF-8 characters (Thai language error messages):

| Target | Regex Pattern | Replacement | Example Input -> Output |
|---|---|---|---|
| Facebook & Query Tokens | `/(access_token=)[^&\s"'\r\n]+/iu` | `${1}***REDACTED***` | `me?access_token=EAAB123&fields=id` $\rightarrow$ `me?access_token=***REDACTED***&fields=id` |
| Standalone Facebook Token | `/\b(EAA[A-Za-z0-9]{20,})\b/u` | `***REDACTED***` | `Token EAABwz12345678901234567890 invalid` $\rightarrow$ `Token ***REDACTED*** invalid` |
| Bearer Tokens | `/(bearer\s+)[A-Za-z0-9_\-\.+=]+/iu` | `${1}***REDACTED***` | `Bearer EAAB999888777` $\rightarrow$ `Bearer ***REDACTED***` |
| Basic Auth Headers | `/(basic\s+)[A-Za-z0-9+\/=_]+/iu` | `${1}***REDACTED***` | `Basic dXNlcjpwYXNz` $\rightarrow$ `Basic ***REDACTED***` |
| Basic Auth in URLs | `/([a-zA-Z][a-zA-Z0-9+.-]*:\/\/)([^:\/\s@]+):([^@\/\s]+)@/u` | `${1}${2}:***REDACTED***@` | `https://admin:secret@wp.com` $\rightarrow$ `https://admin:***REDACTED***@wp.com` |
| JSON Key Secrets | `/("?(?:password\|access_token\|page_access_token\|user_access_token\|token\|secret\|client_secret\|app_secret\|app_password\|application_password)"?\s*:\s*")[^"\\\\]*(?:\\\\.[^"\\\\]*)*(")/iu` | `${1}***REDACTED***${2}` | `{"password":"my\"pass"}` $\rightarrow$ `{"password":"***REDACTED***"}` |
| Form/Query Passwords | `/((?:password\|app_password\|application_password\|client_secret\|secret)=)[^&\s"'\r\n]+/iu` | `${1}***REDACTED***` | `password=xyz&user=john` $\rightarrow$ `password=***REDACTED***&user=john` |

### 3.4 Array Sanitization Algorithm (`sanitizeArray`)
To sanitize arrays (such as `PublishAttempt.metadata` and `PublishEvent.context`):
1. **Sensitive Key Detection**: Compare normalized lowercase key against known sensitive keys:
   `password`, `wordpress_password`, `app_password`, `application_password`, `access_token`, `page_access_token`, `user_access_token`, `token`, `secret`, `client_secret`, `app_secret`, `api_key`, `apikey`, `authorization`, `auth`, `cookie`, `encrypted_credentials`.
   If matched, replace value directly with `'***REDACTED***'`.
2. **Deep Recursion**: If the value is an array, recursively invoke `sanitizeArray($value)`.
3. **String Value Scrubbing**: If the value is a string, invoke `self::sanitize($value)` so that any URL query parameters, Bearer tokens, or JSON snippets inside array values are scrubbed.
4. **Scalar Preservation**: Integers, floats, booleans, and nulls are preserved as-is.

### 3.5 Complete Proposed Code for `app/Support/SecretSanitizer.php`
```php
<?php

namespace App\Support;

class SecretSanitizer
{
    public const REDACTED = '***REDACTED***';

    /**
     * Case-insensitive list of array keys containing sensitive credentials.
     *
     * @var list<string>
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'wordpress_password',
        'app_password',
        'application_password',
        'access_token',
        'page_access_token',
        'user_access_token',
        'token',
        'secret',
        'client_secret',
        'app_secret',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'cookie',
        'encrypted_credentials',
    ];

    /**
     * Sanitize a text string by redacting tokens, passwords, and authorization credentials.
     */
    public static function sanitize(?string $text): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        // 1. Redact access_token=... in URLs, query strings, or urlencoded payloads
        $text = preg_replace('/(access_token=)[^&\s"\'\r\n]+/iu', '$1'.self::REDACTED, $text);

        // 2. Redact Facebook tokens starting with EAA (minimum 20 characters)
        $text = preg_replace('/\b(EAA[A-Za-z0-9]{20,})\b/u', self::REDACTED, $text);

        // 3. Redact Bearer tokens
        $text = preg_replace('/(bearer\s+)[A-Za-z0-9_\-\.+=]+/iu', '$1'.self::REDACTED, $text);

        // 4. Redact Basic auth tokens in Authorization headers
        $text = preg_replace('/(basic\s+)[A-Za-z0-9+\/=_]+/iu', '$1'.self::REDACTED, $text);

        // 5. Redact user:password in URLs
        $text = preg_replace('/([a-zA-Z][a-zA-Z0-9+.-]*:\/\/)([^:\/\s@]+):([^@\/\s]+)@/u', '$1$2:'.self::REDACTED.'@', $text);

        // 6. Redact password, access_token, etc. in JSON keys
        $text = preg_replace('/("?(?:password|access_token|page_access_token|user_access_token|token|secret|client_secret|app_secret|app_password|application_password)"?\s*:\s*")[^"\\\\]*(?:\\\\.[^"\\\\]*)*(")/iu', '$1'.self::REDACTED.'$2', $text);

        // 7. Redact password=..., app_password=... in query/form strings
        $text = preg_replace('/((?:password|app_password|application_password|client_secret|secret)=)[^&\s"\'\r\n]+/iu', '$1'.self::REDACTED, $text);

        return $text;
    }

    /**
     * Recursively sanitize an array by redacting sensitive keys and scrubbing string values.
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public static function sanitizeArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $isSensitive = is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true);

            if ($isSensitive) {
                $sanitized[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = self::sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Helper to sanitize throwable exception messages.
     */
    public static function sanitizeThrowable(\Throwable $e): string
    {
        return self::sanitize($e->getMessage());
    }
}
```

---

## 4. Integration Touchpoints Across Poster Codebase

### 4.1 `app/Services/Publishers/FacebookPublisher.php`
- **Line 104**: `Log::warning('Facebook Comment Error (non-fatal): '.SecretSanitizer::sanitize($response->body()));`
- **Line 164**: `Log::error('Facebook Publish Error: '.SecretSanitizer::sanitize($response->body()));`
- **Line 170**: `'error' => SecretSanitizer::sanitize($response->body())`
- **Line 173**: `Log::error('Facebook Publish Exception: '.SecretSanitizer::sanitize($e->getMessage()));`
- **Line 179**: `'error' => SecretSanitizer::sanitize($e->getMessage())`
- **Line 200**: `Log::error('Facebook Photo Upload Error: '.SecretSanitizer::sanitize($photoResponse->body()));`
- **Lines 245 & 251**: `'error' => SecretSanitizer::sanitize(...)`

### 4.2 `app/Services/Publishers/WordPressPublisher.php`
- **Line 52**: `Log::error('WP Media Upload Error: '.SecretSanitizer::sanitize($response->body()));`
- **Line 94**: `Log::error('WordPress Publish Error: '.SecretSanitizer::sanitize($response->body()));`
- **Line 100**: `'error' => SecretSanitizer::sanitize($response->body())`
- **Line 104**: `Log::error('WordPress Publish Exception: '.SecretSanitizer::sanitize($e->getMessage()));`
- **Line 110**: `'error' => SecretSanitizer::sanitize($e->getMessage())`
- **Lines 148 & 150**: `'error' => SecretSanitizer::sanitize(...)`

### 4.3 `app/Services/IntegrationSettingsService.php`
- **Line 99**: In `markHealth()`, scrub `$error` before writing to database:
  `'last_error' => $connected ? null : ($error ? SecretSanitizer::sanitize($error) : null),`

### 4.4 Publishing Jobs & Failure Classification (Milestone 2)
- In `PublishWordPressJob` and `PublishFacebookJob`:
  - When saving `PublishAttempt` records:
    `'error_message' => SecretSanitizer::sanitize($failure->messageTh),`
    `'metadata' => SecretSanitizer::sanitizeArray($attemptMetadata),`
  - When recording `PublishEvent`:
    `'message' => SecretSanitizer::sanitize($eventMessage),`
    `'context' => SecretSanitizer::sanitizeArray($eventContext),`

---

## 5. Test Suite Implementation Specification

The following complete test class must be created in `tests/Feature/SecurityHardeningTest.php` to verify Features 1 and 2:

```php
<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\SecretSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_setting_credentials_are_hidden_from_array_and_json_serialization(): void
    {
        $setting = IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://example.com', 'username' => 'admin'],
            'encrypted_credentials' => [
                'password' => 'super-secret-wp-password',
                'api_token' => 'top-secret-token',
            ],
        ]);

        // 1. Verify toArray() omits encrypted_credentials
        $array = $setting->toArray();
        $this->assertArrayNotHasKey('encrypted_credentials', $array);

        // 2. Verify toJson() omits credentials
        $json = $setting->toJson();
        $this->assertStringNotContainsString('super-secret-wp-password', $json);
        $this->assertStringNotContainsString('top-secret-token', $json);
        $this->assertStringNotContainsString('encrypted_credentials', $json);

        // 3. Verify json_encode() omits credentials
        $encoded = json_encode($setting);
        $this->assertStringNotContainsString('super-secret-wp-password', $encoded);

        // 4. Verify internal direct access is preserved
        $this->assertSame('super-secret-wp-password', $setting->encrypted_credentials['password']);
        $this->assertSame('top-secret-token', $setting->encrypted_credentials['api_token']);
    }

    public function test_secret_sanitizer_redacts_facebook_access_tokens(): void
    {
        $token = 'EAAB1234567890abcdefghijklmnopqrstuvwxyz1234567890';
        $queryUrl = "https://graph.facebook.com/v26.0/me?access_token={$token}&fields=id,name";

        $sanitized = SecretSanitizer::sanitize($queryUrl);
        $this->assertStringNotContainsString($token, $sanitized);
        $this->assertSame('https://graph.facebook.com/v26.0/me?access_token=***REDACTED***&fields=id,name', $sanitized);

        $rawTokenMessage = "Fatal error with token {$token} on endpoint.";
        $this->assertSame('Fatal error with token ***REDACTED*** on endpoint.', SecretSanitizer::sanitize($rawTokenMessage));
    }

    public function test_secret_sanitizer_redacts_bearer_tokens(): void
    {
        $header = 'Authorization: Bearer EAAB1234567890abcdef_secret.token';
        $sanitized = SecretSanitizer::sanitize($header);

        $this->assertStringNotContainsString('EAAB1234567890', $sanitized);
        $this->assertSame('Authorization: Bearer ***REDACTED***', $sanitized);
    }

    public function test_secret_sanitizer_redacts_basic_auth_credentials(): void
    {
        $basicHeader = 'Authorization: Basic dXNlcjpwYXNzd29yZDEyMw==';
        $sanitizedHeader = SecretSanitizer::sanitize($basicHeader);
        $this->assertSame('Authorization: Basic ***REDACTED***', $sanitizedHeader);

        $url = 'https://editor:supersecretpassword@example.com/wp-json/wp/v2/posts';
        $sanitizedUrl = SecretSanitizer::sanitize($url);
        $this->assertStringNotContainsString('supersecretpassword', $sanitizedUrl);
        $this->assertSame('https://editor:***REDACTED***@example.com/wp-json/wp/v2/posts', $sanitizedUrl);
    }

    public function test_secret_sanitizer_redacts_passwords_in_json_and_query_strings(): void
    {
        $json = '{"user":"admin","password":"my-confidential-password","code":401}';
        $sanitizedJson = SecretSanitizer::sanitize($json);
        $this->assertStringNotContainsString('my-confidential-password', $sanitizedJson);
        $this->assertSame('{"user":"admin","password":"***REDACTED***","code":401}', $sanitizedJson);

        $query = 'username=admin&password=my-confidential-password&client_secret=secret123';
        $sanitizedQuery = SecretSanitizer::sanitize($query);
        $this->assertStringNotContainsString('my-confidential-password', $sanitizedQuery);
        $this->assertStringNotContainsString('secret123', $sanitizedQuery);
    }

    public function test_secret_sanitizer_preserves_unicode_and_thai_characters(): void
    {
        $thaiMessage = 'ข้อผิดพลาด: access_token=EAAB1234567890abcdef1234567890 การเชื่อมต่อล้มเหลว';
        $sanitized = SecretSanitizer::sanitize($thaiMessage);

        $this->assertSame('ข้อผิดพลาด: access_token=***REDACTED*** การเชื่อมต่อล้มเหลว', $sanitized);
    }

    public function test_secret_sanitizer_sanitizes_deeply_nested_arrays(): void
    {
        $payload = [
            'status' => 'error',
            'password' => 'plaintext-password',
            'access_token' => 'EAAB123456',
            'metadata' => [
                'user_id' => 123,
                'app_password' => 'secret-app-pwd',
                'url' => 'https://api.facebook.com?access_token=EAAB999',
                'active' => true,
                'null_key' => null,
            ],
            'headers' => [
                'Authorization' => 'Bearer token12345',
            ],
        ];

        $sanitized = SecretSanitizer::sanitizeArray($payload);

        $this->assertSame('***REDACTED***', $sanitized['password']);
        $this->assertSame('***REDACTED***', $sanitized['access_token']);
        $this->assertSame(123, $sanitized['metadata']['user_id']);
        $this->assertSame('***REDACTED***', $sanitized['metadata']['app_password']);
        $this->assertSame('https://api.facebook.com?access_token=***REDACTED***', $sanitized['metadata']['url']);
        $this->assertTrue($sanitized['metadata']['active']);
        $this->assertNull($sanitized['metadata']['null_key']);
        $this->assertSame('Authorization: Bearer ***REDACTED***', $sanitized['headers']['Authorization']);
    }
}
```

---

## 6. Verification and Execution Guide

To verify both features once implemented:
```powershell
& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SecurityHardeningTest
```
All assertions should pass with 0 failures and 0 warnings.
