# Handoff Report: Milestone 1 Features 1 & 2 (Security Hardening & SecretSanitizer)

**Agent**: Explorer 1 (Milestone 1: Security, Integration & Health Foundation)  
**Date**: 2026-10-02  
**Target Project**: Poster (Laravel 13 + React / Inertia)  
**Target Directory**: `C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_explorer_m1_1\handoff.md`  
**Handoff Type**: Hard (Task Complete)

---

## 1. Observation

1. **Current `IntegrationSetting` Model Definition**:
   In `app/Models/IntegrationSetting.php` lines 7–23:
   ```php
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
   No `protected $hidden` property exists on this model.

2. **Plaintext Error and Response Body Logging**:
   - In `app/Services/Publishers/WordPressPublisher.php`:
     - Line 52: `Log::error('WP Media Upload Error: '.$response->body());`
     - Line 94: `Log::error('WordPress Publish Error: '.$response->body());`
     - Line 104: `Log::error('WordPress Publish Exception: '.$e->getMessage());`
   - In `app/Services/Publishers/FacebookPublisher.php`:
     - Line 104: `Log::warning('Facebook Comment Error (non-fatal): '.$response->body());`
     - Line 164: `Log::error('Facebook Publish Error: '.$response->body());`
     - Line 173: `Log::error('Facebook Publish Exception: '.$e->getMessage());`
     - Line 200: `Log::error('Facebook Photo Upload Error: '.$photoResponse->body());`

3. **Missing `app/Support/SecretSanitizer.php`**:
   The directory `app/Support` does not exist yet. Neither `SecretSanitizer` nor any centralized secret scrubbing service is present in the codebase.

4. **Test Environment Baseline**:
   - Running test baseline with command:
     `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=IntegrationSettingsTest`
   - Result:
     `{"tool":"phpunit","result":"passed","tests":5,"passed":5,"assertions":44,"duration_ms":2302}`
     The test runner and SQLite in-memory environment are fully functional under PHP 8.3.30.

5. **Empirical Regex Validation**:
   - Tested regex patterns on PHP 8.3.30 against Facebook tokens (`access_token=EAAB...` and standalone `EAAB...`), Bearer tokens, Basic Auth headers, basic auth in URLs (`https://user:pass@host`), JSON payloads (`"password": "..."`), and query string parameters (`password=...`).
   - All sensitive elements were replaced with `***REDACTED***` while multibyte Thai text (UTF-8) remained intact.
   - Tested recursive array sanitization (`sanitizeArray`): successfully masked sensitive keys (`password`, `access_token`, `app_password`, `authorization`), scrubbed string values containing token URLs, and preserved booleans, numbers, and nulls.

---

## 2. Logic Chain

1. **Serialization Exposure Risk**:
   - Observation 1 demonstrates that `IntegrationSetting` casts `encrypted_credentials` to `encrypted:array` but omits `$hidden`.
   - In Laravel Eloquent, `toArray()` and `toJson()` call `attributesToArray()`, which converts all attributes and applies casts. Because `encrypted:array` decrypts the ciphertext on access, serializing an instance of `IntegrationSetting` outputs decrypted credentials in plaintext.
   - Adding `protected $hidden = ['encrypted_credentials'];` instructs Eloquent to omit this attribute during serialization, eliminating data leakage without disrupting backend access via `$setting->encrypted_credentials`.

2. **Log & Trace Leakage Risk**:
   - Observation 2 demonstrates that raw `$response->body()` and `$e->getMessage()` are passed directly to `Log::error()` and `Log::warning()`.
   - If Facebook Graph API returns an error reflecting the request URL or parameter (e.g. `access_token=...`), or if WordPress returns a 401 response detailing basic auth failures, raw credentials are saved to `storage/logs/laravel.log`.
   - Furthermore, in Milestones 2 and 3, error messages and response payloads are stored in `PublishAttempt.metadata` and `PublishEvent.context`. Without scrubbing, credentials leak into database audit records.

3. **Requirement for `SecretSanitizer`**:
   - Observation 3 shows `app/Support/SecretSanitizer.php` does not exist.
   - A unified static utility class `SecretSanitizer` implementing `sanitize(?string $text): string` and `sanitizeArray(array $data): array` (as verified in Observation 5) provides a single point of enforcement for scrubbing string and array data before logging or persistence.

---

## 3. Caveats

1. **Bearer Token Authentication in HTTP Calls**: Passing access tokens via Bearer headers instead of GET query parameters (`FacebookPublisher` Rule S2) is designated as Feature 3 of Milestone 1 and is being explored by Explorer 2. `SecretSanitizer` handles query tokens regardless, providing defense-in-depth.
2. **Third-Party Secret Formats**: Custom WordPress application passwords without standard labeling (e.g. if passed as arbitrary body text without `password` keyword or basic auth header) cannot be deduced by regex alone unless matched by key name in `sanitizeArray`.

---

## 4. Conclusion

1. **Feature 1 Implementation**:
   Update `app/Models/IntegrationSetting.php` to include:
   ```php
   protected $hidden = [
       'encrypted_credentials',
   ];
   ```
2. **Feature 2 Implementation**:
   Create `app/Support/SecretSanitizer.php` with:
   - `REDACTED = '***REDACTED***'`
   - `SENSITIVE_KEYS` list covering passwords, tokens, API keys, and authorization headers.
   - `sanitize(?string $text): string` matching Facebook query tokens, raw EAAB tokens, Bearer tokens, Basic auth, URLs, and JSON keys with the `/u` UTF-8 flag.
   - `sanitizeArray(array $data): array` recursively redacting sensitive keys and sanitizing string values.
   - `sanitizeThrowable(\Throwable $e): string` helper.
3. **Publisher Hardening**:
   Wrap all `Log::error(...)`, `Log::warning(...)`, and return `'error'` values in `FacebookPublisher.php` and `WordPressPublisher.php` with `SecretSanitizer::sanitize(...)`.
4. **Test Suite**:
   Create `tests/Feature/SecurityHardeningTest.php` to assert zero credential leakage on `IntegrationSetting` serialization and 100% regex match coverage on `SecretSanitizer`.

---

## 5. Verification Method

1. **Automated Unit & Feature Test Command**:
   Run the specific test suite using the Laragon PHP 8.3 CLI:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SecurityHardeningTest
   ```

2. **Files to Inspect**:
   - `app/Models/IntegrationSetting.php`: Verify `protected $hidden = ['encrypted_credentials'];` is declared.
   - `app/Support/SecretSanitizer.php`: Verify class structure and regex patterns with `/u` modifier.
   - `tests/Feature/SecurityHardeningTest.php`: Verify all test assertions pass.

3. **Invalidation Conditions**:
   - If `$setting->toJson()` contains the plaintext password or access token, `$hidden` was not applied properly.
   - If an error message containing `access_token=EAAB...` is logged or stored in `PublishAttempt.metadata` without being replaced by `***REDACTED***`, `SecretSanitizer` was not invoked.
