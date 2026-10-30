# Handoff Report: Security, Health & Testing Specification Mining

**Agent**: teamwork_preview_spec_miner_survey_security_1  
**Recipient**: parent (88ae952d-230f-4b30-8f2a-a0bea6f43ba1)  
**Date**: 2026-10-02T03:15:00Z  
**Type**: Hard Handoff (Task Complete)  

---

## 1. Observation

1. **Missing `$hidden` in `IntegrationSetting` Model**:
   - In `app/Models/IntegrationSetting.php`, lines 18-23:
     ```php
     protected $casts = [
         'settings' => 'array',
         'encrypted_credentials' => 'encrypted:array', // Store credentials encrypted
         'last_checked_at' => 'datetime',
     ];
     ```
     There is no `protected $hidden = ['encrypted_credentials'];`. Calling `toArray()` or `toJson()` on this model decrypts and exposes plain text credentials.
2. **Access Token Passed via GET Query Parameters**:
   - In `app/Services/Publishers/FacebookPublisher.php`, lines 44-50:
     ```php
     $meResponse = Http::connectTimeout(5)
         ->timeout(10)
         ->get($this->graphUrl('me'), [
             'fields' => 'id',
             'access_token' => $this->accessToken,
         ]);
     ```
     The token is exposed in the query string and can leak in HTTP client exception URLs and access logs.
3. **Raw Error Body Logged in Plain Text**:
   - In `app/Services/Publishers/FacebookPublisher.php`:
     - Line 164: `Log::error('Facebook Publish Error: '.$response->body());`
     - Line 173: `Log::error('Facebook Publish Exception: '.$e->getMessage());`
     - Line 200: `Log::error('Facebook Photo Upload Error: '.$photoResponse->body());`
   - In `app/Services/Publishers/WordPressPublisher.php`:
     - Line 52: `Log::error('WP Media Upload Error: '.$response->body());`
     - Line 94: `Log::error('WordPress Publish Error: '.$response->body());`
     - Line 104: `Log::error('WordPress Publish Exception: '.$e->getMessage());`
4. **Current Status of Test Execution & PHP Executable**:
   - System command `php artisan test` failed with:
     `php : The term 'php' is not recognized as the name of a cmdlet...`
   - Located Laragon PHP executable at `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.
   - Executed: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
   - Output:
     `{"tool":"phpunit","result":"passed","tests":30,"passed":30,"assertions":106,"duration_ms":31195}`
     All 30 existing tests passed cleanly.
5. **Existing Unimplemented Components**:
   - `app/Exceptions/PublishException.php` is an empty class (11 lines).
   - `app/Jobs/PublishWordPressJob.php` and `app/Jobs/PublishFacebookJob.php` are empty skeleton jobs (28 lines each).
   - `PublishFailureClassifier` does not exist yet anywhere in `app/`.
   - `PublishTarget.status` enum is `['pending', 'processing', 'published', 'failed']`.

---

## 2. Logic Chain

1. **Security Vulnerability Inference (Observation 1 & 2 & 3)**:
   - Observation 1 shows `IntegrationSetting` has no `$hidden` attribute.
   - Observation 2 shows `FacebookPublisher` transmits tokens in URLs.
   - Observation 3 shows publishers log raw response bodies and exception messages without redacting tokens.
   - `ORIGINAL_REQUEST.md` Acceptance Criteria explicitly mandates: *"No access tokens or passwords are sent to the frontend or logged in plain text."*
   - Therefore, remediation requires:
     * Adding `protected $hidden = ['encrypted_credentials'];` to `IntegrationSetting`.
     * Using Bearer token headers in Facebook Graph API calls.
     * Introducing a centralized `SecretSanitizer` to scrub sensitive strings before logging or saving into `PublishAttempt.metadata` and `PublishEvent.context`.
2. **Health Check Requirement Inference (Observation 5 & ORIGINAL_REQUEST.md R3)**:
   - R3 requires health checks for Facebook token expiration/permissions and WordPress connectivity.
   - Current health checks only perform rudimentary reachability (`/me` and `/users/me`).
   - Facebook tokens require permission verification (`pages_manage_posts`, `pages_read_engagement`) and expiration inspection (`expires_at` via debug or page inspection).
   - WordPress requires verification of `publish_posts` capability and authentication validity.
3. **Error Classification & Thai Mapping Inference (Observation 5 & ORIGINAL_REQUEST.md R7, AC 2)**:
   - Non-retryable errors like Facebook code 190 (expired token) or code 200 (missing permissions), and WordPress 401/403 must NOT be retried.
   - Retrying non-retryable errors wastes worker capacity and floods third-party APIs.
   - Therefore, a `PublishFailureClassifier` is needed to inspect error codes/subcodes and return:
     * `isRetryable = false` for codes 190, 10, 200, 283, 100, 368, WP 401, WP 403, marking target `failed` immediately.
     * `isRetryable = true` for rate limits (FB 4, 17, 32, 613), 500 server errors, and connection timeouts, applying exponential backoff: delay $(2^n \times 10)$ seconds.
     * Human-friendly Thai translation strings for user display (e.g. "เซสชัน Facebook หมดอายุ กรุณาต่ออายุหรือสร้าง Access Token ใหม่ที่เมนูการตั้งค่า").
4. **Testing Suite Strategy Inference (Observation 4 & ORIGINAL_REQUEST.md AC 1, AC 2)**:
   - The test environment is functional using `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test`.
   - To prove acceptance criteria, three new feature test classes are needed:
     * `PublishIdempotencyTest`: verifies retry with existing `external_id` updates `/posts/{id}` or `/{id}` and never calls create endpoints.
     * `PublishFailureClassificationTest`: verifies Facebook code 190 and WordPress 401 mark target failed immediately with 0 auto-retries.
     * `SecurityHardeningTest`: verifies model serialization excludes secrets and secret redactor cleanses log messages.

---

## 3. Caveats

1. **PHP CLI in Environment PATH**: The default shell `PATH` in PowerShell does not have `php` globally registered. All commands must use the absolute path `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"` or be executed within Laragon's terminal environment.
2. **Facebook App Secret Availability**: The current `.env` does not define `FACEBOOK_APP_SECRET`. Therefore, calling the `/debug_token` endpoint directly (which requires `app_id|app_secret` as access token) may not be available if only a User/Page token is configured. The health check must rely on `GET /{page_id}?fields=id,name,tasks` and `/me/permissions` when `FACEBOOK_APP_SECRET` is omitted.
3. **PublishTarget Status Enum vs Retrying**: `publish_targets.status` is currently an enum `['pending', 'processing', 'published', 'failed']`. If the UI or worker needs a distinct `'retrying'` status on `publish_targets`, a migration or interpretation using `status = 'pending'` with `retry_count > 0` must be finalized by the backend orchestration team.

---

## 4. Conclusion

All 5 investigation objectives have been fully researched and documented in `survey_report.md`.
The specifications provide clear contracts for:
1. **Security Hardening**: Model `$hidden` fix, Bearer header adoption, and `SecretSanitizer` regex pattern.
2. **Integration Health Checks**: Robust Facebook and WordPress probe sequences, permission verifications, and 5-minute cache policy.
3. **Thai Error Translation**: Complete dictionary covering Facebook error codes (190, 200, 100, 4, 17, 32, 613, 368) and WordPress error codes (401, 403, 404, 500, timeouts).
4. **Test Suite Strategy**: Concrete test matrices for idempotency (`Http::fake()` verifying update vs create), non-retryable immediate failure (0 retries), and secrets leakage audit.

---

## 5. Verification Method

To independently verify all findings and test suite behavior:
1. **Verify PHP and Test Suite**:
   Run in PowerShell:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test
   ```
   Expected: 30 tests passed, 106 assertions.
2. **Verify Model Serialization Leak (Observation 1)**:
   Run in PowerShell:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan tinker --execute "echo json_encode(App\Models\IntegrationSetting::first());"
   ```
   Inspect output to confirm whether `encrypted_credentials` is exposed when `$hidden` is missing.
3. **Inspect Specification Documents**:
   - Survey Report: `C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_spec_miner_survey_security_1\survey_report.md`
   - Handoff Report: `C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_spec_miner_survey_security_1\handoff.md`
