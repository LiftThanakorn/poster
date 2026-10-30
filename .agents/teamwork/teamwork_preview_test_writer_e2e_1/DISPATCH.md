## 2026-10-02T03:17:23Z
You are the E2E Test Suite Architect and Writer for the Poster Laravel 13 + React refactoring project.
Project root: C:\laragon\www\Poster
Your working directory: C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_test_writer_e2e_1
Parent agent conversation ID: 88ae952d-230f-4b30-8f2a-a0bea6f43ba1

MANDATORY FIRST STEP:
Read C:\laragon\www\Poster\.agents\teamwork\ORIGINAL_REQUEST.md and C:\laragon\www\Poster\PROJECT.md completely.

YOUR SCOPE & MISSION:
You are the independent E2E Testing Track (requirement-driven, opaque-box).
1. Create C:\laragon\www\Poster\TEST_INFRA.md following the template from PROJECT.md and the Project Pattern:
   - Test Philosophy (opaque-box, requirement-driven, using Http::fake(), zero internal coupling).
   - Feature Inventory mapping for all 20 features in PROJECT.md.
   - 4-Tier test methodology (Category-Partition, Boundary Value Analysis, Pairwise Combinatorial, Real-World Application Scenarios).
   - Coverage thresholds:
     * Tier 1: >= 5 per feature
     * Tier 2: >= 5 per feature
     * Tier 3: pairwise coverage of major interactions
     * Tier 4: >= 5 realistic application scenarios
2. Implement the comprehensive E2E test suite in tests/Feature/E2E/ with clean, modular test files:
   - Ensure all network calls to WordPress, Facebook, or external services are mocked with Http::fake().
   - Specifically verify:
     * Idempotency: when external_id is present, remote update is called instead of duplicate creation.
     * Non-retryable error: Facebook code 190 marks target failed with 0 auto-retries.
     * Security: no access tokens or passwords in frontend responses or serialized models.
   - Run tests using: & "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" artisan test --filter=E2E (or php artisan test).
3. Once the test suite is designed, implemented, and organized, publish C:\laragon\www\Poster\TEST_READY.md at project root with:
   - Test Runner command.
   - Coverage Summary table across Tiers 1-4.
   - Feature Checklist confirming test coverage for each feature.
4. Write handoff.md in your working directory and notify parent (ID: 88ae952d-230f-4b30-8f2a-a0bea6f43ba1).
