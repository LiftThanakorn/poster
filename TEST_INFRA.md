# TEST INFRASTRUCTURE & SPECIFICATION: POSTER APPLICATION

## 1. Test Philosophy & Architectural Principles

The test suite for the **Poster** application follows an **opaque-box, requirement-driven, zero-internal-coupling** methodology. This guarantees that tests validate system contracts, observable behavior, and business outcomes rather than private implementation details.

### Core Principles
1. **Opaque-Box Boundary Testing**:
   - Assertions are made exclusively through public HTTP endpoints, Artisan console commands, queued job executions, database state changes, and intercepted external HTTP communications via `Http::fake()`.
   - Never mock internal service methods or couple tests to private variables.
2. **Deterministic External Isolation**:
   - All external HTTP interactions (WordPress REST API, Facebook Graph API, external CDNs) are strictly intercepted using Laravel's `Http::fake()`.
   - Zero outbound network requests are permitted during test execution.
3. **Idempotency Guarantees**:
   - Verify that any publishing action or retry where a target already has an `external_id` executes a remote **UPDATE** request (e.g., `POST /wp-json/wp/v2/posts/{id}` or `POST /{graph_version}/{post_id}`) and **never** a remote creation request.
4. **Resilience & Failure Classification**:
   - Non-retryable errors (such as Facebook Graph API error code 190 - Invalid/Expired OAuth Access Token, or HTTP 401/403) must immediately mark the target as `failed` with zero automatic retries.
   - Retryable errors (HTTP 500, 429, connection timeouts) must trigger exponential backoff (`[10, 30, 90]`) with accurate attempt logging.
5. **Zero Token & Secret Leakage**:
   - Enforce database credential encryption.
   - Verify that serialized models and frontend API responses (Inertia props, JSON payloads) never expose plaintext passwords, application passwords, or access tokens.

---

## 2. Feature Inventory Mapping (Features 1 - 20)

| # | Feature Key | Category | Description | Primary Verification Boundary |
|---|-------------|----------|-------------|-------------------------------|
| F01 | `model_hidden_encryption` | Security | `IntegrationSetting` hides `encrypted_credentials` in serialization | Model `toArray()`, `toJson()`, Eloquent query serialization |
| F02 | `secret_sanitizer` | Security | `SecretSanitizer` redacts tokens, passwords, and secrets | Utility output, log sanitize checks, exception trace sanitization |
| F03 | `bearer_token_auth` | Security | Facebook API calls pass tokens via `Authorization: Bearer` headers | `Http::fake()` recorded request headers |
| F04 | `integration_health_checks` | Health | Health probes for Facebook permissions and WordPress REST API | `POST /settings/integrations/check/{channel}`, service health check |
| F05 | `target_status_migration` | Database | `publish_targets.status` supports 'retrying', 'detached', 'queued', etc. | Database column length and status writes |
| F06 | `failure_classifier_thai` | Error Handling | Error classification (retryable vs non-retryable) with Thai message mapping | `PublishFailureClassifier` return contracts, attempt error messages |
| F07 | `decoupled_channel_jobs` | Queue | `PublishWordPressJob` & `PublishFacebookJob` decoupled execution | Queue dispatching, job execution, timeouts, backoff arrays |
| F08 | `publishing_orchestration` | Orchestration | `PublishingService` sequences WP first for `{wordpress_url}`, then FB | Queue sequence, payload replacement `{wordpress_url}` |
| F09 | `publishing_idempotency` | Reliability | When `external_id` is present, execute remote update, not duplicate | `Http::fake()` URL and method inspection for target with `external_id` |
| F10 | `immediate_failure_code_190` | Reliability | Facebook code 190 causes immediate failure with 0 auto-retries | Target status `failed`, attempt count = 1, retry job not queued |
| F11 | `windows_worker_script` | Operations | PowerShell worker script (`scripts/start-worker.ps1`) structure & flags | Script syntax, parameter handling, Laragon PHP path discovery |
| F12 | `linux_supervisor_doc` | Operations | Supervisor configuration and operational guide | File existence, program configuration parameters, queue names |
| F13 | `stale_job_recovery` | Maintenance | Artisan command `poster:recover-stale-jobs` clears jobs > 15 min | Artisan command exit code, database target status transition |
| F14 | `stepper_progress_contract` | Frontend API | Stepper status contract (`GET /posts/{post}/status`) | JSON response structure: `id`, `status`, `targets`, `updated_at` |
| F15 | `timeline_audit_contract` | Frontend API | Timeline audit modal contract (`GET /posts/{post}/timeline`) | JSON response structure: `attempts`, `events`, attempt details |
| F16 | `manual_channel_retry` | Actions | Single channel retry endpoint (`POST /posts/{post}/retry/{channel}`) | Isolated channel reset to `pending`/`queued`, published channel untouched |
| F17 | `autosave_drafts` | Actions | Autosave endpoint (`POST/PUT /posts/autosave` or `/posts/{post}/autosave`) | Draft persistence, title, content updates without triggering publish |
| F18 | `duplicate_post_action` | Actions | Duplicate post endpoint (`POST /posts/{post}/duplicate`) | Clone post, new draft status, copy media, reset targets |
| F19 | `facebook_media_pdf_exclusion` | Media/Validation| Multi-layer filter excluding PDF attachments when FB is selected | Request validation failure (422) or controller exclusion for FB + PDF |
| F20 | `near_realtime_polling` | Realtime API | Polling endpoint lifecycle (active states vs terminal states) | Target and Post status contract for active vs terminal states |

---

## 3. Four-Tier Testing Methodology

The E2E test suite is constructed across 4 rigorous testing tiers:

### Tier 1: Category-Partition Testing (Equivalence Partitioning)
- **Goal**: Partition input and state domains into discrete equivalence classes and verify valid and invalid partitions.
- **Coverage Requirement**: $\ge 5$ test cases per feature.
- **Partitions Tested**:
  1. *Authentication & Access*: Unauthenticated, unauthorized, authenticated owner, authenticated non-owner.
  2. *Payload Variations*: Minimal valid, maximum length valid, empty mandatory, malformed types, missing relations.
  3. *Channel Combinations*: Single channel (WordPress only, Facebook only), dual channel (WordPress + Facebook), empty channels.
  4. *Integration States*: Connected, unconfigured, expired token, incorrect credentials, server unreachable.
  5. *Target States*: `pending`, `queued`, `processing`, `retrying`, `published`, `failed`, `detached`.
  6. *Job Execution Results*: Success (200/201), Client Error (400/401/403/404), Server Error (500/502/503), Network Timeout.

### Tier 2: Boundary Value Analysis (BVA)
- **Goal**: Stress extreme boundaries, edge limits, zero conditions, and threshold transitions.
- **Coverage Requirement**: $\ge 5$ test cases per feature.
- **Boundaries Tested**:
  1. *Retry Limits & Delays*: 0 retries (immediate failure for code 190), 1st retry (10s), 2nd retry (30s), 3rd retry (90s), max tries exceeded.
  2. *Timeout Thresholds*: 60s job timeout boundary, 15-minute stale recovery threshold (14m 59s untouched vs 15m 01s recovered).
  3. *Payload Lengths*: Empty title (0 chars), 1 char title, 255 char boundary, 256 char boundary (validation rejection).
  4. *Media Exclusions*: JPEG/PNG/WebP (valid for FB & WP), PDF (valid for WP only, rejected/excluded for FB).
  5. *URL Replacements*: Content with zero `{wordpress_url}`, single `{wordpress_url}`, multiple `{wordpress_url}`, and unresolved `{wordpress_url}` on WP failure.
  6. *String Sanitization*: Raw passwords, passwords with special characters, tokens embedded in stack traces, URL encoded tokens.

### Tier 3: Pairwise Combinatorial Testing
- **Goal**: Systematic coverage of multi-factor interaction combinations between channels, network responses, and post states.
- **Matrix Dimensions**:
  - `Channel 1 (WordPress)`: [Success, 500 Retryable, 401 Non-Retryable, Timeout]
  - `Channel 2 (Facebook)`: [Success, Code 190 Non-Retryable, 429 Retryable, Timeout]
  - `External ID State`: [Null (New Publish), Present (Idempotent Update)]
  - `Media Type`: [None, Image (JPEG/PNG), Document (PDF)]
  - `Post Action`: [Immediate Publish, Scheduled Publish, Autosave Draft]
- **Key Pairwise Interactions Verified**:
  - WP Success (generates URL) + FB with `{wordpress_url}` placeholder -> Verifies URL replacement and FB post success.
  - WP 500 (Retryable) + FB Pending -> Verifies WP schedules retry without corrupting FB target.
  - WP Success + FB Code 190 -> Verifies WP is `published`, FB is `failed` immediately, post is `partially_published`.
  - Retry on WP Target only when FB already `published` -> FB target is untouched and NOT re-dispatched.
  - Post with PDF + WordPress + Facebook -> WordPress succeeds with PDF, Facebook excludes PDF or validates restriction.

### Tier 4: Real-World Application Scenarios (Comprehensive End-to-End)
- **Goal**: Full lifecycle simulations reflecting complete production user journeys.
- **Coverage Requirement**: $\ge 5$ realistic end-to-end scenarios.
- **Scenarios**:
  1. **Scenario 1 — Complete Dual-Channel Publishing Workflow**:
     - User creates post with image, selects WordPress & Facebook, content includes `{wordpress_url}`.
     - Post queued -> WordPress published (returning external_id `101` and URL `https://wp.example.com/post-101`) -> Facebook published (attaching media and replacing `{wordpress_url}`).
     - Targets both `published`, post status `published`, timeline events recorded.
  2. **Scenario 2 — Facebook Code 190 Non-Retryable Failure & Isolated Recovery**:
     - User publishes to WordPress and Facebook.
     - WordPress succeeds. Facebook returns Graph error 190 (Token Expired).
     - Facebook target immediately transitions to `failed` (0 retries), Thai error mapped ("สิทธิ์ Facebook ไม่เพียงพอ หรือ Token หมดอายุ").
     - User updates Facebook token in settings, clicks manual retry for Facebook channel only.
     - Only Facebook job re-executes and succeeds; WordPress is never re-published. Post reaches `published`.
  3. **Scenario 3 — Publishing Idempotency Under Network Retry**:
     - Target has existing `external_id = "fb_post_999"`.
     - Worker retries job.
     - Request made to Facebook is `POST /v26.0/fb_post_999` (Update) and NEVER `POST /v26.0/{page_id}/feed` (Create).
     - Database target retains existing `external_id` without creating duplicate remote resources.
  4. **Scenario 4 — Zombie Job Stale Recovery & Recovery Command**:
     - Target stuck in `processing` with `updated_at` 20 minutes ago (simulating worker crash/SIGKILL).
     - Artisan `poster:recover-stale-jobs` runs.
     - Target marked as `failed` with timeout message, audit event logged, post status updated.
  5. **Scenario 5 — Full Editorial Flow (Autosave, Duplicate, Media Filtering)**:
     - User drafts post with PDF media, autosave triggers every 3s preserving draft state.
     - User attempts to publish PDF to Facebook -> prevented or filtered.
     - User duplicates post -> cloned post created with fresh `draft` status, targets reset, original post intact.

---

## 4. Test Suite Structure & File Layout

```
tests/Feature/E2E/
├── SecurityAndHealthE2ETest.php            # Features 1, 2, 3, 4 (Security, Encryption, Sanitizer, Health)
├── PublishingOrchestrationE2ETest.php       # Features 6, 7, 8, 9, 10 (Classifier, Jobs, Sequence, Idempotency, Code 190)
├── WorkerAndRecoveryE2ETest.php             # Features 5, 11, 12, 13 (Status migration, Scripts, Stale Recovery)
├── FrontendApiAndWorkflowE2ETest.php        # Features 14, 15, 16, 17, 18, 19, 20 (Stepper, Timeline, Retry, Autosave, Duplication, PDF)
└── RealWorldScenariosE2ETest.php            # Tiers 3 & 4 Complex Pairwise & Real-World End-to-End Scenarios
```

---

## 5. Test Execution Command

Run the complete E2E test suite:
```powershell
& "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" artisan test --filter=E2E
```
Or filter by test class:
```powershell
& "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" artisan test tests/Feature/E2E/PublishingOrchestrationE2ETest.php
```
