# Dispatch History

## 2026-10-02T03:04:25Z
From: parent (88ae952d-230f-4b30-8f2a-a0bea6f43ba1)
Priority: MESSAGE_PRIORITY_HIGH

You are a read-only Security, Health & Testing Spec Miner for the Poster Laravel 13 + React refactoring project.
Project root: C:\laragon\www\Poster
Your working directory: C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_spec_miner_survey_security_1
Parent agent conversation ID: 88ae952d-230f-4b30-8f2a-a0bea6f43ba1

MANDATORY FIRST STEP:
Read C:\laragon\www\Poster\.agents\teamwork\ORIGINAL_REQUEST.md completely.

SCOPE BOUNDARIES:
- Read-only exploration and analysis.
- DO NOT create or modify any source code or test files in the project.
- Write your findings to:
  1. C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_spec_miner_survey_security_1\survey_report.md
  2. C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_spec_miner_survey_security_1\handoff.md
- Update progress.md in your working directory periodically.

INVESTIGATION OBJECTIVES:
1. Examine existing integration settings and secrets storage:
   - How Facebook and WordPress credentials/tokens/app secrets/passwords are currently stored and accessed.
   - Requirements for `IntegrationSettingsService`: encrypt credentials in database, decrypt on demand for publishing jobs.
   - Security Audit: verify no access tokens, app secrets, or passwords leak to the frontend (Inertia props, API responses) or get logged in plain text in logs/timelines.
2. Detail requirements for Integration Health Checks (R3):
   - Facebook token health check: inspect token expiration, scopes/permissions (e.g. `pages_manage_posts`, `pages_read_engagement`).
   - WordPress health check: verify REST API connectivity, authentication, and application password validity.
3. Detail Thai Error Mapping for Facebook API (R7):
   - Facebook Graph API error structures (error code, error_subcode, type, message).
   - Specific handling for error code 190 (expired/invalid token -> e.g. "เซสชัน Facebook หมดอายุ กรุณาเชื่อมต่อบัญชีใหม่").
   - Missing permissions error -> "สิทธิ์ Facebook ไม่เพียงพอ".
   - Rate limit errors, network errors, media validation errors.
   - Complete dictionary/mapping table for user-friendly Thai messages.
4. Examine existing testing setup:
   - PHPUnit / Pest configuration (`phpunit.xml`, `tests/Feature`, `tests/Unit`).
   - Current test suite status and test execution commands (`php artisan test`).
   - Strategy for `Http::fake()` acceptance criteria:
     * Idempotency test: verify retry with existing `external_id` calls remote update endpoint, NOT create endpoint.
     * Non-retryable error test: Facebook code 190 marks channel failed immediately with 0 auto-retries.
5. Document all specifications, security rules, error translation tables, and test coverage matrices in survey_report.md and handoff.md.

When finished, write your complete survey_report.md and handoff.md, then send a message to parent (ID: 88ae952d-230f-4b30-8f2a-a0bea6f43ba1) summarizing your findings.
