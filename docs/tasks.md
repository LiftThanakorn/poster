# Production-Grade Refactor Tasks

## PHASE 1 — Audit
- [x] Inspect code, schema, queue, current flow

## PHASE 2 — Data model
- [x] Created `PublishAttempt`, `PublishEvent`, `IntegrationSetting` models & migrations
- [x] Add indexes to models
- [x] Run `migrate`

## PHASE 3 — Publisher refactor
- [x] Create `PublishException` (Standardized result/errors)
- [x] Implement `PublishFailureClassifier` (classify retryable vs non_retryable)
- [x] Ensure Idempotency in `WordPressPublisher` and `FacebookPublisher`
- [x] Set timeouts for HTTP requests

## PHASE 4 — Queue
- [x] Refactor `PublishPostJob` into Orchestration
- [x] Create `PublishWordPressJob`
- [x] Create `PublishFacebookJob`
- [x] Implement retry/backoff logic & locking
- [x] Handle failed jobs -> update Target to `failed`, log Event

## PHASE 5 — UI
- [x] Implement Publishing progress UI (stepper or progress bar)
- [x] Manual Retry per channel in UI
- [x] Publishing timeline / Audit UI
- [x] Post Detail page

## PHASE 6 — Integration health
- [x] IntegrationSettingsService to read from DB (encrypted) fallback to `.env`
- [x] Facebook token check (expired token detection, permissions check)
- [x] WordPress health check

## PHASE 7 — Realtime
- [x] Implement Near-realtime polling fallback (safe polling every 1.5s while processing)

## PHASE 8 — Worker/Scheduler
- [x] Setup `scripts/start-worker.ps1` for Windows local
- [x] Documentation for Production Linux worker setup (Supervisor)
- [x] Configure Laravel Scheduler for stale processing recovery

## PHASE 9 — UX improvements
- [x] Autosave draft logic
- [x] Duplicate Post feature
- [x] Preview feature (HTML sanitize)
- [x] Media validation (PDF vs Image)

## PHASE 10 — Tests + docs
- [x] Full automated test suite (faking Http)
- [x] `npm run build` & `pint`
- [x] Production docs (PRODUCTION.md and DEVELOPMENT.md)

## PHASE 11 — Facebook Reach Investigation
- [x] A1: Facebook diagnostic snapshots model + migration
- [x] A1: Read-only Facebook diagnostics service (post metadata + insights)
- [x] A1: Lightweight `facebook:collect-diagnostics` command
- [x] A1: Defensive error/permission handling and secret sanitization
- [x] A1: Targeted Http::fake tests + Pint
- [ ] A2: Minimal Post Detail diagnostics UI (after A1 validation)
- [ ] B: Controlled publishing experiments (after baseline; do not enable yet)
- [ ] C: Compare 20–50 posts and decide whether publishing behavior should change
