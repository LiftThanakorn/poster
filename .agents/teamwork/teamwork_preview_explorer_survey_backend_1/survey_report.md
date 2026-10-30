# Backend Architecture Survey & Technical Specification Report (R1 & R5)

**Project**: Poster (Laravel 13 + React + Inertia)  
**Date**: 2026-10-02  
**Author**: Backend Architecture Explorer (`teamwork_preview_explorer_survey_backend_1`)  
**Scope**: R1 (Queue & Publisher Orchestration, Idempotency, Failure Classification) & R5 (Worker Auto-Start, Supervisor Docs, Stale Job Recovery)

---

## 1. Executive Summary

The Poster application currently contains the basic foundational structures for publishing to WordPress and Facebook, including database tables (`posts`, `publish_targets`, `publish_attempts`, `publish_events`, `integration_settings`), along with basic HTTP client wrappers (`WordPressPublisher`, `FacebookPublisher`). 

However, the existing execution flow is tightly coupled inside a single job (`PublishPostJob`) that executes both channels sequentially in-process, without persistent attempt logging, without standardized exception handling, and without fine-grained error classification or channel-specific retries. Additionally, `PublishWordPressJob`, `PublishFacebookJob`, `PublishingService`, and `PublishException` exist merely as empty stubs.

This report establishes the complete architectural blueprints for:
1. **R1**: Transforming the publishing lifecycle into an idempotent, decoupled, per-channel queue pipeline orchestrated by `PublishingService`, guarded by `PublishFailureClassifier`, with explicit HTTP/Job timeout management and exponential backoff.
2. **R5**: Establishing local Windows worker tooling (`scripts/start-worker.ps1`), production Linux Supervisor process definitions, and an automated scheduler command (`poster:recover-stale-jobs`) to heal stale jobs stuck in processing state.

---

## 2. Current State Audit & Codebase Observations

### 2.1 Database Schema & Models
1. **`posts` table** (`2026_10_01_042848_create_posts_table.php`):
   - Statuses: `['draft', 'scheduled', 'publishing', 'published', 'partially_published', 'failed']`.
   - Fields: `title`, `slug`, `wp_content`, `fb_caption`, `fb_comment`, `wp_category_id`, `featured_image_id`, `author_id`, `published_at`, `scheduled_at`.
   - Model: `App\Models\Post` has relationships: `targets()`, `publishAttempts()`, `publishEvents()`, `media()`, `author()`.

2. **`publish_targets` table** (`2026_10_01_042855_create_publish_targets_table.php`):
   - Current schema:
     ```php
     $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
     $table->string('channel'); // wordpress, facebook
     $table->enum('status', ['pending', 'processing', 'published', 'failed'])->default('pending');
     $table->string('external_id')->nullable();
     $table->string('external_url')->nullable();
     $table->text('error_message')->nullable();
     $table->integer('retry_count')->default(0);
     $table->unique(['post_id', 'channel']);
     ```
   - *Observation & Potential Issue*: In `PostController.php:203`, the code attempts to set `status = 'detached'` for unselected channels. In MySQL strict mode, setting an enum to a value outside its defined list (`['pending', 'processing', 'published', 'failed']`) throws an error. Furthermore, to support the requested `retrying` status (R4 polling requirement), `publish_targets.status` should either be converted to a `string(32)` or expanded to include `retrying` and `detached`.

3. **`publish_attempts` table** (`2026_10_02_024039_create_publish_attempts_table.php`):
   - Fields: `correlation_id` (uuid), `post_id`, `publish_target_id`, `channel`, `attempt_number`, `status` (string: queued, processing, published, failed, retrying), `queued_at`, `started_at`, `finished_at`, `duration_ms`, `error_type`, `error_code`, `error_message`, `http_status`, `retryable` (boolean), `metadata` (json).
   - Fully ready to log every single job attempt.

4. **`publish_events` table** (`2026_10_02_024040_create_publish_events_table.php`):
   - Fields: `correlation_id`, `post_id`, `publish_target_id`, `channel`, `level` (info, warning, error), `event_type`, `message`, `context` (json).
   - Fully ready for audit trail and UI timeline rendering.

5. **`integration_settings` table & service** (`app/Services/IntegrationSettingsService.php`):
   - Encrypted credentials storage in database (`encrypted:array`), with fallback to `.env` config.
   - Comprehensive test suite in `tests/Feature/IntegrationSettingsTest.php` is already passing (33 tests, 140 assertions).

### 2.2 Current Publishing Logic Audit
- **`PostController`** (`app/Http/Controllers/PostController.php:118`):
  - On store/update with `publish_action == 'publish'`, dispatches `PublishPostJob::dispatch($post)`.
  - On `schedule`, dispatches `PublishPostJob::dispatch($post)->delay($post->scheduled_at)`.
- **`PublishPostJob`** (`app/Jobs/PublishPostJob.php`):
  - Directly creates `new WordPressPublisher` and `new FacebookPublisher`.
  - Runs WordPress first, updates target. Then runs Facebook, updates target.
  - Updates post status.
  - Does NOT log `PublishAttempt` or `PublishEvent`.
  - Does NOT retry on failure with backoff.
  - Does NOT decouple execution across channels.
- **Stubbed Components**:
  - `app/Jobs/PublishWordPressJob.php` — Empty skeleton.
  - `app/Jobs/PublishFacebookJob.php` — Empty skeleton.
  - `app/Services/Publishers/PublishingService.php` — Empty class.
  - `app/Exceptions/PublishException.php` — Empty exception.

---

## 3. R1 Architecture: Queue & Publisher Orchestration

### 3.1 Orchestration Workflow via `PublishingService`

The orchestration must balance two critical operational concerns:
1. **Decoupled execution**: WordPress and Facebook failures should not block or contaminate each other. Manual retry of Facebook must not re-publish WordPress.
2. **Data Dependency**: If a post targets both WordPress and Facebook, Facebook caption can embed `{wordpress_url}`. Therefore, when both are selected, WordPress should publish first so Facebook can reference the resolved `external_url`.

#### Workflow Execution Graph
```
[PostController / Scheduled Dispatch]
                 │
                 ▼
     PublishingService::dispatchPost(Post $post)
                 │
        ┌────────┴──────────────────────────┐
        │ Both Channels or WP only          │ FB only
        ▼                                   ▼
[PublishWordPressJob]              [PublishFacebookJob]
        │
  ┌─────┴────────────────┐
  │ Success / Failure     │
  ▼                       ▼
Update Target       Record Attempt/Event
  │                       │
  └───────────┬───────────┘
              ▼
    PublishingService::onWordPressFinished(Post $post)
              │
    ┌─────────┴─────────────────────┐
    │ Has FB target pending/retry?  │ No other targets
    ▼                               ▼
[PublishFacebookJob]       PublishingService::updatePostStatus()
```

#### Manual Channel Retry Flow
When a user clicks "Retry" for a specific target on the UI:
1. `POST /publish-targets/{target}/retry` invokes `PublishingService::retryTarget(PublishTarget $target)`.
2. Target status is set to `pending`, `error_message` is cleared.
3. Only the corresponding job (`PublishWordPressJob` or `PublishFacebookJob`) is dispatched.
4. The other channel is untouched.

---

### 3.2 Idempotency via `external_id` (Remote Verify / Update vs Duplicate)

A central requirement (Acceptance Criterion 1) is:
> *Programmatic check: `Http::fake()` tests prove that if a post already has an `external_id`, retrying the job updates the remote post instead of creating a duplicate.*

#### Remote Interaction Rules
Each publisher (`WordPressPublisher`, `FacebookPublisher`) and job must follow strict idempotency gates:

| Channel | Condition | Action | HTTP Request | Prevented Failure Mode |
|---|---|---|---|---|
| **WordPress** | `$target->external_id == null` | Create | `POST /wp-json/wp/v2/posts` | N/A |
| **WordPress** | `$target->external_id != null` | Update | `POST /wp-json/wp/v2/posts/{external_id}` | Prevents duplicate WordPress post on worker retry |
| **Facebook** | `$target->external_id == null` | Create | `POST /{page_id}/feed` (or photo upload + feed) | N/A |
| **Facebook** | `$target->external_id != null` | Update | `POST /{external_id}` (updates `message` field) | Prevents duplicate Facebook feed post on worker retry |

#### Edge Case: Remote Resource Deleted (404 Not Found)
If `update` returns HTTP 404 (WordPress `rest_post_invalid_id` or Facebook `GraphMethodException` / code 100 object does not exist):
- `PublishFailureClassifier` flags this as `non_retryable` (`not_found`).
- The job marks the target as `failed` with an explicit Thai explanation: `"ไม่พบบทความเดิมบนแพลตฟอร์มปลายทาง (อาจถูกลบไปแล้ว) กรุณาล้าง External ID หากต้องการโพสต์ใหม่"`.

---

### 3.3 Timeouts and Exponential Backoff Configuration

#### 1. HTTP Client Timeouts
Under Laravel's `Illuminate\Support\Facades\Http`:
- **WordPress**:
  - `connectTimeout(5)`: 5 seconds max for DNS and TCP handshake.
  - `timeout(20)`: 20 seconds for standard post creation/updating.
  - Media upload: `timeout(30)`.
- **Facebook Graph API**:
  - `connectTimeout(5)`: 5 seconds.
  - `timeout(15)`: 15 seconds for text/feed post.
  - Photo upload: `timeout(30)`.

#### 2. Queue Connection & Job Timeouts
In `.env`, `DB_QUEUE_RETRY_AFTER=120` (120 seconds).
To prevent queue worker race conditions (where a worker is still processing a job when `retry_after` expires, causing another worker to pick up the same job):
- `PublishWordPressJob::$timeout = 60;` (Must be strictly less than `retry_after = 120`)
- `PublishFacebookJob::$timeout = 60;`
- `PublishWordPressJob::$tries = 3;`
- `PublishFacebookJob::$tries = 3;`

#### 3. Exponential Backoff Schedule
In `PublishWordPressJob` and `PublishFacebookJob`:
```php
/**
 * Calculate the number of seconds to wait before retrying the job.
 * Attempt 1: 10s backoff
 * Attempt 2: 30s backoff
 * Attempt 3: 90s backoff
 *
 * @return array<int, int>
 */
public function backoff(): array
{
    return [10, 30, 90];
}
```

---

### 3.4 `PublishFailureClassifier` & `PublishFailure` Specification

The classifier receives any exception, HTTP response, or error payload and produces a normalized `PublishFailure` value object.

#### Contract: `PublishFailure` Value Object
```php
namespace App\Services\Publishers;

class PublishFailure
{
    public function __construct(
        public readonly bool $isRetryable,
        public readonly string $errorType,     // e.g. auth_failed, rate_limited, network_timeout, server_error, validation_error, permission_denied
        public readonly ?string $errorCode,   // e.g. "190", "rest_cannot_create", "500", "TIMEOUT"
        public readonly ?int $httpStatus,     // e.g. 401, 403, 500
        public readonly string $userMessage,  // Thai message for UI
        public readonly string $technicalMessage, // Sanitized log message
        public readonly array $metadata = [],
    ) {}
}
```

#### Classification Matrix

| Platform | Condition / Error Pattern | `isRetryable` | `errorType` | `errorCode` | User Message (Thai) |
|---|---|:---:|---|---|---|
| **Facebook** | Code `190` (OAuthException / Subcodes 463, 467, 490) | **`false`** | `auth_failed` | `190` | `สิทธิ์ Facebook ไม่เพียงพอ หรือ Token หมดอายุ กรุณาเชื่อมต่อใหม่ในการตั้งค่า` |
| **Facebook** | Code `102` (Session expired) | **`false`** | `auth_failed` | `102` | `เซสชัน Facebook หมดอายุ กรุณาเข้าสู่ระบบใหม่` |
| **Facebook** | Code `200`–`299` (Permissions error, e.g. missing `pages_manage_posts`) | **`false`** | `permission_denied` | `200` | `สิทธิ์ Facebook ไม่เพียงพอในการโพสต์ลงเพจ (โปรดตรวจสอบ Pages permissions)` |
| **Facebook** | Code `100` (Invalid parameter / unsupported media) | **`false`** | `invalid_parameter` | `100` | `พารามิเตอร์หรือข้อมูลสำหรับโพสต์ Facebook ไม่ถูกต้อง` |
| **Facebook** | Code `368` (Temporarily blocked by spam/policy filter) | **`false`** | `policy_violation` | `368` | `การโพสต์ถูกระงับชั่วคราวเนื่องจากนโยบายความปลอดภัยของ Facebook` |
| **Facebook** | Code `4`, `17`, `32`, `613` (Rate limit) | **`true`** | `rate_limited` | e.g. `4` | `เกินขีดจำกัดการเรียกใช้งาน Facebook API ชั่วคราว กำลังลองใหม่ในภายหลัง` |
| **Facebook** | Code `1`, `2` (Temporary service error) | **`true`** | `server_error` | `1` | `เกิดข้อผิดพลาดชั่วคราวจากเซิร์ฟเวอร์ Facebook กำลังลองใหม่` |
| **Facebook** | HTTP 500, 502, 503, 504 | **`true`** | `server_error` | `500` | `เซิร์ฟเวอร์ Facebook ขัดข้องชั่วคราว กำลังลองใหม่` |
| **WordPress** | HTTP 401 / 403 (`rest_cannot_create`, `rest_forbidden`) | **`false`** | `auth_failed` | `401` | `การยืนยันตัวตน WordPress ล้มเหลว หรือไม่มีสิทธิ์สร้างโพสต์` |
| **WordPress** | HTTP 400 (`rest_invalid_param`) | **`false`** | `validation_error` | `400` | `ข้อมูลโพสต์ไม่ถูกต้องสำหรับ WordPress` |
| **WordPress** | HTTP 404 (`rest_post_invalid_id`) | **`false`** | `not_found` | `404` | `ไม่พบบทความเดิมบน WordPress (อาจถูกลบไปแล้ว)` |
| **WordPress** | HTTP 429 | **`true`** | `rate_limited` | `429` | `ส่งคำขอไปยัง WordPress ถี่เกินไป กำลังลองใหม่ในภายหลัง` |
| **WordPress** | HTTP 500, 502, 503, 504 | **`true`** | `server_error` | `500` | `เซิร์ฟเวอร์ WordPress ขัดข้องชั่วคราว (HTTP {status})` |
| **Both** | `ConnectionException`, `ConnectException`, cURL timeout | **`true`** | `network_timeout` | `TIMEOUT` | `การเชื่อมต่อไปยัง {platform} หมดเวลาหรือไม่สามารถติดต่อได้ กำลังลองใหม่` |

#### Security & Redaction Rule
Before storing `technicalMessage`, `error_message`, or `metadata` in `publish_attempts` or `publish_events`:
`PublishFailureClassifier::sanitize(string $text): string` regex-replaces:
- Access tokens: `EAAP[a-zA-Z0-9]+` -> `[REDACTED_FACEBOOK_TOKEN]`
- Passwords in URLs/params: `password=[^&]+` -> `password=[REDACTED]`
- Bearer / Basic tokens: `Authorization:\s*(Basic|Bearer)\s+[A-Za-z0-9+/=._-]+` -> `Authorization: $1 [REDACTED]`

---

## 4. R5 Architecture: Worker & Deployment Specs

### 4.1 Windows Auto-Start Script (`scripts/start-worker.ps1`)

In the local environment (Windows with Laragon), `php` is located at `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe` and is not necessarily on the system-wide PATH.

The script `scripts/start-worker.ps1` must:
1. Dynamically discover the PHP executable:
   - Check if `Get-Command php` succeeds.
   - If not, probe `C:\laragon\bin\php\*\php.exe` and select the highest installed version.
2. Verify project root and database connectivity.
3. Run the queue worker with production-like constraints:
   `& $phpPath artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=90 --max-time=3600`
4. Provide an automatic restart loop if the process exits due to memory limit or `--max-time`, allowing graceful Ctrl+C exit.

```powershell
# scripts/start-worker.ps1 snippet
param (
    [string]$Queue = "default",
    [int]$Timeout = 90
)

$phpPath = $null
if (Get-Command php -ErrorAction SilentlyContinue) {
    $phpPath = "php"
} else {
    $laragonPhp = Get-ChildItem -Path "C:\laragon\bin\php" -Filter "php.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -Last 1
    if ($laragonPhp) {
        $phpPath = $laragonPhp.FullName
    }
}

if (-not $phpPath) {
    Write-Error "PHP executable could not be found. Please ensure PHP is in PATH or installed in C:\laragon\bin\php."
    exit 1
}

Write-Host "Starting Poster Queue Worker using: $phpPath" -ForegroundColor Green
while ($true) {
    & $phpPath artisan queue:work database --queue=$Queue --sleep=3 --tries=3 --timeout=$Timeout --max-time=3600
    Start-Sleep -Seconds 2
}
```

### 4.2 Production Linux (Supervisor) Configuration & Docs

File: `deployment/supervisor/poster-worker.conf`
Documentation: `docs/production-worker.md`

```ini
[program:poster-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/poster/artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=90 --max-time=3600 --max-jobs=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/poster/storage/logs/worker.log
stopwaitsecs=3600
```

**Key Operational Safeguards Documented**:
- `stopwaitsecs=3600`: Supervisor waits for current running job to complete before sending `SIGKILL`.
- `max-time=3600` and `max-jobs=1000`: Workers periodically restart to eliminate PHP memory leaks.
- Deploy hook integration: `php artisan queue:restart` gracefully cycles workers on every deployment.

### 4.3 Stale Processing Job Recovery Command & Scheduler

#### The Problem
If a server reboots, a Docker container is killed, or a PHP worker suffers a hard segmentation fault while executing a job:
1. `publish_targets.status` remains `'processing'` indefinitely.
2. `posts.status` remains `'publishing'` indefinitely.
3. The UI indicates endless progress, and manual retries may be locked out.

#### Recovery Command: `poster:recover-stale-jobs`
File: `app/Console/Commands/RecoverStaleJobsCommand.php`
Signature: `poster:recover-stale-jobs {--timeout=15 : Minutes before a processing target is deemed stale}`

#### Recovery Logic
1. Find all `PublishTarget` where `status = 'processing'` and `updated_at < now()->subMinutes($timeout)`.
2. For each stale target:
   - If `retry_count < 3`:
     - Set target `status = 'pending'`.
     - Increment `retry_count = retry_count + 1`.
     - Log `PublishEvent`: `level = 'warning'`, `event_type = 'stale_job_recovered'`, `message = "งานค้างในสถานะ processing นานกว่า {$timeout} นาที ระบบทำการคืนคิวเพื่อลองใหม่"`.
     - Dispatch corresponding job (`PublishWordPressJob` or `PublishFacebookJob`).
   - If `retry_count >= 3`:
     - Set target `status = 'failed'`, `error_message = "งานหมดเวลาและค้างในสถานะ processing เกินจำนวนครั้งที่กำหนด"`.
     - Log `PublishEvent`: `level = 'error'`, `event_type = 'stale_job_abandoned'`.
3. Check all `Post` where `status = 'publishing'`:
   - If all targets have reached terminal state (`published` or `failed`), invoke `PublishingService::updatePostStatus($post)`.

#### Scheduler Registration (`routes/console.php`)
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('poster:recover-stale-jobs --timeout=15')
    ->everyTenMinutes()
    ->withoutOverlapping();
```

---

## 5. Detailed Class Signatures & File Architecture

### 5.1 Files to Create

1. `app/Services/Publishers/PublishFailure.php`
2. `app/Services/Publishers/PublishFailureClassifier.php`
3. `app/Console/Commands/RecoverStaleJobsCommand.php`
4. `scripts/start-worker.ps1`
5. `deployment/supervisor/poster-worker.conf`
6. `docs/production-worker.md`
7. `tests/Unit/PublishFailureClassifierTest.php`
8. `tests/Feature/PublishWordPressJobTest.php`
9. `tests/Feature/PublishFacebookJobTest.php`
10. `tests/Feature/RecoverStaleJobsTest.php`

### 5.2 Files to Modify

1. `app/Exceptions/PublishException.php`
2. `app/Services/Publishers/PublishingService.php`
3. `app/Jobs/PublishWordPressJob.php`
4. `app/Jobs/PublishFacebookJob.php`
5. `app/Jobs/PublishPostJob.php`
6. `app/Services/Publishers/WordPressPublisher.php`
7. `app/Services/Publishers/FacebookPublisher.php`
8. `routes/console.php`

---

### 5.3 Class Specifications

#### `App\Services\Publishers\PublishFailureClassifier`
```php
namespace App\Services\Publishers;

use Illuminate\Http\Client\Response;
use Throwable;

class PublishFailureClassifier
{
    public function classify(string $channel, Throwable|Response|array|string $error): PublishFailure;
    protected function classifyWordPress(Throwable|Response|array|string $error): PublishFailure;
    protected function classifyFacebook(Throwable|Response|array|string $error): PublishFailure;
    public function sanitize(string $text): string;
}
```

#### `App\Services\Publishers\PublishingService`
```php
namespace App\Services\Publishers;

use App\Models\Post;
use App\Models\PublishAttempt;
use App\Models\PublishEvent;
use App\Models\PublishTarget;

class PublishingService
{
    public function __construct(
        protected PublishFailureClassifier $classifier,
    ) {}

    public function dispatchPost(Post $post): void;
    public function retryTarget(PublishTarget $target): void;
    public function onWordPressFinished(Post $post): void;
    public function updatePostStatus(Post $post): void;
    public function recordAttempt(
        Post $post,
        PublishTarget $target,
        string $correlationId,
        int $attemptNumber,
        string $status,
        ?PublishFailure $failure = null,
        ?int $durationMs = null,
        array $metadata = []
    ): PublishAttempt;
    public function logEvent(
        Post $post,
        ?PublishTarget $target,
        string $level,
        string $eventType,
        string $message,
        ?array $context = null,
        ?string $correlationId = null
    ): PublishEvent;
}
```

#### `App\Jobs\PublishWordPressJob`
```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishWordPressJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 3;
    public int $maxExceptions = 1;

    public function __construct(
        public int $publishTargetId,
        public ?string $correlationId = null
    ) {}

    public function uniqueId(): string;
    public function backoff(): array;
    public function handle(PublishingService $service, WordPressPublisher $publisher): void;
    public function failed(?\Throwable $exception): void;
}
```

#### `App\Jobs\PublishFacebookJob`
```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishFacebookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 3;
    public int $maxExceptions = 1;

    public function __construct(
        public int $publishTargetId,
        public ?string $correlationId = null
    ) {}

    public function uniqueId(): string;
    public function backoff(): array;
    public function handle(PublishingService $service, FacebookPublisher $publisher): void;
    public function failed(?\Throwable $exception): void;
}
```

---

## 6. Verification and Acceptance Criteria Testing Plan

To ensure 100% compliance with `ORIGINAL_REQUEST.md`:

| Requirement | Acceptance Test | Implementation Method |
|---|---|---|
| **Idempotency** (WP) | `PublishWordPressJobTest::test_retry_updates_remote_post_when_external_id_exists` | `Http::fake()`: Assert `POST /wp-json/wp/v2/posts/{id}` is called; assert `POST /wp-json/wp/v2/posts` (create) is NOT called. |
| **Idempotency** (FB) | `PublishFacebookJobTest::test_retry_updates_feed_post_when_external_id_exists` | `Http::fake()`: Assert `POST /{id}` is called; assert `POST /{page_id}/feed` is NOT called. |
| **Non-retryable Error** | `PublishFacebookJobTest::test_fb_code_190_fails_target_immediately_with_zero_retries` | `Http::fake()` with Facebook error code 190. Run job. Assert job is not released back to queue, target marked `failed`, attempt logged as `retryable = false`. |
| **Thai Error Message** | `PublishFailureClassifierTest::test_facebook_190_maps_to_readable_thai_message` | Assert `userMessage` contains `"สิทธิ์ Facebook ไม่เพียงพอ หรือ Token หมดอายุ"`. |
| **Security Sanitization** | `PublishFailureClassifierTest::test_credentials_are_redacted_from_error_logs` | Pass response with `EAAP...` token and app passwords. Assert redacted strings in logged messages. |
| **Stale Job Recovery** | `RecoverStaleJobsTest::test_stale_processing_jobs_are_reset_or_failed` | Seed target with `status = 'processing'` and `updated_at = 20 minutes ago`. Run artisan command. Assert target recovered or marked failed. |

---

## 7. Migration & Schema Recommendation

To avoid MySQL strict mode errors when setting `status = 'detached'` (found in `PostController.php:203`) and to support `retrying` status for real-time progress:
- Recommend adding a migration: `database/migrations/2026_10_02_040000_alter_status_column_on_publish_targets_table.php` to change `status` from `enum(['pending', 'processing', 'published', 'failed'])` to `string('status', 32)->default('pending')`.
