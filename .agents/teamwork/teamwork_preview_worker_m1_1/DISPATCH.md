## 2026-10-02T03:27:36Z

You are the Worker implementing Milestone 1: Security, Integration & Health Foundation for the Poster Laravel 13 + React refactoring project.
Project root: C:\laragon\www\Poster
Your working directory: C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_worker_m1_1
Parent agent conversation ID: 88ae952d-230f-4b30-8f2a-a0bea6f43ba1

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

MANDATORY FIRST STEPS:
1. Read C:\laragon\www\Poster\.agents\teamwork\ORIGINAL_REQUEST.md and C:\laragon\www\Poster\PROJECT.md.
2. Read the detailed analysis and handoff reports produced by the 3 M1 Explorers:
   - C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_explorer_m1_1\analysis.md and handoff.md
   - C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_explorer_m1_2\analysis.md and handoff.md
   - C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_explorer_m1_3\analysis.md and handoff.md

YOUR EXCLUSIVE WRITE OWNERSHIP:
You own and may modify or create the following files:
- app/Models/IntegrationSetting.php
- app/Support/SecretSanitizer.php
- app/Services/IntegrationSettingsService.php
- app/Services/Publishers/FacebookPublisher.php
- app/Services/Publishers/WordPressPublisher.php
- database/migrations/2026_10_02_030000_alter_status_column_on_publish_targets_table.php
- tests/Feature/SecurityHardeningTest.php
- tests/Feature/IntegrationHealthCheckTest.php

IMPLEMENTATION REQUIREMENTS:
1. Feature 1: Model Hidden Encryption
   In app/Models/IntegrationSetting.php, add:
   protected $hidden = ['encrypted_credentials'];
   Ensure credential attributes are excluded during toArray(), toJson(), and json_encode().

2. Feature 2: SecretSanitizer Utility
   Create app/Support/SecretSanitizer.php with robust UTF-8 regex patterns (/u modifier) and recursive array sanitization.
   In app/Services/Publishers/FacebookPublisher.php and WordPressPublisher.php, wrap all error and warning logs, exceptions, and response body logs with SecretSanitizer::sanitize(...).

3. Feature 3: Bearer Token Auth
   In app/Services/Publishers/FacebookPublisher.php, update pageAccessToken(), healthCheck(), and postGraph() to pass access tokens via Authorization: Bearer <token> headers (using Http::withToken($token)) instead of GET query parameters.

4. Feature 4: IntegrationSettingsService & Health Probes
   In app/Services/IntegrationSettingsService.php, implement:
   - getCredentials(string $provider): array
   - saveCredentials(string $provider, array $credentials, ?array $settings = null): void (preserve existing encrypted credentials if submitted value is blank/null)
   - checkFacebookHealth(bool $forceFresh = false): array (validate token expiration via debug/page probe, check permissions pages_manage_posts and pages_read_engagement)
   - checkWordPressHealth(bool $forceFresh = false): array (validate /wp-json/wp/v2/users/me and publish_posts capability)
   - 5-minute health check caching with cache invalidation in saveCredentials()
   - Retain existing methods (wordpress, facebook, updateWordPress, updateFacebook, markHealth) as backward-compatible proxies.

5. Feature 5: Status Column Migration
   Create database/migrations/2026_10_02_030000_alter_status_column_on_publish_targets_table.php:
   - up(): alter status to string('status', 32)->default('pending')
   - down(): pre-normalize values not in enum to 'pending' before reverting to enum, preventing MySQL Error 1265.
   - Run migration via artisan migrate.

6. Comprehensive Tests:
   Create tests/Feature/SecurityHardeningTest.php and tests/Feature/IntegrationHealthCheckTest.php using Http::fake().
   Run tests using Laragon PHP:
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test (or php-8.3.16).
   Ensure all existing 33 tests AND all new tests pass with 0 failures.

7. Reporting:
   Document all implemented files, diffs, test outputs, and verification commands in handoff.md in your working directory.
   Send a message to parent (ID: 88ae952d-230f-4b30-8f2a-a0bea6f43ba1) when complete.
