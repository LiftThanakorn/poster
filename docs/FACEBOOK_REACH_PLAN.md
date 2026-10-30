# Facebook Reach Investigation Plan

## Goal
หาสาเหตุด้วยข้อมูลจริงว่าทำไมโพสต์ Facebook ที่เผยแพร่จาก Poster อาจมี Views/Reach ต่ำกว่าการโพสต์ด้วยมือ โดยไม่เปลี่ยน publishing behavior เดิมจนกว่าจะมี baseline ที่พิสูจน์ได้

## Guardrails
- ห้ามเปลี่ยน flow ปัจจุบันของ `FacebookPublisher` ใน Phase A
- ห้ามสร้าง/ลบโพสต์ Facebook จริงในการทดสอบ
- ทุก Graph API test ต้องใช้ `Http::fake()`
- ห้าม log access token หรือ secret
- จำกัดงานเก็บข้อมูลให้เบา: default ไม่เกิน 20 published Facebook targets ต่อรอบ
- การอ่าน Facebook ต้อง fail gracefully หาก permission/metric ไม่รองรับ
- เก็บ raw diagnostic payload เฉพาะข้อมูลที่ไม่ใช่ secret เพื่อให้ตรวจย้อนหลังได้

## Phase A — Measure before changing

### A1 — Diagnostics backend (ทำตอนนี้)
- [ ] สร้างตาราง/model สำหรับ Facebook diagnostic snapshots
- [ ] Snapshot ผูกกับ `publish_target_id` และเก็บ `external_id`, collected_at, post metadata, insights payload, normalized metrics, error
- [ ] สร้าง service อ่าน Graph API แบบ read-only สำหรับ post metadata + insights
- [ ] ใช้ Graph API version จาก IntegrationSettingsService เดิม
- [ ] ไม่ hard-code สมมติฐานว่า metric ใดต้องมีเสมอ; parse แบบ defensive
- [ ] สร้าง Artisan command `facebook:collect-diagnostics` จำกัดจำนวน target ต่อรอบ
- [ ] default scope: Facebook targets ที่ published และมี external_id ล่าสุด
- [ ] command ต้องสรุป collected / skipped / failed โดยไม่แสดง secret
- [ ] เพิ่ม targeted tests ด้วย `Http::fake()`
- [ ] รัน targeted tests + Pint เฉพาะไฟล์ที่เกี่ยวข้อง

### A2 — Visibility (ทำหลังยืนยัน A1)
- [ ] แสดง latest diagnostics แบบย่อใน Post Detail
- [ ] แสดง post object type / attachment type / collected time / metrics ที่ API ส่งกลับจริง
- [ ] แสดงสถานะ permission/API error แบบอ่านง่าย
- [ ] ไม่เพิ่ม polling ถี่; refresh เมื่อเปิดหน้า/กด refresh diagnostics เท่านั้น

## Phase B — Controlled publishing experiments (ยังไม่ทำ)
- [ ] เพิ่ม experiment flag แบบ explicit ต่อโพสต์
- [ ] B1: current photo + attached_media (control)
- [ ] B2: photo post แต่ไม่ยิง automated comments ทันที
- [ ] B3: delayed comments (ถ้าข้อมูล A ชี้ว่าควรทดสอบ)
- [ ] B4: native link post เฉพาะกรณีที่ผู้ใช้ต้องการ link post
- [ ] เก็บ experiment variant ไว้กับ diagnostics เพื่อเทียบผล
- [ ] ห้ามเปิด variant ใหม่เป็น default ก่อนมีข้อมูลเพียงพอ

## Phase C — Analysis
- [ ] เปรียบเทียบอย่างน้อย 20–50 โพสต์ โดยแยกประเภทเนื้อหา/ช่วงเวลา
- [ ] ดู performance หลัง +2h / +24h / +7d เมื่อ permission รองรับ
- [ ] สรุปว่าอะไรเป็น code fact, correlation และอะไรยังเป็น hypothesis
- [ ] ค่อยตัดสินใจเปลี่ยน default publishing behavior

## Acceptance criteria for A1
1. Existing Facebook publishing tests/behavior ไม่เปลี่ยน
2. Command ทำ read-only Graph calls เท่านั้น
3. ไม่มี real external post ถูกสร้างในการทดสอบ
4. ถ้า insights endpoint ไม่รองรับหรือ permission ไม่พอ ระบบบันทึก error และเดินต่อ target ถัดไป
5. target เดียวสามารถมีหลาย snapshots เพื่อเทียบตามเวลา
6. ไม่มี token/credential อยู่ใน DB snapshot, logs หรือ test output

## Validation result — 2026-10-07
- MySQL migration applied successfully after shortening the composite index name for MySQL's identifier-length limit.
- Targeted verification: 23 tests passed, 111 assertions; Pint passed.
- Live read-only diagnostics found 1 published Facebook target currently stored by Poster.
- Facebook classified that target as `status_type=added_photos` with `attachment_type=photo`.
- Current configured user token grants: `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`, `pages_read_user_content`, `pages_manage_posts`, `pages_manage_engagement`, `public_profile`.
- Current token does **not** grant `read_insights`; therefore Views / Unique Viewers cannot yet be treated as available diagnostics.
- Diagnostics now detects missing `read_insights`, skips insight calls, stores metadata, and records snapshot status as `partial` instead of falsely reporting success.
- Do not start Phase B until `read_insights` is granted and enough baseline snapshots are collected.

## Historical comparison — 2026-10-07
- Pulled 50 recent Page posts read-only from Graph API.
- Poster PR application-tagged posts: 2.
- Other posts: 48.
- Comparing only `status_type=added_photos` + `attachment_type=photo`:
  - Poster PR: 2 posts, reactions=0, comments=1, shares=0, average simple engagement=0.5, median=0.
  - Other photo posts: 45 posts, reactions=554, comments=88, shares=212, average simple engagement=18.98, median=10.
- Comparable manual welcome posts on 2026-10-01 had reaction/comment/share examples:
  - 11/0/1
  - 3/0/1
  - 30/1/7
  - 18/1/6
- Business Suite screenshot confirms distribution gap:
  - Poster PR 2026-10-07: views=9, viewers=2, engagement=2.
  - Poster PR 2026-10-05: views=18, viewers=3, engagement=0.
  - Shared/mobile-status 2026-10-05: views=421, viewers=183, engagement=10.
  - Manual photo result post 2026-10-01: views=3388, viewers=1290, engagement=362.
- Metadata comparison across Poster PR vs manual samples:
  - same `status_type=added_photos`
  - same attachment `media_type=photo`, `type=photo`
  - same Page photo album target pattern
  - `is_hidden=false`, `is_expired=false`
  - the clear exposed difference is `application = Poster PR` on system-created posts; manual posts do not expose this application field.
- Current Poster publishing sequence is two-step:
  1. `POST /{page_id}/photos` with `published=false`
  2. `POST /{page_id}/feed` with `attached_media[0]`
  3. optional immediate comments.
- Highest-priority configuration check before changing publisher: verify whether Meta App `Poster PR` is in Development vs Live mode. Current symptom (API-created posts visible to very few people while manual posts distribute normally) is consistent with Development-mode visibility restrictions reported by multiple current integrations.
- Do not change publishing endpoint yet until App mode is verified.
- If App is already Live, first controlled publishing experiment should compare current two-step attached-media flow against direct single-photo publishing via `/{page_id}/photos` with caption, changing one variable only.
