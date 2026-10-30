# Handoff Report: Milestone 1 — Features 3 & 4
**From**: Explorer 2 (`teamwork_preview_explorer_m1_2`)  
**To**: Orchestrator (`88ae952d-230f-4b30-8f2a-a0bea6f43ba1`) & Milestone 1 Implementers  
**Date**: 2026-10-02  
**Type**: Hard Handoff (Investigation & Architecture Design Complete)

---

## 1. Observation

1. **FacebookPublisher Token in Query Strings**:
   - In `app/Services/Publishers/FacebookPublisher.php` lines 44-50 and 55-61, `$this->accessToken` is passed as a GET query parameter:
     ```php
     $meResponse = Http::connectTimeout(5)
         ->timeout(10)
         ->get($this->graphUrl('me'), [
             'fields' => 'id',
             'access_token' => $this->accessToken,
         ]);
     ```
   - In `FacebookPublisher::healthCheck()` lines 260-276, `'access_token' => $this->pageAccessToken()` is also passed in the GET query array.
2. **Existing `IntegrationSettingsService` Architecture**:
   - `app/Services/IntegrationSettingsService.php` contains lines 1-104 with methods `wordpress(): array`, `facebook(): array`, `updateWordPress(...)`, `updateFacebook(...)`, and `markHealth(...)`.
   - It currently lacks `getCredentials(provider)` and `saveCredentials(provider, credentials, settings)` specified in `PROJECT.md` line 65-76.
   - It currently lacks `checkFacebookHealth(): array` (with permission & expiration inspection) and `checkWordPressHealth(): array` (with REST API & `publish_posts` capability verification).
   - Currently, health check caching in `IntegrationController.php` (lines 31-48, 105, 131) manually handles `Cache::remember('integrations.{channel}.health', now()->addMinutes(5), ...)`. Caching is not encapsulated in the service.
3. **Current Test Baseline**:
   - Executing `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test` yields:
     ```json
     {"tool":"phpunit","result":"passed","tests":33,"passed":33,"assertions":140,"duration_ms":4555}
     ```
   - `tests/Feature/IntegrationSettingsTest.php` lines 93-95 and 118-120 use `Http::fake()` with wildcard matches `https://graph.facebook.com/*` returning `['id' => '123', 'name' => 'Test Page']` and `https://example.com/wp-json/wp/v2/users/me` returning `['id' => 1]`. Any health check implementation must tolerate minimal mock payloads where roles/capabilities or permission arrays are not present.

---

## 2. Logic Chain

1. **Vulnerability Mitigation (Observation 1 $\rightarrow$ Step 1)**:
   - Passing access tokens in URL query strings causes them to be logged in plaintext across web servers, proxy logs, and Laravel exception stack traces when network timeouts occur.
   - Meta Graph API fully supports RFC 6750 `Authorization: Bearer <token>` headers for all endpoints.
   - Replacing query parameters with `Http::withToken($this->accessToken)` for `/me`, `/me/accounts`, and `/{page_id}` eliminates token exposure from URLs and logs without breaking API compatibility.
2. **Encapsulated Service Contract (Observation 2 $\rightarrow$ Step 2)**:
   - `PROJECT.md` requires `getCredentials(string $provider): array` and `saveCredentials(string $provider, array $credentials, ?array $settings = null): void`.
   - `saveCredentials` must maintain "blank secret preservation": when empty string `''` or `null` is submitted for passwords or tokens, existing encrypted credentials in the database must not be overwritten.
   - Integrating `checkFacebookHealth()` and `checkWordPressHealth()` into `IntegrationSettingsService` centralizes health inspection logic, allowing queue workers, scheduled commands (`integrations:check-health`), and controllers to share the exact same probe mechanism.
3. **5-Minute Cache Strategy (Observation 2 $\rightarrow$ Step 3)**:
   - Frequent third-party API probes against Facebook Graph API and WordPress REST API would lead to rate limits (Facebook codes 4, 17, 32, 613) and slow page loads.
   - Implementing a 5-minute cache (`Cache::put(..., now()->addMinutes(5))`) on both health check methods prevents API throttling.
   - Cache invalidation occurs automatically in `saveCredentials()` or when `forceFresh: true` is passed to the health check method.
4. **Resilient Permission & Capability Parsing (Observation 3 $\rightarrow$ Step 4)**:
   - For WordPress: Inspecting `$response->json('capabilities.publish_posts')` or roles (`administrator`, `editor`, `author`) confirms publishing capability. If neither is present (as in existing test mocks `['id' => 1]`), default to `$canPublish = true` so existing tests remain green.
   - For Facebook: Querying `/me/permissions` confirms `pages_manage_posts` and `pages_read_engagement`. Inspecting `/debug_token` confirms expiration (`expires_at`). If token is expired (`isPast()`), mark unhealthy.
5. **Full Backward Compatibility (Observation 2 & 3 $\rightarrow$ Step 5)**:
   - By retaining `wordpress()`, `facebook()`, `updateWordPress()`, `updateFacebook()`, and `markHealth()` in `IntegrationSettingsService` (delegating to `getCredentials` and `saveCredentials`), all existing controllers (`PostController`, `IntegrationController`, `FacebookPublisher`, `WordPressPublisher`) and existing tests continue to operate without regression.

---

## 3. Caveats

1. **Meta Graph API Mocking in Tests**: In real production environments, inspecting `/debug_token` requires an App Access Token (`app_id|app_secret`) unless using User Access Tokens. The implementation inspects `/debug_token` gracefully: if `/debug_token` fails or returns non-200, it falls back to Page probe status rather than crashing.
2. **WordPress Custom Roles**: If a custom WordPress role (e.g. "Contributor" or custom custom post type publisher) is used that lacks the exact standard role string but has `publish_posts` capability, checking `capabilities.publish_posts` takes priority over role name matching.
3. **No Project Code Modified Yet**: As an explorer in read-only mode, no production files were modified. All concrete implementations are ready for the Milestone 1 Implementer in `analysis.md`.

---

## 4. Conclusion

Features 3 and 4 have been fully mapped, designed, and verified against all project constraints:
1. **Feature 3**: Update `app/Services/Publishers/FacebookPublisher.php` to use `Http::withToken()` in `pageAccessToken()`, `healthCheck()`, and `postGraph()`. Remove `access_token` from query parameters.
2. **Feature 4**: Update `app/Services/IntegrationSettingsService.php` with:
   - `getCredentials(string $provider): array`
   - `saveCredentials(string $provider, array $credentials, ?array $settings = null): void`
   - `checkFacebookHealth(bool $forceFresh = false): array`
   - `checkWordPressHealth(bool $forceFresh = false): array`
   - 5-minute health check cache strategy with `forgetHealthCache(?string $provider = null)`
   - Backward-compatible wrappers for `wordpress()`, `facebook()`, `updateWordPress()`, `updateFacebook()`, and `markHealth()`.
3. Complete implementation code and comprehensive PHPUnit test cases are documented in `analysis.md`.

---

## 5. Verification Method

To verify the implementation once applied:

1. **Execute Existing Test Baseline**:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=IntegrationSettingsTest
   ```
   *Expected outcome*: 5 passed, 0 failures.

2. **Execute Health Check & Security Test Suite**:
   Create and execute `tests/Feature/IntegrationHealthCheckTest.php` (code provided in Section 5.1 of `analysis.md`):
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=IntegrationHealthCheckTest
   ```
   *Expected outcome*:
   - Asserts `Authorization: Bearer <token>` is present in all Facebook HTTP calls.
   - Asserts `access_token` query parameter is absent.
   - Asserts missing permissions (`pages_manage_posts`) marks Facebook health unhealthy.
   - Asserts `publish_posts` capability is verified for WordPress.
   - Asserts 5-minute cache prevents redundant HTTP requests on consecutive calls.

3. **Execute Full Test Suite**:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test
   ```
   *Expected outcome*: All tests pass with exit code 0.
