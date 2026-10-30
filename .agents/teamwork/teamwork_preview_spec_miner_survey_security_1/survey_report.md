# Security, Integration Health, Error Mapping & Testing Specification Report

**Date**: 2026-10-02  
**Role**: Security, Health & Testing Spec Miner  
**Project**: Poster (Laravel 13 + React + Inertia)  
**Document Target**: `.agents/teamwork/teamwork_preview_spec_miner_survey_security_1/survey_report.md`  

---

## Executive Summary

This report establishes the authoritative specifications for **Security Hardening & Secrets Storage**, **Integration Health Checks (R3)**, **Thai Error Mapping & Classification (R7)**, and **Acceptance Testing Strategy with `Http::fake()` (R1, R5)** for the Poster application.

### Key Discoveries & Immediate Security Action Items
1. **Model Serialization Secret Leakage Risk**: `App\Models\IntegrationSetting` currently casts `encrypted_credentials` to `encrypted:array`, but **omits** `protected $hidden = ['encrypted_credentials'];`. Any future API controller or debug serializer calling `toJson()` or `toArray()` on `IntegrationSetting` will automatically decrypt and leak plaintext passwords and tokens.
2. **Access Token in GET Query Parameter Risk**: `App\Services\Publishers\FacebookPublisher` sends `$this->accessToken` as a query parameter in `GET /me` and `GET /me/accounts`. If an HTTP error or uncaught exception occurs, standard HTTP client logging and exception stack traces expose the raw token in URLs. Recommendation: migrate all Facebook GET/POST calls to `Authorization: Bearer <token>` headers or redact query parameters.
3. **Raw Error Body Logging Risk**: `FacebookPublisher` and `WordPressPublisher` currently call `Log::error($response->body())`. If an upstream error payload contains tokens or credentials, they are logged in plain text. A dedicated secret sanitizer must be applied before writing to logs, `PublishAttempt.metadata`, or `PublishEvent.context`.
4. **Current Test Suite**: The test suite uses PHPUnit with SQLite in-memory (`:memory:`) and is fully operational. All 30 existing tests pass (106 assertions) when executed via the Laragon PHP 8.3 CLI (`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test`).

---

## Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Security | `IntegrationSetting` Encrypted Storage | Stores per-channel secrets encrypted at rest via Eloquent `encrypted:array` cast | `channel`, `settings` (JSON), `encrypted_credentials` (array) | Model instance with encrypted DB column | Missing `$hidden` allows leak during JSON serialization | `app/Models/IntegrationSetting.php:20` |
| 2 | Security | Secret Field Masking in UI | Hides plaintext password/token in Inertia props, exposing only boolean flags | DB credentials | `{password: '', password_configured: bool, access_token: '', token_configured: bool}` | None | `app/Http/Controllers/IntegrationController.php:52-71` |
| 3 | Security | Blank Secret Preservation | Submitting empty password/token during settings update preserves existing encrypted secret | Empty string or null in form payload | Unchanged `encrypted_credentials` | Validation rejection if non-nullable rules added | `app/Services/IntegrationSettingsService.php:51-87` |
| 4 | Security | Secrets Sanitizer / Redactor | Redacts access tokens, basic auth credentials, and passwords from logs/events | Raw string, error message, or payload | Sanitized string with `***REDACTED***` | Returns safe fallback string on malformed input | Security audit requirement |
| 5 | Health Check | Facebook Health Check (R3) | Verifies Facebook Page connectivity, token validity, expiration, and required permissions | `page_id`, `access_token`, `graph_version` | Boolean or detailed health DTO | Returns false/error on invalid token (code 190) or missing permissions | `FacebookPublisher::healthCheck()`, R3 |
| 6 | Health Check | WordPress Health Check (R3) | Verifies WordPress REST API reachability, Application Password, and `publish_posts` permission | `url`, `username`, `password` | Boolean or detailed health DTO | Returns false/error on 401 unauthorized, connection timeout | `WordPressPublisher::healthCheck()`, R3 |
| 7 | Health Check | Integration Health Cache & Invalidation | Caches health status for 5 minutes; busts cache on settings update or manual check | Channel name (`wordpress`, `facebook`) | Cached health boolean / status | Falls back to fresh probe on cache miss | `IntegrationController.php:32,105,131` |
| 8 | Error Mapping | Facebook Error Classification (R7) | Classifies Facebook Graph API errors into retryable vs non-retryable and maps to Thai messages | Error JSON (`code`, `error_subcode`, `type`, `message`) | Classified Result (`isRetryable`, `errorType`, `thaiMessage`) | Fallback to sanitized default Thai error | Graph API specification & R7 |
| 9 | Error Mapping | Facebook Code 190 Terminal Failure | Handles expired/invalid Facebook tokens by marking target as failed immediately with 0 auto-retries | Code 190, Subcode 460/463/467/458 | Non-retryable failure, Thai message: "เซสชัน Facebook หมดอายุ..." | Target status set to `failed` immediately | ORIGINAL_REQUEST.md AC 2 |
| 10 | Error Mapping | Facebook Permission Error (Code 200/283) | Detects lack of `pages_manage_posts` or `pages_read_engagement` and fails without retrying | Code 10, 200, 283 | Non-retryable failure, Thai: "สิทธิ์ Facebook ไม่เพียงพอ..." | Target status set to `failed` immediately | Graph API specification & R7 |
| 11 | Error Mapping | Facebook Rate Limiting (Code 4/17/32/613) | Detects API throttle limits and marks failure as retryable with exponential backoff | Code 4, 17, 32, 613 | Retryable failure, Thai: "เกินขีดจำกัดการเรียกใช้งาน..." | Job released back to queue with backoff delay | Graph API specification & R1 |
| 12 | Error Mapping | WordPress REST Error Classification | Maps WordPress REST errors (`rest_not_logged_in`, `rest_cannot_create`, 500) to Thai messages | WP Error JSON (`code`, `message`, `data.status`) | Classified Result (`isRetryable`, `errorType`, `thaiMessage`) | Fallback to sanitized default Thai error | WordPress REST API spec & R1 |
| 13 | Reliability | WordPress Job Idempotency | Ensures retry with existing `external_id` updates `/posts/{id}` rather than `/posts` | `Post`, `PublishTarget` with `external_id` | Remote post updated, same `external_id` returned | Non-retryable if post deleted remotely | `WordPressPublisher::update()`, AC 1 |
| 14 | Reliability | Facebook Job Idempotency | Ensures retry with existing `external_id` updates `/{external_id}` rather than `/{page_id}/feed` | `Post`, `PublishTarget` with `external_id` | Remote post updated, same `external_id` returned | Non-retryable if post deleted remotely | `FacebookPublisher::update()`, AC 1 |
| 15 | Observability | Publish Attempt & Event Tracking | Records structured publishing attempts and audit events in database | Attempt metadata, duration, error details | `PublishAttempt`, `PublishEvent` rows | Null correlation allowed, timestamps tracked | `app/Models/PublishAttempt.php`, `PublishEvent.php` |

---

## Edge Cases

| # | Feature | Input | Observed / Specified Behavior |
|---|---------|-------|-------------------------------|
| 1 | Settings Serialization | `$setting->toJson()` called directly | Without `$hidden = ['encrypted_credentials']`, Eloquent decrypts and outputs raw password/token. With `$hidden`, field is completely omitted from JSON. |
| 2 | Secret Preservation | User updates WP URL but leaves password empty `''` | `updateWordPress` keeps existing encrypted password; does NOT overwrite with empty string. |
| 3 | Facebook User vs Page Token | User supplies User Access Token with `pages_manage_posts` | `pageAccessToken()` fetches `/me/accounts` and exchanges for target Page Access Token automatically. |
| 4 | Facebook Page Not Managed | Access token does not have access to configured `page_id` | `pageAccessToken()` throws `RuntimeException('The configured Facebook Page is not available...')`. Classified as non-retryable permission error. |
| 5 | Facebook Media Validation | User attempts to attach PDF to Facebook target | Facebook photo endpoint rejects PDF (error code 100, subcode 1349125). UI MediaPicker must filter out PDF; publisher falls back to text or rejects with Thai error. |
| 6 | Idempotency on Deleted Post | `external_id` exists in DB but post was deleted on Facebook/WP | WP returns 404; FB returns code 100/subcode 33. Must be classified as non-retryable error, target marked failed. |
| 7 | Transient Network Timeout | cURL error 28 / `Illuminate\Http\Client\ConnectionException` | Classified as RETRYABLE. Attempt recorded, job released with exponential backoff delay. |
| 8 | Facebook Code 190 Subcode 463 | Access token expired after 60 days | Classified as NON-RETRYABLE. Target marked `failed`, `retryable = false`, 0 auto-retries, Thai message: "เซสชัน Facebook หมดอายุ กรุณาเชื่อมต่อบัญชีใหม่". |
| 9 | WordPress 401 Invalid App Password | Incorrect Application Password configured | WordPress returns `rest_not_logged_in`. Classified as NON-RETRYABLE. Target marked `failed`, 0 auto-retries. |
| 10 | Error Response with Token | FB error message contains `access_token=EAAB...` query parameter | Secret scrubber replaces token with `***REDACTED***` before writing to `PublishEvent`, `PublishAttempt`, or log file. |

---

## Section 1: Integration Settings & Secrets Storage Security Specification

### 1.1 Architecture & Flow
```
User (Browser)
   │  (Form Submit: password, access_token)
   ▼
IntegrationController::update()
   │  (Validates inputs: nullable string)
   ▼
IntegrationSettingsService::updateWordPress() / updateFacebook()
   │  (Keeps existing secret if input is empty)
   ▼
IntegrationSetting Model ($casts = ['encrypted_credentials' => 'encrypted:array'])
   │  (AES-256-CBC encrypted via APP_KEY)
   ▼
Database `integration_settings` table (Encrypted ciphertext at rest)
   ▲
   │  (Decrypted on demand in Job/Service only)
PublishWordPressJob / PublishFacebookJob -> Publisher Class
```

### 1.2 Identified Security Vulnerabilities & Mandated Rules

#### Rule S1: Eloquent Model Hidden Attribute
- **Vulnerability**: `app/Models/IntegrationSetting.php` does not declare `$hidden`. If any controller or response accidentally includes an `IntegrationSetting` model, the decrypted secrets are serialized into JSON.
- **Mandate**: In `app/Models/IntegrationSetting.php`, add:
  ```php
  protected $hidden = [
      'encrypted_credentials',
  ];
  ```

#### Rule S2: HTTP Request Authorization Header vs Query Parameter
- **Vulnerability**: In `FacebookPublisher::pageAccessToken()`, the code calls:
  ```php
  Http::get($this->graphUrl('me'), [
      'fields' => 'id',
      'access_token' => $this->accessToken, // <-- In query parameter!
  ]);
  ```
  Query parameters appear in URLs, web server access logs, and HTTP client exception messages.
- **Mandate**: Pass the access token via the `Authorization` header instead of query parameters:
  ```php
  Http::withToken($this->accessToken)
      ->connectTimeout(5)
      ->timeout(10)
      ->get($this->graphUrl('me'), ['fields' => 'id']);
  ```
  Or if Facebook Graph API endpoint requires query parameter (some older versions), ensure all URL loggers strip query string parameters matching `access_token`.

#### Rule S3: Centralized Secrets Redactor / Sanitizer
- **Vulnerability**: `Log::error($response->body())` in publishers; `PublishAttempt.metadata` and `PublishEvent.context` saving raw responses.
- **Mandate**: Implement a dedicated `SecretSanitizer` utility (e.g. in `App\Support\SecretSanitizer` or inside `PublishFailureClassifier`):
  ```php
  namespace App\Support;

  class SecretSanitizer
  {
      public static function sanitize(mixed $content): mixed
      {
          if (is_array($content)) {
              return array_map([self::class, 'sanitize'], $content);
          }

          if (! is_string($content)) {
              return $content;
          }

          // Redact access_token=...
          $content = preg_replace('/(access_token=)[^&\s"\']+/i', '$1***REDACTED***', $content);

          // Redact Bearer tokens
          $content = preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]+/i', '$1***REDACTED***', $content);

          // Redact Basic auth tokens
          $content = preg_replace('/(Basic\s+)[A-Za-z0-9+\/=]+/i', '$1***REDACTED***', $content);

          // Redact app passwords or secrets if present
          $content = preg_replace('/("password"\s*:\s*")[^"]+(")/i', '$1***REDACTED***$2', $content);

          return $content;
      }
  }
  ```

#### Rule S4: Inertia & API Response Quarantine
- No Inertia prop or JSON API response may include decrypted passwords or access tokens.
- Return only boolean indicators:
  - `wordpress.password_configured: bool`
  - `facebook.token_configured: bool`
- Inputs for `wordpress_password` and `facebook_access_token` must default to empty strings in Vue/React forms, and the backend must preserve existing secrets if inputs are empty.

---

## Section 2: Integration Health Checks Specification (R3)

### 2.1 Facebook Health Check Specification

#### Probe Sequence
1. **Configured Check**: Check that `page_id` and `access_token` are non-empty. If empty, return status `disconnected`.
2. **Page & Token Verification**:
   - Call `GET https://graph.facebook.com/{version}/{page_id}?fields=id,name,tasks` using `withToken($pageAccessToken)`.
   - If User Token is provided, resolve Page Token via `GET /me/accounts` first.
3. **Permissions Inspection**:
   - Check that token has required permissions:
     * User Token permissions (`/me/permissions`): `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`.
     * Page Tasks (`tasks` array on page): `CREATE_CONTENT` or `MANAGE`.
4. **Token Expiration Inspection**:
   - If `/debug_token` is available: inspect `expires_at`.
   - In Page Access Tokens derived from Long-Lived User Tokens: `expires_at` is typically `0` (never expires).
   - If `expires_at > 0` and within 7 days of expiration, flag a warning in health check metadata.
5. **Output Structure**:
   ```php
   [
       'channel' => 'facebook',
       'status' => 'connected' | 'error' | 'disconnected',
       'connected' => bool,
       'page_id' => string,
       'page_name' => string|null,
       'permissions_valid' => bool,
       'missing_permissions' => string[],
       'expires_at' => Carbon|null,
       'is_never_expiring' => bool,
       'error_code' => int|null,
       'error_message' => string|null,
       'thai_message' => string|null,
       'last_checked_at' => Carbon,
   ]
   ```

### 2.2 WordPress Health Check Specification

#### Probe Sequence
1. **Configured Check**: Verify `url`, `username`, and `password` are non-empty. If empty, return status `disconnected`.
2. **REST API Reachability & Auth**:
   - Call `GET {url}/wp-json/wp/v2/users/me?context=edit` with Basic Auth (`username`, `password`).
   - Timeout: 5 seconds.
3. **Publishing Capability Verification**:
   - Verify HTTP 200 response.
   - Inspect user roles: verify user has role `administrator`, `editor`, or `author` (or check `capabilities.publish_posts === true`).
4. **Output Structure**:
   ```php
   [
       'channel' => 'wordpress',
       'status' => 'connected' | 'error' | 'disconnected',
       'connected' => bool,
       'user_id' => int|null,
       'username' => string|null,
       'roles' => string[],
       'can_publish' => bool,
       'error_code' => string|null,
       'error_message' => string|null,
       'thai_message' => string|null,
       'last_checked_at' => Carbon,
   ]
   ```

### 2.3 Health Check Execution & Caching Rules
- **Cache Strategy**: Cache successful health check results for **5 minutes** (`Cache::remember('integrations.{channel}.health', now()->addMinutes(5), ...)`).
- **Cache Invalidation**:
  - Automatically invalidated on `POST /settings/integrations` (settings update).
  - Force-busted on manual check `POST /settings/integrations/check/{channel}`.
  - Automatically invalidated when any publishing job encounters an authentication/token error (e.g. Facebook code 190 or WordPress 401).
- **Scheduled Background Check**: Provide an Artisan command `php artisan integrations:check-health` callable via Laravel Scheduler (e.g. hourly) to detect expired tokens proactively before users trigger publishing jobs.

---

## Section 3: Thai Error Mapping & Classification Specification (R7)

### 3.1 Failure Classification Architecture (`PublishFailureClassifier`)

Every publishing failure must be passed through a centralized `PublishFailureClassifier` before updating `PublishTarget`, logging to `PublishAttempt`, or emitting a `PublishEvent`.

```
Failed Job / HTTP Response / Throwable
               │
               ▼
PublishFailureClassifier::classify(string $channel, Throwable|Response $source)
               │
               ▼
      PublishFailureResult DTO:
      ├── isRetryable: bool
      ├── errorType: string (auth_required | permission_denied | rate_limited | network_error | validation | server_error | unknown)
      ├── errorCode: string|int|null
      ├── errorSubcode: int|null
      ├── rawMessage: string (sanitized)
      ├── thaiMessage: string (user-facing)
      └── backoffDelaySeconds: int
```

### 3.2 Facebook Graph API Complete Thai Error Dictionary

| Error Code | Error Subcode | Error Type | Retryable? | Thai Error Message (`thaiMessage`) | Action Required |
|---|---|---|---|---|---|
| **190** | `463` | `auth_required` | **NO** (0 retries) | เซสชัน Facebook หมดอายุ กรุณาต่ออายุหรือสร้าง Access Token ใหม่ที่เมนูการตั้งค่า | Reconnect Facebook account |
| **190** | `467` | `auth_required` | **NO** (0 retries) | Access Token ของ Facebook ไม่ถูกต้องหรือถูกเพิกถอน กรุณาเชื่อมต่อบัญชีใหม่ | Re-enter valid token |
| **190** | `460` | `auth_required` | **NO** (0 retries) | ผู้ใช้งานเปลี่ยนรหัสผ่าน Facebook ทำให้ Token เดิมใช้การไม่ได้ กรุณาเชื่อมต่อบัญชีใหม่ | Re-authenticate |
| **190** | `458` | `auth_required` | **NO** (0 retries) | แอปพลิเคชันยังไม่ได้รับอนุญาตหรือถูกยกเลิกการเข้าถึง Facebook กรุณาตั้งค่าใหม่อีกครั้ง | Authorize app permissions |
| **190** | null / any | `auth_required` | **NO** (0 retries) | เซสชัน Facebook หมดอายุหรือไม่ถูกต้อง กรุณาเชื่อมต่อบัญชีใหม่ | Reconnect Facebook |
| **10** | any | `permission_denied` | **NO** (0 retries) | แอปพลิเคชันไม่ได้รับอนุญาตให้ดำเนินการนี้บน Facebook | Grant Page app permissions |
| **200** | any | `permission_denied` | **NO** (0 retries) | สิทธิ์ Facebook ไม่เพียงพอ กรุณาตรวจสอบว่า Access Token มีสิทธิ์ pages_manage_posts และ pages_read_engagement | Grant missing permissions |
| **283** | any | `permission_denied` | **NO** (0 retries) | บัญชีนี้ไม่มีสิทธิ์จัดการโพสต์บน Facebook Page ที่เลือก | Verify Page admin access |
| **100** | `1349125` | `validation` | **NO** (0 retries) | รูปแบบไฟล์สื่อไม่ถูกต้อง Facebook รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, WebP) ไม่รองรับไฟล์ PDF | Upload valid image |
| **100** | `1349048` | `validation` | **NO** (0 retries) | ขนาดหรือมิติของรูปภาพไม่ถูกต้องตามข้อกำหนดของ Facebook | Resize image |
| **100** | any | `validation` | **NO** (0 retries) | พารามิเตอร์หรือข้อมูลโพสต์ไม่ถูกต้องตามข้อกำหนดของ Facebook | Check post payload |
| **368** | any | `policy_violation` | **NO** (0 retries) | Facebook ระงับการโพสต์ชั่วคราวเนื่องจากตรวจพบความถี่สูงหรือละเมิดนโยบายความปลอดภัย | Wait for cooldown |
| **4** | any | `rate_limited` | **YES** | เกินขีดจำกัดการเรียกใช้งาน Facebook API ชั่วคราว (Rate Limit) ระบบจะพยายามส่งใหม่โดยอัตโนมัติ | Exponential backoff |
| **17** | any | `rate_limited` | **YES** | ผู้ใช้เรียกใช้งาน Facebook API ถี่เกินไป ระบบจะพยายามส่งใหม่โดยอัตโนมัติ | Exponential backoff |
| **32** | any | `rate_limited` | **YES** | Page นี้เรียกใช้งาน Facebook API เกินขีดจำกัดชั่วคราว ระบบจะพยายามส่งใหม่อีกครั้ง | Exponential backoff |
| **613** | any | `rate_limited` | **YES** | อัตราการเรียก API สูงเกินกำหนด (Throttle) ระบบจะหน่วงเวลาและลองใหม่อีกครั้ง | Exponential backoff |
| **1** | any | `server_error` | **YES** | เซิร์ฟเวอร์ Facebook ขัดข้องชั่วคราว (API Error 1) ระบบจะพยายามส่งใหม่อีกครั้ง | Exponential backoff |
| **2** | any | `server_error` | **YES** | เกิดข้อผิดพลาดที่ไม่คาดคิดจากเซิร์ฟเวอร์ Facebook ระบบจะพยายามส่งใหม่อีกครั้ง | Exponential backoff |
| **500, 502, 503** | - | `server_error` | **YES** | เซิร์ฟเวอร์ Facebook ปิดปรับปรุงหรือขัดข้องชั่วคราว ระบบจะลองใหม่อีกครั้ง | Exponential backoff |
| Network / Timeout | - | `network_error` | **YES** | การเชื่อมต่อไปยัง Facebook ขัดข้องหรือหมดเวลา (Connection Timeout) ระบบจะลองใหม่อีกครั้ง | Exponential backoff |

### 3.3 WordPress REST API Complete Thai Error Dictionary

| Error Code / HTTP | Error Type | Retryable? | Thai Error Message (`thaiMessage`) | Action Required |
|---|---|---|---|---|
| `rest_not_logged_in` (401) | `auth_required` | **NO** (0 retries) | ไม่สามารถเข้าสู่ระบบ WordPress ได้ กรุณาตรวจสอบ Username และ Application Password | Verify credentials |
| `rest_forbidden` (401/403) | `permission_denied` | **NO** (0 retries) | ไม่ได้รับอนุญาตให้เข้าถึง WordPress REST API | Check API access |
| `rest_cannot_create` (403) | `permission_denied` | **NO** (0 retries) | บัญชีผู้ใช้ WordPress ไม่มีสิทธิ์สร้างบทความ (ต้องการสิทธิ์ Publish Posts) | Grant editor/author role |
| `rest_cannot_edit` (403) | `permission_denied` | **NO** (0 retries) | บัญชีผู้ใช้ WordPress ไม่มีสิทธิ์แก้ไขบทความนี้ | Grant post edit permissions |
| `rest_post_invalid_id` (404)| `not_found` | **NO** (0 retries) | ไม่พบบทความบน WordPress สำหรับการอัปเดต (อาจถูกลบไปแล้ว) | Publish as new post |
| `rest_upload_invalid_file_type` | `validation` | **NO** (0 retries) | ประเภทไฟล์สื่อไม่ได้รับอนุญาตใน WordPress | Check allowed file types |
| `rest_upload_file_error` | `validation` | **NO** (0 retries) | ไม่สามารถบันทึกไฟล์สื่อลงใน WordPress ได้ กรุณาตรวจสอบสิทธิ์โฟลเดอร์ uploads | Check server disk/perms |
| HTTP 500, 502, 503 (`internal_server_error`) | `server_error` | **YES** | เซิร์ฟเวอร์ WordPress ขัดข้องชั่วคราว (HTTP 500/502/503) ระบบจะลองส่งใหม่โดยอัตโนมัติ | Exponential backoff |
| Connection Timeout / Refused | `network_error` | **YES** | ไม่สามารถเชื่อมต่อไปยังเว็บไซต์ WordPress ได้ กรุณาตรวจสอบว่าเว็บไซต์เปิดใช้งานอยู่และ URL ถูกต้อง | Retry connection |

### 3.4 Exponential Backoff Schedule
For all retryable errors:
- **Maximum Attempts**: 3 tries (`public int $tries = 3;` in Job).
- **Backoff Formula**:
  $$\text{Delay}(n) = \min(2^n \times 10, 300) \text{ seconds}$$
  - Attempt 1 failure $\rightarrow$ delay 20 seconds.
  - Attempt 2 failure $\rightarrow$ delay 40 seconds.
  - Attempt 3 failure $\rightarrow$ terminal `failed` state.
- **Immediate Non-Retryable Error Handling**:
  If `isRetryable == false`:
  - Do NOT release job to queue.
  - Mark `PublishTarget` status directly as `'failed'`.
  - Record `PublishAttempt` with `status = 'failed'`, `retryable = false`.
  - Log `PublishEvent` with `level = 'error'`, `event_type = 'publish_failed'`.
  - Update post aggregate status via `PublishPostJob::updatePostStatus()`.

---

## Section 4: Testing & Test Suite Strategy

### 4.1 Current Test Environment Baseline
- **PHP Version**: PHP 8.3.30 (`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`)
- **Execution Command**:
  ```powershell
  & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test
  ```
- **Test Framework**: PHPUnit 11.5.46
- **Test Configuration**: `phpunit.xml` configuring SQLite `:memory:`, queue `sync`, cache `array`.
- **Current Status**: 30 tests, 30 passed, 106 assertions (Duration: ~31s).

### 4.2 Required `Http::fake()` Test Matrix & Acceptance Criteria Specifications

To fulfill the Acceptance Criteria in `ORIGINAL_REQUEST.md`, the following dedicated test classes must be created:

#### Test Class 1: `tests/Feature/PublishIdempotencyTest.php`
1. **WordPress Idempotency Test (Existing `external_id`)**:
   - **Setup**: Create Post, create WordPress `PublishTarget` with `status = 'pending'`, `external_id = '987'`, `external_url = 'https://wp.test/p/987'`.
   - **Http Fake**:
     ```php
     Http::fake([
         'https://wp.test/wp-json/wp/v2/posts/987' => Http::response(['id' => 987, 'link' => 'https://wp.test/p/987'], 200),
     ]);
     ```
   - **Execution**: Run `PublishWordPressJob` (or `WordPressPublisher::publish/update`).
   - **Assertion**:
     - `Http::assertSent(fn ($request) => $request->url() === 'https://wp.test/wp-json/wp/v2/posts/987' && $request->method() === 'POST');`
     - `Http::assertNotSent(fn ($request) => $request->url() === 'https://wp.test/wp-json/wp/v2/posts');`
     - Target status remains `published`, `external_id` remains `'987'`.

2. **Facebook Idempotency Test (Existing `external_id`)**:
   - **Setup**: Create Post, create Facebook `PublishTarget` with `status = 'pending'`, `external_id = '123456_7891011'`, `external_url = 'https://facebook.com/posts/7891011'`.
   - **Http Fake**:
     ```php
     Http::fake([
         'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '123456'], 200),
         'https://graph.facebook.com/v26.0/123456_7891011' => Http::response(['id' => '123456_7891011'], 200),
     ]);
     ```
   - **Execution**: Run `PublishFacebookJob` (or `FacebookPublisher::publish/update`).
   - **Assertion**:
     - `Http::assertSent(fn ($request) => str_contains($request->url(), '123456_7891011') && $request->method() === 'POST');`
     - `Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/feed'));`
     - Target status is `published`, `external_id` preserved.

3. **Fresh Publishing Test (Null `external_id`)**:
   - **Setup**: Target with `external_id = null`.
   - **Assertion**: Calls create endpoints (`/wp-json/wp/v2/posts` and `/{page_id}/feed`), updates target with returned ID.

#### Test Class 2: `tests/Feature/PublishFailureClassificationTest.php`
1. **Facebook Code 190 Non-Retryable Failure**:
   - **Setup**: Target `status = 'pending'`, 0 retries.
   - **Http Fake**:
     ```php
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
     ```
   - **Execution**: Dispatch job.
   - **Assertion**:
     - `PublishTarget` status is immediately `'failed'`.
     - `PublishTarget.error_message` contains `'เซสชัน Facebook หมดอายุ'`.
     - `PublishAttempt` has `retryable = false`, `error_code = 190`, `status = 'failed'`.
     - No queue release (`$job->release()`) occurs.

2. **Facebook Permission Denied (Code 200) Non-Retryable Failure**:
   - **Http Fake**: Returns Facebook code 200 with missing permissions error.
   - **Assertion**: Target marked `'failed'` immediately, Thai message contains `'สิทธิ์ Facebook ไม่เพียงพอ'`.

3. **Retryable Error (Facebook Code 4 Rate Limit)**:
   - **Http Fake**: Returns Facebook code 4 rate limit.
   - **Assertion**:
     - Classifier identifies error as retryable (`isRetryable === true`).
     - Target marked with backoff schedule / attempt recorded.

4. **WordPress 401 Unauthorized Non-Retryable Failure**:
   - **Http Fake**: Returns WP 401 `rest_not_logged_in`.
   - **Assertion**: Target marked `'failed'` immediately, Thai message contains `'ไม่สามารถเข้าสู่ระบบ WordPress ได้'`.

#### Test Class 3: `tests/Feature/SecurityHardeningTest.php`
1. **Model Hidden Decrypted Credentials Serialization Test**:
   - Create `IntegrationSetting` with encrypted credentials.
   - Call `$setting->toArray()` and `$setting->toJson()`.
   - Assert `array_key_exists('encrypted_credentials', $setting->toArray()) === false`.
   - Assert `str_contains($setting->toJson(), 'password') === false`.
   - Assert `str_contains($setting->toJson(), 'access_token') === false`.

2. **Plaintext Secrets Leakage Prevention in Logs & Timelines**:
   - Simulate a failed publish where the raw error string contains `access_token=EAABsecret123` and `Authorization: Basic dXNlcjpwYXNz`.
   - Verify that `PublishAttempt::first()->metadata` has tokens scrubbed to `***REDACTED***`.
   - Verify that `PublishEvent::first()->message` and `context` do NOT contain `secret123`.

3. **Integration Settings Controller Secret Quarantine**:
   - Access `GET /settings/integrations`.
   - Verify Inertia page props have `'wordpress.password' === ''` and `'facebook.access_token' === ''`.

---

## Conclusion & Architecture Roadmap

The security boundaries and specifications documented herein provide the exact blueprints required by:
1. **Backend Implementer**:
   - Add `protected $hidden = ['encrypted_credentials'];` to `IntegrationSetting.php`.
   - Implement `SecretSanitizer` in `app/Support/SecretSanitizer.php`.
   - Implement `PublishFailureClassifier` in `app/Services/Publishers/PublishFailureClassifier.php` using the Thai dictionary defined in Section 3.
   - Update `FacebookPublisher` and `WordPressPublisher` to sanitize logged errors, use Bearer token headers, and return rich health check metadata.
   - Update `PublishWordPressJob` and `PublishFacebookJob` to check `target->external_id` for idempotency and consult `PublishFailureClassifier` before queue retries.
2. **Frontend Implementer**:
   - Display `thaiMessage` directly on the Publishing Timeline and Target Status badges.
   - Respect secret field placeholders and mask token/password inputs.
3. **QA & Test Implementer**:
   - Implement `PublishIdempotencyTest.php`, `PublishFailureClassificationTest.php`, and `SecurityHardeningTest.php` according to Section 4.2 using `Http::fake()`.
