# Project: Poster (Laravel 13 + React Application Refactoring)

## Architecture
Poster is a multi-channel social media publishing platform built on Laravel 13 and React 18 (Inertia v2 + Tailwind CSS). The system is architected into three primary layers:
1. **Core Domain & Security Layer**:
   - `IntegrationSetting` model with encrypted database credentials and hidden serialization.
   - `IntegrationSettingsService` managing credentials on-demand and executing health checks against Facebook Graph API and WordPress REST API.
   - `SecretSanitizer` utility guaranteeing zero plaintext token leakage into logs, exceptions, or audit events.
2. **Publishing & Queue Orchestration Layer**:
   - Decoupled per-channel jobs: `PublishWordPressJob` and `PublishFacebookJob` dispatched onto database queues.
   - `PublishingService` orchestrating the multi-channel workflow (sequencing WordPress before Facebook to resolve `{wordpress_url}`, and handling isolated channel retries).
   - `PublishFailureClassifier` determining retryability, exponential backoff delays (`[10, 30, 90]`), and mapping third-party error codes (e.g. Facebook code 190) to user-friendly Thai messages.
   - Strict idempotency: if `$target->external_id` exists, remote update API is invoked instead of creating a duplicate remote post.
   - Stale job recovery command (`poster:recover-stale-jobs`) and worker startup tooling (`scripts/start-worker.ps1`, Supervisor docs).
3. **User Experience & Near-Realtime Polling Layer**:
   - React components providing visual stepper/progress feedback during publishing (`PublishingProgressModal`, `PublishingStepper`).
   - Audit trail modal (`PublishingTimelineModal`) rendering detailed attempt history and events from `publish_attempts` and `publish_events`.
   - Per-channel retry (`ChannelRetryButton`), debounced autosave drafts (`useAutosave`), and post duplication (`DuplicatePostAction`).
   - Strict multi-layer PDF exclusion for Facebook destinations.
   - Dynamic near-real-time polling (~1.5s interval) while posts are in active states (`queued`, `processing`, `retrying`), cleanly terminating upon terminal states (`published`, `failed`, `partially_published`).

---

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Model Hidden Encryption | Add `$hidden = ['encrypted_credentials']` to `IntegrationSetting` to prevent plaintext credential exposure in JSON/Array serialization | M1 | Survey (Security Spec Miner) |
| 2 | Secret Sanitizer Utility | Centralized regex sanitizer to strip tokens, passwords, and secrets from logs, exception traces, and timeline metadata | M1 | Survey (Security Spec Miner) |
| 3 | Bearer Token Authentication | Update `FacebookPublisher` and health checks to pass tokens via HTTP Bearer headers instead of GET query params | M1 | Survey (Security Spec Miner) |
| 4 | Integration Health Checks | Implement Facebook token expiration & permissions probe (`pages_manage_posts`, `pages_read_engagement`) and WordPress REST API connectivity probe | M1 | ORIGINAL_REQUEST R3 |
| 5 | Target Status Migration | Update `publish_targets.status` column from enum to string(32) with default 'pending' to accommodate 'retrying' and 'detached' | M1 | Survey (Backend Explorer) |
| 6 | PublishFailureClassifier & Thai Error Mapping | Classify errors into retryable (500, 429, timeouts) vs non-retryable (400, 401, 403, FB code 190), apply exponential backoff, and map to Thai messages | M2 | ORIGINAL_REQUEST R1, R7 |
| 7 | Decoupled Channel Jobs | Implement `PublishWordPressJob` and `PublishFacebookJob` with 60s timeout, exponential backoff, and attempt logging | M2 | ORIGINAL_REQUEST R1 |
| 8 | Publishing Service Orchestration | Implement `PublishingService` coordinating channel sequence (WordPress first for `{wordpress_url}`, then Facebook) and single-channel retry | M2 | ORIGINAL_REQUEST R1 |
| 9 | Publishing Idempotency | Enforce remote update (`update()`) rather than remote create (`publish()`) when `$target->external_id` is already populated | M2 | ORIGINAL_REQUEST R1, Acceptance Criteria |
| 10 | Non-Retryable Immediate Fail | Configure worker and classifier so Facebook code 190 and similar terminal errors mark target failed immediately with 0 auto-retries | M2 | ORIGINAL_REQUEST R1, Acceptance Criteria |
| 11 | Windows Worker Auto-Start Script | Create `scripts/start-worker.ps1` with dynamic Laragon PHP detection, restart resilience, and queue worker loop | M3 | ORIGINAL_REQUEST R5 |
| 12 | Linux Supervisor Documentation | Create documentation and configuration file for running queue workers under Supervisor in production Linux | M3 | ORIGINAL_REQUEST R5 |
| 13 | Stale Job Recovery Command | Create `poster:recover-stale-jobs` Artisan command to detect zombie targets in processing > 15m, scheduled every 10m in `routes/console.php` | M3 | ORIGINAL_REQUEST R5 |
| 14 | Publishing Stepper & Progress UI | Build `PublishingProgressModal` and `PublishingStepper` showing overall percent bar and per-channel progression | M4 | ORIGINAL_REQUEST R2 |
| 15 | Publishing Timeline Audit Modal | Build `PublishingTimelineModal` rendering detailed audit events, attempt numbers, durations, and Thai error messages | M4 | ORIGINAL_REQUEST R2 |
| 16 | Manual Channel Retry Action | Add `ChannelRetryButton` allowing isolated retry of failed targets without re-publishing succeeded channels | M4 | ORIGINAL_REQUEST R2 |
| 17 | Autosave Drafts Functionality | Implement debounced (3s) autosave hook updating draft posts and swapping URL state seamlessly | M4 | ORIGINAL_REQUEST R2 |
| 18 | Duplicate Post Functionality | Implement post duplication endpoint and UI action to clone post content while resetting target publishing status | M4 | ORIGINAL_REQUEST R2 |
| 19 | Facebook Media PDF Exclusion | Enforce strict multi-layer filter preventing PDF upload or selection when Facebook destination is chosen | M4 | ORIGINAL_REQUEST R2 |
| 20 | Near-Realtime Polling Hook | Implement ~1.5s interval polling while post status is in `queued`, `processing`, or `retrying`, stopping cleanly on terminal states | M4 | ORIGINAL_REQUEST R4 |
| 21 | Full E2E Test Suite (Tiers 1-4) | Comprehensive requirement-driven opaque-box test suite verifying all 20 features, idempotency, non-retryable handling, and security | M5 | ORIGINAL_REQUEST Acceptance Criteria |
| 22 | Adversarial Hardening (Tier 5) | White-box stress-testing and adversarial coverage hardening by Challenger | M5 | Project Pattern Phase 2 |

---

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Security, Integration & Health Foundation | Features 1-5: Model encryption hidden, SecretSanitizer, Bearer headers, Integration health checks, Status migration | none | PLANNED |
| M2 | Publishing Orchestration & Queue Idempotency | Features 6-10: PublishFailureClassifier, PublishWordPressJob, PublishFacebookJob, PublishingService, Idempotency & Immediate Fail | M1 | PLANNED |
| M3 | Operations, Worker Scripts & Stale Recovery | Features 11-13: start-worker.ps1, Supervisor configuration & docs, poster:recover-stale-jobs command & scheduler | M1, M2 | PLANNED |
| M4 | Frontend UI/UX Refactoring & Realtime Polling | Features 14-20: Stepper modal, Timeline modal, Channel retry button, Autosave drafts, Duplicate post, Facebook PDF filter, 1.5s Polling hook | M1, M2 | PLANNED |
| M5 | Final E2E Acceptance & Adversarial Hardening | Features 21-22: 100% pass of E2E test suite (Tiers 1-4) + Tier 5 adversarial coverage hardening | M1, M2, M3, M4, TEST_READY.md | PLANNED |

---

## Interface Contracts

### 1. `IntegrationSettingsService`
```php
namespace App\Services;

class IntegrationSettingsService
{
    public function getCredentials(string $provider): array;
    public function saveCredentials(string $provider, array $credentials, ?array $settings = null): void;
    public function checkFacebookHealth(): array; // ['status' => 'healthy'|'unhealthy', 'expires_at' => ?string, 'permissions' => array, 'error' => ?string]
    public function checkWordPressHealth(): array; // ['status' => 'healthy'|'unhealthy', 'user' => ?string, 'can_publish' => bool, 'error' => ?string]
}
```

### 2. `SecretSanitizer`
```php
namespace App\Support;

class SecretSanitizer
{
    public static function sanitize(string $text): string;
    public static function sanitizeArray(array $data): array;
}
```

### 3. `PublishFailureClassifier` & `PublishFailure`
```php
namespace App\Services\Publishers;

class PublishFailure
{
    public bool $isRetryable;
    public int $retryDelaySeconds;
    public string $errorType;
    public string $errorCode;
    public string $messageEn;
    public string $messageTh;
    public ?int $httpStatus;
}

class PublishFailureClassifier
{
    public static function classifyThrowable(\Throwable $e, string $channel, int $attemptNumber = 1): PublishFailure;
    public static function classifyHttpResponse(\Illuminate\Http\Client\Response $response, string $channel, int $attemptNumber = 1): PublishFailure;
}
```

### 4. `PublishingService`
```php
namespace App\Services\Publishers;

class PublishingService
{
    public function publishPost(Post $post, ?string $correlationId = null): void;
    public function retryTarget(Post $post, string $channel): void;
    public function recoverStaleJobs(int $timeoutMinutes = 15): int;
}
```

### 5. Frontend API Endpoints
- `GET /posts/{post}/status` -> `{ id: int, status: string, targets: array, updated_at: string }`
- `GET /posts/{post}/timeline` -> `{ attempts: array, events: array }`
- `POST /posts/{post}/retry/{channel}` -> `{ success: bool, message: string }`
- `POST /posts/{post}/duplicate` -> redirect to edit new draft post
- `POST /posts/autosave` & `PUT /posts/{post}/autosave` -> `{ success: bool, post_id: int }`

---

## Code Layout
- Backend Models & Services:
  - `app/Models/IntegrationSetting.php`, `app/Models/PublishTarget.php`, `app/Models/PublishAttempt.php`, `app/Models/PublishEvent.php`, `app/Models/Post.php`
  - `app/Services/IntegrationSettingsService.php`
  - `app/Support/SecretSanitizer.php`
  - `app/Services/Publishers/PublishingService.php`
  - `app/Services/Publishers/PublishFailureClassifier.php`
  - `app/Services/Publishers/PublishFailure.php`
  - `app/Services/Publishers/WordPressPublisher.php`
  - `app/Services/Publishers/FacebookPublisher.php`
  - `app/Jobs/PublishWordPressJob.php`
  - `app/Jobs/PublishFacebookJob.php`
  - `app/Console/Commands/RecoverStaleJobsCommand.php`
- Operational Scripts & Configs:
  - `scripts/start-worker.ps1`
  - `docs/supervisor.md`
- Frontend React Components:
  - `resources/js/Components/PublishingProgressModal.jsx`
  - `resources/js/Components/PublishingStepper.jsx`
  - `resources/js/Components/PublishingTimelineModal.jsx`
  - `resources/js/Components/ChannelRetryButton.jsx`
  - `resources/js/Hooks/useAutosave.js`
  - `resources/js/Hooks/usePublishingProgress.js`
  - `resources/js/Components/PostForm.jsx`
  - `resources/js/Pages/Posts/Index.jsx`
- Tests:
  - `tests/Feature/SecurityHardeningTest.php`
  - `tests/Feature/IntegrationHealthCheckTest.php`
  - `tests/Feature/PublishIdempotencyTest.php`
  - `tests/Feature/PublishFailureClassificationTest.php`
  - `tests/Feature/StaleJobRecoveryTest.php`
  - `tests/Feature/E2E/...` (Opaque-box test suite published by E2E Testing Track)
