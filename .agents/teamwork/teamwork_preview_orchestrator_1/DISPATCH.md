# Dispatch History

## 2026-10-02T03:00:23Z
You are the Project Orchestrator for the "Poster" Laravel 13 + React application refactoring.

Your working directory is:
C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_orchestrator_1

Your authoritative task requirements are recorded in:
C:\laragon\www\Poster\.agents\teamwork\ORIGINAL_REQUEST.md

Project root:
C:\laragon\www\Poster

Task Overview:
Refactor the "Poster" Laravel 13 + React application into a production-grade system.
Implement:
1. Idempotent per-channel queues and jobs: `PublishWordPressJob` and `PublishFacebookJob` orchestrated by a service. Enforce idempotency (verify/update via external_id), timeouts, and a `PublishFailureClassifier` to only retry retryable errors (e.g., 500, network issues) with exponential backoff.
2. UI & UX Refactoring: Publishing progress UI (stepper/progress bar) and Publishing Timeline (Audit log), manual retry per channel, autosave drafts, duplicate post functionality, and Media Picker strictly filtering out PDFs for Facebook.
3. Integration Health & Security: `IntegrationSettingsService` to handle encrypted credentials from database. Periodic health checks for Facebook token expiration/permissions and WordPress connectivity. Code audit: no tokens or passwords sent to frontend or logged in plain text.
4. Realtime Progress: Robust near-real-time polling (~1.5s) while post is in queued/processing/retrying status, stopping on terminal state.
5. Worker & Deployment Specs: Windows auto-start scripts (`start-worker.ps1`), Production Linux (Supervisor) documentation, Laravel Scheduler to recover stale processing jobs.
6. Testing: All PHPUnit tests pass using `Http::fake()`. Verify idempotency (retry updates remote post with external_id instead of duplicate) and non-retryable errors (e.g. FB code 190 marks target failed with 0 auto-retries).
7. Thai error mapping for Facebook API errors (e.g. "สิทธิ์ Facebook ไม่เพียงพอ").

Maintain your plan.md, progress.md, and BRIEFING.md in your working directory.
Dispatch specialists as needed to execute, review, and test the work.
When complete, report back to me with your comprehensive completion report and verification evidence.
