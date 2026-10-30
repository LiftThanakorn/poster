# Original User Request

## 2026-10-02T02:58:19Z

Refactor the "Poster" Laravel 13 + React application into a production-grade system. Implement idempotent per-channel queues, robust error classification with exponential backoff, encrypted integration settings, and near-realtime publishing progress in the UI.

Working directory: C:\laragon\www\Poster
Integrity mode: development

## Requirements

### R1. Queue & Publisher Orchestration (Phases 3-4)
Implement `PublishWordPressJob` and `PublishFacebookJob` orchestrated by a service. Enforce idempotency (verify/update via external_id), timeouts, and a `PublishFailureClassifier` to only retry retryable errors (e.g., 500, network issues) with exponential backoff.

### R2. UI & UX Refactoring (Phases 5 & 9)
Build a publishing progress UI (stepper/progress bar) and a Publishing Timeline (Audit log). Add manual retry per channel. Implement autosave drafts, duplicate post functionality, and a Media Picker that strictly filters out PDFs for Facebook.

### R3. Integration Health & Security (Phases 6)
Implement `IntegrationSettingsService` to handle encrypted credentials from the database. Create periodic health checks for Facebook token expiration/permissions and WordPress connectivity.

### R4. Realtime Progress (Phase 7)
Implement robust near-real-time polling (every ~1.5 seconds) while a post is actively in `queued`, `processing`, or `retrying` status. Stop polling when the post reaches a terminal state.

### R5. Worker & Deployment Specs (Phase 8 & 10)
Provide Windows auto-start scripts (`start-worker.ps1`) and Production Linux (Supervisor) documentation. Configure Laravel Scheduler to recover stale processing jobs. Ensure all PHPUnit tests pass using `Http::fake()`.

## Acceptance Criteria

### Reliability & Idempotency
- [ ] Programmatic check: `Http::fake()` tests prove that if a post already has an external_id, retrying the job updates the remote post instead of creating a duplicate.
- [ ] Programmatic check: Non-retryable errors (e.g., Facebook code 190) mark the target as `failed` immediately with 0 auto-retries.

### UI & UX
- [ ] Agent-as-judge: The user sees publishing progress advance without manually refreshing the browser (via automated polling).
- [ ] Agent-as-judge: Facebook API errors are mapped to readable Thai messages (e.g., "สิทธิ์ Facebook ไม่เพียงพอ").

### Security
- [ ] Code audit: No access tokens or passwords are sent to the frontend or logged in plain text.
