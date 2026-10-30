# Handoff Report: Backend Architecture Survey (R1 & R5)

**Agent ID**: `teamwork_preview_explorer_survey_backend_1`  
**Timestamp**: 2026-10-02T03:16:00Z  
**Type**: Hard Handoff (Investigation & Survey Complete)

---

## 1. Observation

1. **Current Tables and Migrations**:
   - `posts`: Created in `database/migrations/2026_10_01_042848_create_posts_table.php`, with additions in `2026_10_01_062630_add_wp_category_id_to_posts_table.php` and `2026_10_01_070932_add_fb_comment_to_posts_table.php`. Status enum: `['draft', 'scheduled', 'publishing', 'published', 'partially_published', 'failed']`.
   - `publish_targets`: Created in `database/migrations/2026_10_01_042855_create_publish_targets_table.php:18`:
     `$table->enum('status', ['pending', 'processing', 'published', 'failed'])->default('pending');`
     `$table->unique(['post_id', 'channel']);`
     In `app/Http/Controllers/PostController.php:203`:
     `$post->targets()->whereNotIn('channel', $validated['channels'])->update(['status' => 'detached']);`
     Notice that `'detached'` is not in the MySQL enum values.
   - `publish_attempts`: Created in `database/migrations/2026_10_02_024039_create_publish_attempts_table.php`. Has `status` as a string (`'queued', 'processing', 'published', 'failed', 'retrying'`), `retryable` (boolean), `http_status`, `error_type`, `error_code`, `error_message`, and `correlation_id`.
   - `publish_events`: Created in `database/migrations/2026_10_02_024040_create_publish_events_table.php`. Has `level`, `event_type`, `message`, `context`, `correlation_id`.
   - `jobs` & `failed_jobs`: Created in `database/migrations/0001_01_01_000002_create_jobs_table.php`. Queue driver is `database` (`QUEUE_CONNECTION=database` in `.env`).

2. **Publishing Flow & Stubs**:
   - `app/Jobs/PublishPostJob.php:50-99` runs WordPress and Facebook sequentially in one synchronous handle block. It does not record `PublishAttempt` or `PublishEvent`, does not classify errors, and does not retry with exponential backoff.
   - `app/Jobs/PublishWordPressJob.php` and `app/Jobs/PublishFacebookJob.php` are empty skeletons (`//`).
   - `app/Services/Publishers/PublishingService.php` is an empty class.
   - `app/Exceptions/PublishException.php` is an empty exception stub.
   - `app/Services/Publishers/WordPressPublisher.php:115` and `app/Services/Publishers/FacebookPublisher.php:219` already have rudimentary `update()` methods that accept `$target->external_id`, but they return raw unclassified array structures.

3. **Runtime & Environment**:
   - PHP executable verified at `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe`.
   - Running test command:
     `& "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" artisan test`
     Result: **33 tests passed, 140 assertions** in 39.8s. All existing tests pass.
   - Neither `scripts/start-worker.ps1` nor Supervisor configs exist yet.
   - No custom scheduler commands exist yet in `routes/console.php`.

---

## 2. Logic Chain

1. **Decoupled Queues vs Monolithic Job**:
   - *From Observation 2*: `PublishPostJob` currently couples WordPress and Facebook execution in a single job. If Facebook fails with a 500 error, retrying the whole job would re-evaluate WordPress.
   - *Inference*: We must decompose the workflow into `PublishWordPressJob` and `PublishFacebookJob`. `PublishingService` must coordinate dispatching so that if both channels are targeted, WordPress runs first to populate `external_url` for Facebook's `{wordpress_url}` token, followed by Facebook.

2. **Idempotency via `external_id`**:
   - *From Observation 2 & ORIGINAL_REQUEST.md*: When a job retries, or when a user triggers manual retry, the target may already possess an `external_id` from a prior successful remote creation where the worker crashed or timed out before recording the finished state.
   - *Inference*: In both `PublishWordPressJob` and `PublishFacebookJob`, if `$target->external_id` is present, the job MUST invoke `update()` instead of `publish()`. In PHPUnit with `Http::fake()`, this will be proven by asserting that update endpoints (`POST /wp-json/wp/v2/posts/{id}` and `POST /{page_id}/posts/{id}`) are invoked and create endpoints are not called.

3. **Classification & Immediate Fail vs Retry**:
   - *From Observation 1 & 2*: Non-retryable errors such as Facebook error code 190 (expired token) or HTTP 401/403 should never waste queue worker cycles.
   - *Inference*: `PublishFailureClassifier` must classify errors into `PublishFailure` with `isRetryable: false` for code 190, 100, 200..299, 400, 401, 403, and `isRetryable: true` for 500, 502, 503, 504, 429, and timeouts. When non-retryable, the job immediately marks the target `failed` with 0 retries and calls `$this->fail()`.

4. **Timeouts and Backoff Alignment**:
   - *From Observation 1 (`.env`)*: `DB_QUEUE_RETRY_AFTER=120`.
   - *Inference*: The job execution timeout must be set to `60` seconds (`$timeout = 60`), and HTTP connect timeout to `5`s and request timeout to `20`s. This ensures a job times out and catches errors before Laravel's queue driver releases the reserved job to a concurrent worker. Backoff array `[10, 30, 90]` provides progressive exponential spacing.

5. **R5 Operational Tooling**:
   - *From Observation 3*: Because Laragon installs PHP under `C:\laragon\bin\php\...`, a dedicated `scripts/start-worker.ps1` script is required to resolve PHP dynamically and launch `queue:work` with restart-resilience.
   - *From Observation 1*: Database queue worker crashes can leave `publish_targets` in `processing` indefinitely. A console command `poster:recover-stale-jobs` scheduled every 10 minutes in `routes/console.php` is necessary to recover stale targets (> 15 minutes) or fail them if attempts exceed 3.

---

## 3. Caveats

1. **Target Status Enum vs `detached` / `retrying`**:
   `publish_targets.status` is currently an `enum('pending', 'processing', 'published', 'failed')`. In `PostController.php:203`, the code writes `'detached'`. In MySQL, writing `'detached'` or `'retrying'` requires altering this column to `string(32)` or expanding the enum. We recommend a migration changing it to `string('status', 32)->default('pending')`.
2. **Facebook Post Update Limitation**:
   Facebook Graph API permits updating the `message` of a page post via `POST /{post_id}`, but does NOT permit replacing or modifying already attached photos or media. `FacebookPublisher::update()` correctly updates the text message.
3. **Queue Connection in Tests**:
   `phpunit.xml` configures `QUEUE_CONNECTION=sync` and `DB_CONNECTION=sqlite`. Feature tests for queue jobs can test job execution directly or use `Queue::fake()` for dispatch verification.

---

## 4. Conclusion

The backend architecture for R1 and R5 is thoroughly mapped, with exact class signatures, interfaces, error taxonomy, and operational scripts specified in `survey_report.md`. The design fulfills all acceptance criteria:
1. Idempotency is enforced by routing through `update()` whenever `external_id` exists.
2. Error classification maps retryable vs non-retryable with 0 auto-retries on Facebook error 190.
3. Thai error messages are human-readable and tokens/credentials are scrubbed.
4. Worker scripts for Windows Laragon and Linux Supervisor are specified.
5. Automated stale job recovery command and schedule are ready for implementation.

---

## 5. Verification Method

1. **Verify Existing Test Baseline**:
   Run the test command to verify all 33 tests continue to pass:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" artisan test
   ```
2. **Verify Survey Report Artifact**:
   Inspect `C:\laragon\www\Poster\.agents\teamwork\teamwork_preview_explorer_survey_backend_1\survey_report.md`.
3. **Invalidation Conditions**:
   - If Facebook Graph API deprecates `POST /{post_id}` text updates for page feeds.
   - If MySQL strict mode fails on `publish_targets` status enum without the recommended migration.
