# Milestone 1: Handoff Report — Migration Strategy & Test Specifications

**Author**: Explorer 3 (`teamwork_preview_explorer_m1_3`)  
**Date**: 2026-10-02  
**Parent Conversation ID**: `88ae952d-230f-4b30-8f2a-a0bea6f43ba1`  
**Handoff Type**: Hard (Task Complete)  

---

## 1. Observation

1. **`publish_targets.status` Schema**:
   - File: `C:\laragon\www\Poster\database\migrations\2026_10_01_042855_create_publish_targets_table.php`, line 18:
     ```php
     $table->enum('status', ['pending', 'processing', 'published', 'failed'])->default('pending');
     ```
   - In MySQL production (`poster` database), `DESCRIBE publish_targets` confirmed:
     `Field: status | Type: enum('pending','processing','published','failed') | Null: NO | Key: MUL | Default: pending`
   - Existing index: `publish_targets_status_index` (`MUL` key).
2. **PostController Unhandled Status Bug**:
   - File: `C:\laragon\www\Poster\app\Http\Controllers\PostController.php`, lines 201–203:
     ```php
     $post->targets()
         ->whereNotIn('channel', $validated['channels'])
         ->update(['status' => 'detached']);
     ```
   - Under MySQL strict mode (`STRICT_TRANS_TABLES`), setting `'detached'` violates the enum constraint and produces:
     `SQLSTATE[22001]: String data, right truncated: 1265 Data truncated for column 'status' at row X`.
3. **IntegrationSetting Secret Exposure Risk**:
   - File: `C:\laragon\www\Poster\app\Models\IntegrationSetting.php`, lines 18–22:
     ```php
     protected $casts = [
         'settings' => 'array',
         'encrypted_credentials' => 'encrypted:array',
         'last_checked_at' => 'datetime',
     ];
     ```
   - Omission of `protected $hidden = ['encrypted_credentials'];` allows any Eloquent serialization (`toArray()`, `toJson()`, `json_encode()`) to expose decrypted plaintext credentials.
4. **Facebook Token in URL Query String**:
   - File: `C:\laragon\www\Poster\app\Services\Publishers\FacebookPublisher.php`, lines 46–49:
     ```php
     ->get($this->graphUrl('me'), [
         'fields' => 'id',
         'access_token' => $this->accessToken,
     ]);
     ```
   - Exposes access tokens in HTTP client exception traces and web server access logs.
5. **Baseline Test Suite Status**:
   - Executed: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test`
   - Result: 33 passed, 140 assertions, 0 failures. SQLite `:memory:` configuration in `phpunit.xml`.

---

## 2. Logic Chain

1. **Step 1 (Schema Incompatibility)**: Observation 1 (`status` enum) conflicts with Observation 2 (`PostController` setting `'detached'`) and R4 requirements (setting `'retrying'`). Under strict MySQL mode, this causes immediate query rejection.
2. **Step 2 (Column Type Selection)**: Converting `status` to `string(32)->default('pending')` accommodates all current and anticipated statuses (`pending`, `processing`, `published`, `failed`, `retrying`, `detached`) with negligible storage difference and zero performance degradation on indexed searches.
3. **Step 3 (Safe Rollback Design)**: If `status` contains `'detached'` or `'retrying'`, rolling back via `ALTER TABLE ... MODIFY status ENUM(...)` in MySQL strict mode throws SQL error 1265 (Data truncated). To guarantee a clean rollback, `down()` must execute `DB::table('publish_targets')->whereNotIn('status', ['pending', 'processing', 'published', 'failed'])->update(['status' => 'pending'])` prior to reverting the column.
4. **Step 4 (Empirical Database Verification)**: Verified via tinker on both SQLite in-memory and MySQL 8:
   - SQLite: Altering column with index succeeded; rollback after inserting `'retrying'` succeeded (`DOWN_RESULT: pending`).
   - MySQL: Altering column with index succeeded without index drop; rollback succeeded (`MYSQL_DOWN_RESULT: pending`).
5. **Step 5 (Model Serialization Quarantine)**: Adding `protected $hidden = ['encrypted_credentials'];` to `IntegrationSetting` prevents plaintext exposure during JSON/Array transformations while preserving internal PHP accessor access (`$setting->encrypted_credentials`).
6. **Step 6 (Multi-Vector Secret Sanitization)**: `SecretSanitizer` in `app/Support/SecretSanitizer.php` scrubs query string tokens (`access_token=...`), Bearer authorization headers (`Bearer <token>`), Basic authentication (`Basic <base64>` and `user:pass@host`), JSON passwords (`"password":"..."`), and recursive array keys before errors or attempts are logged or persisted.
7. **Step 7 (Health Check Contract & Scenarios)**: `IntegrationSettingsService::checkFacebookHealth()` and `checkWordPressHealth()` must return structured diagnostic arrays (`status`, `permissions`, `can_publish`, `error`) and handle token expiration (FB code 190), permission errors (FB code 200/283), unauthorized access (WP 401), insufficient author permissions, server errors, and network timeouts cleanly using `Http::fake()`.

---

## 3. Caveats

- **Scope Boundary**: As Explorer, this investigation is strictly read-only; no code files in `app/` or `database/` have been modified directly. All code and test blueprints are provided in `analysis.md` for immediate application by implementers.
- **Rollback Data Mapping**: In the event of a migration rollback, targets in `'retrying'` or `'detached'` status are mapped to `'pending'`. Implementers and operators should note that running queue workers would need to be paused during any production schema rollback.
- **Milestone 2 Dependencies**: Milestone 2 queue jobs (`PublishWordPressJob`, `PublishFacebookJob`) will write `'retrying'` and log `PublishAttempt`/`PublishEvent` records. The migration and `SecretSanitizer` provided in Milestone 1 establish the required prerequisites.

---

## 4. Conclusion

1. **Feature 5 Migration**:
   - Create migration: `database/migrations/2026_10_02_030000_alter_status_column_on_publish_targets_table.php`.
   - `up()`: Change `status` to `string(32)->default('pending')`.
   - `down()`: Pre-normalize non-enum values to `'pending'`, then revert to enum.
2. **Security Hardening**:
   - Add `protected $hidden = ['encrypted_credentials'];` to `app/Models/IntegrationSetting.php`.
   - Create `app/Support/SecretSanitizer.php` supporting both `sanitize(string)` and `sanitizeArray(array)`.
   - Replace query-string tokens with `Http::withToken()` Bearer headers in `FacebookPublisher`.
3. **Integration Health Checks**:
   - Implement `checkFacebookHealth(): array` and `checkWordPressHealth(): array` in `IntegrationSettingsService.php`.
   - Maintain boolean `healthCheck(): bool` on publishers delegating to the service.
4. **Test Suite Implementation**:
   - Create `tests/Feature/SecurityHardeningTest.php` (11 test methods detailed in `analysis.md`).
   - Create `tests/Feature/IntegrationHealthCheckTest.php` (15 test methods detailed in `analysis.md`).

---

## 5. Verification Method

To independently verify the implementation:

1. **Run Migration and Rollback**:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan migrate
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan migrate:rollback --step=1
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan migrate
   ```
   *Invalidation Condition*: Any error during rollback indicating data truncation (`SQLSTATE[22001]`).

2. **Execute Security Hardening Tests**:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/SecurityHardeningTest.php
   ```
   *Invalidation Condition*: Any decrypted secret appearing in `toJson()`, `toArray()`, or sanitized log strings.

3. **Execute Integration Health Check Tests**:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/IntegrationHealthCheckTest.php
   ```
   *Invalidation Condition*: Any unhandled exception or incorrect health status returned when third-party APIs fail.

4. **Execute Full Project Test Suite**:
   ```powershell
   & "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test
   ```
   *Pass Criteria*: All existing 33 tests + new Milestone 1 tests pass with 0 failures.
