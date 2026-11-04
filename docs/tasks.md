# Implementation Tasks: Dynamic QR Code & Image Editor Integration (Audited)

---

## Phase 1: Environment & Tooling Scaffolding

- [ ] **Task 1.1: Install Backend & Frontend QR Libraries + Public URL Config**
  - **Target Files:** `composer.json`, `package.json`, `config/app.php`, `.env.example`
  - **Description:** Install `endroid/qr-code:^6.0` (locks `6.0.9` + `bacon/bacon-qr-code:v3.1` with native `ext-gd` `PngWriter` and `SvgWriter`) via Composer, install `qrcode:^1.5.4` via npm, and add optional `qr_public_url` (`QR_PUBLIC_BASE_URL`) config fallback.
  - **Verification:** `composer show endroid/qr-code` returns `6.0.x`, and `npm list qrcode` returns `1.5.x`.

- [ ] **Task 1.2: Client-Side QR Generation & Localhost Detection Helper**
  - **Target Files:** `resources/js/Utils/qrcode.js`
  - **Description:** Implement `generateQrDataUrl(url, options)` supporting high-res PNG base64 output (`size: 800`), `errorCorrectionLevel`, `margin`, `darkColor`, `lightColor`, `transparentBg`, and `isLocalhostUrl(url)` warning helper.
  - **Verification:** `npm run build` succeeds without bundle or import errors.

---

## Phase 2: Database Models, Migrations & Seeds

- [ ] **Task 2.1: Create Migrations for `qr_codes` and `qr_code_scans`**
  - **Target Files:** `database/migrations/2026_11_03_000001_create_qr_codes_table.php`, `database/migrations/2026_11_03_000002_create_qr_code_scans_table.php`
  - **Description:** Create `qr_codes` and `qr_code_scans` tables with composite index `(qr_code_id, scanned_at)`, unique `code`, and cascading foreign keys compatible with both MySQL and SQLite `:memory:`.
  - **Verification:** `php artisan migrate` succeeds cleanly.

- [ ] **Task 2.2: Create `QrCode`, `QrCodeScan` Models & `QrCodePolicy`**
  - **Target Files:** `app/Models/QrCode.php`, `app/Models/QrCodeScan.php`, `app/Policies/QrCodePolicy.php`
  - **Description:** Implement models with casts, `scan_url` & `image_url` accessors (Subdirectory `/poster` safe via `route('qr.redirect')`), automatic Cache invalidation (`Cache::forget("qr_code:{$code}")` on `saved`/`deleted` including `getOriginal('code')`), and `QrCodePolicy` (viewable/insertable by any authenticated user; editable/deletable by creator or `super_admin`).
  - **Verification:** `php artisan test --filter=QrCodeModelTest` passes.

- [ ] **Task 2.3: Create Model Factories for Testing**
  - **Target Files:** `database/factories/QrCodeFactory.php`, `database/factories/QrCodeScanFactory.php`
  - **Description:** Create factories with states (`inactive()`, `expired()`) and realistic User-Agent/device scan variations.
  - **Verification:** Factories instantiate and persist cleanly in SQLite `:memory:`.

---

## Phase 3: Backend Services, API Contracts & Public Redirection

- [ ] **Task 3.1: Implement `QrCodeGeneratorService` (`SvgWriter` & `PngWriter`)**
  - **Target Files:** `app/Services/QrCodeGeneratorService.php`
  - **Description:** Implement server-side SVG and GD-based PNG rendering using `endroid/qr-code` v6 with custom foreground/background colors, transparent background support, margin, and ECC levels.
  - **Verification:** Unit test asserting valid `<svg` output and valid `\x89PNG` binary header without requiring `imagick`.

- [ ] **Task 3.2: Implement `QrTelemetryService` (PDPA Hash + Post-Response Terminating Write)**
  - **Target Files:** `app/Services/QrTelemetryService.php`
  - **Description:** Implement User-Agent classification (`mobile`/`tablet`/`desktop`/`bot`, OS, `LINE In-App`/`Facebook In-App`/`Safari`/`Chrome`), HMAC-SHA256 IP hashing, and `scheduleScanRecording()` using `app()->terminating()` (or direct execution in unit tests) wrapped in `try/catch` with atomic `increment('scan_count')`.
  - **Verification:** Unit test confirming accurate UA classification, zero raw IP persistence, and atomic counter increment.

- [ ] **Task 3.3: Implement `SafeRedirectUrl` Rule & Form Requests**
  - **Target Files:** `app/Rules/SafeRedirectUrl.php`, `app/Http/Requests/StoreQrCodeRequest.php`, `app/Http/Requests/UpdateQrCodeRequest.php`
  - **Description:** Enforce `http`/`https` schemes, block `localhost`/`.local`/`.internal` and private/reserved IPv4 & IPv6 literals (plus DNS checks in production without breaking offline tests), and validate slug uniqueness (`^[a-zA-Z0-9_-]{3,64}$`).
  - **Verification:** Unit tests rejecting `javascript:`, `data:`, `http://127.0.0.1`, `http://192.168.1.1`, `http://[::1]`, and accepting valid HTTPS URLs.

- [ ] **Task 3.4: Implement `QrRedirectController` & Inactive Landing View**
  - **Target Files:** `app/Http/Controllers/QrRedirectController.php`, `resources/views/qr/inactive.blade.php`, `routes/web.php`
  - **Description:** Implement `GET /qr/{code}` (`throttle:120,1`) with cached lookup (`qr_code:{code}`), HTTP `302 Found` + `Cache-Control: no-store, no-cache, must-revalidate`, post-response telemetry recording, and polite Thai inactive/expired Blade view.
  - **Verification:** Feature tests verifying 302 redirect, cache invalidation upon `target_url` edit, inactive status page, and 404 handling.

- [ ] **Task 3.5: Implement `QrCodeController` (CRUD, Safe Delete Protection, Analytics, Image Endpoint)**
  - **Target Files:** `app/Http/Controllers/QrCodeController.php`, `routes/web.php`
  - **Description:** Implement Inertia + JSON `index`, `store`, `update`, `destroy` (blocking deletion when referenced in `Post` or `Template` `image_editor_data.elements[].qrCodeId`, matching `MediaController::destroy`), cross-DB SQLite/MySQL compatible `analytics`, and `GET /qr/{code}/image`.
  - **Verification:** Feature tests verifying CRUD, policy enforcement, deletion guard when used in posts/templates, analytics JSON, and SVG/PNG download headers.

---

## Phase 4: UI Components & Image Editor Integration

- [ ] **Task 4.1: Build QR Management Dashboard (`Pages/QrCodes/Index.jsx`)**
  - **Target Files:** `resources/js/Pages/QrCodes/Index.jsx`, `resources/js/Layouts/AuthenticatedLayout.jsx`
  - **Description:** Build `/qr-codes` page implementing Scott Hurff's 5 UI States, summary metric cards, search & status filter, localhost URL warning banner, one-click scan URL copy, usage count badge, and navigation link in `AuthenticatedLayout.jsx`.
  - **Verification:** Page renders cleanly and navigation highlights active state.

- [ ] **Task 4.2: Build Create/Edit, Analytics, and Download Modals**
  - **Target Files:** `resources/js/Components/QrCode/QrCodeFormModal.jsx`, `resources/js/Components/QrCode/QrCodeAnalyticsModal.jsx`, `resources/js/Components/QrCode/QrCodeDownloadModal.jsx`
  - **Description:** Build live-preview create/edit modal (with custom color/margin/ECC & active toggle), analytics modal (daily trend bars, device/browser breakdown, recent scans), and multi-size SVG/PNG download modal.
  - **Verification:** `npm run build` succeeds and modals operate with keyboard `Escape` support.

- [ ] **Task 4.3: Build `QrSelectorPopover` & Integrate into `ImageEditorModal.jsx`**
  - **Target Files:** `resources/js/Components/QrCode/QrSelectorPopover.jsx`, `resources/js/Components/ImageEditorModal.jsx`, `resources/js/Components/PostForm.jsx`, `resources/js/Components/TemplateForm.jsx`
  - **Description:**
    - Add `"เพิ่ม QR Code"` button to `ImageEditorModal` toolbar opening 2-tab `QrSelectorPopover` (Select Existing / Quick Create with 1-click post link suggestion).
    - Render `type === 'qr'` elements via in-memory high-res DataURL (stripping `src` on DB save to avoid JSON bloat while keeping `qrCodeId`, `code`, `scanUrl`, `styling`).
    - Enforce 4-corner-only Konva `Transformer` (`enabledAnchors={['top-left', 'top-right', 'bottom-left', 'bottom-right']}`, min `64×64px`) so QR ratio never distorts.
    - Prevent nested `Escape` key from closing the parent `ImageEditorModal` when `QrSelectorPopover` is open, and await QR image readiness before `saveDesign()` exports PNG.
    - Add sidebar QR inspector (color, quiet-zone margin, transparent bg toggle, inline target URL quick-edit, switch QR).
  - **Verification:** `npm run build` succeeds cleanly; saving design exports sharp PNG and persists lightweight `qr` element metadata in `Post` and `Template`.

---

## Phase 5: End-to-End Verification & Regression Hardening

- [ ] **Task 5.1: Comprehensive Automated Test Suite**
  - **Target Files:** `tests/Feature/DynamicQrCodeFlowTest.php`, `tests/Unit/SafeRedirectUrlTest.php`
  - **Description:** Test full lifecycle: creation, dynamic URL update + immediate cache invalidation, scan telemetry & PDPA IP hashing, inactive/expired handling, SSRF/open-redirect rejection, post/template `image_editor_data` persistence, and deletion protection when QR is used in an editor layout.
  - **Verification:** `php artisan test --compact --filter=DynamicQrCodeFlowTest` and `SafeRedirectUrlTest` pass 100%.

- [ ] **Task 5.2: Pint Formatting, Frontend Production Build & Full Suite Check**
  - **Target Files:** Modified PHP & JSX files
  - **Description:** Run `vendor/bin/pint --dirty --format agent`, `npm run build`, and `php artisan test --compact`.
  - **Verification:** 0 Pint issues, clean Vite production manifest, and 100% pass across all 223+ existing + new tests.
