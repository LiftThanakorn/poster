# Product Requirements Document (PRD): ระบบ Dynamic QR Code และการเชื่อมต่อในเครื่องมือแก้ไขรูป (Poster Image Editor)

---

## 1. Executive Summary

ผู้ดูแลงานประชาสัมพันธ์และแอดมินองค์กรต้องสร้างโปสเตอร์และสื่อประชาสัมพันธ์ที่มี QR Code เป็นประจำ แต่ QR Code ทั่วไปเป็น Static QR Code ที่เมื่อพิมพ์ลงบนโปสเตอร์หรือโพสต์ไปยัง Facebook/WordPress แล้ว จะไม่สามารถแก้ไขลิงก์ปลายทางได้หากมีการเปลี่ยนลิงก์เอกสาร, เปลี่ยนแบบฟอร์ม หรือลิงก์หมดอายุ อีกทั้งยังไม่สามารถติดตามจำนวนผู้สแกนได้ ระบบนี้จะเพิ่มระบบ **Dynamic QR Code** เต็มรูปแบบในระบบ Poster พร้อมเชื่อมต่อเป็นเครื่องมือแทรกลงใน **เครื่องมือแก้ไขรูป (Image Editor Modal)** โดยตรง เพื่อให้สามารถสร้าง, เลือก, ปรับแต่ง QR Code ลงบนผืนผ้าใบโปสเตอร์ได้ทันที และสามารถแก้ไข URL ปลายทางได้ตลอดเวลาแม้ภาพจะถูกเผยแพร่ไปแล้ว พร้อมเก็บบันทึกสถิติการสแกนและการวิเคราะห์ (Scan Analytics) แบบเรียลไทม์

---

## 2. Goals & Success Metrics

| เป้าหมาย (Goal) | ตัวชี้วัดความสำเร็จ (Success Metric) |
| :--- | :--- |
| **ความยืดหยุ่นของลิงก์ปลายทาง (Zero Broken Links on Published Posters)** | ผู้ใช้สามารถเปลี่ยน `target_url` ของ Dynamic QR Code ได้ทุกเมื่อ โดยภาพโปสเตอร์ที่เผยแพร่บน Facebook, WordPress หรือดาวน์โหลดไปแล้วยังคงสแกนติดและส่งผู้ใช้ไปยังปลายทางใหม่ทันที 100% |
| **การทำงานไร้รอยต่อในเครื่องมือแก้ไขรูป (Seamless Canvas Integration)** | ผู้ใช้สามารถกด "เพิ่ม QR Code" ใน `ImageEditorModal`, เลือก QR เดิม หรือสร้างใหม่พร้อมแทรกลงบน Canvas ได้ในขั้นตอนเดียว โดยใช้เวลาไม่เกิน 10 วินาที |
| **ความเร็วและความแม่นยำในการเปลี่ยนเส้นทาง (High-Performance Redirection)** | การสแกนผ่าน `/qr/{code}` ทำ HTTP 302 Redirection ไปยัง URL ปลายทางได้ภายใน < 50ms โดยไม่หน่วงผู้ใช้ และบันทึกสถิติแบบ Background / Asynchronous |
| **ความปลอดภัยและการปกป้องความเป็นส่วนตัว (PDPA / Privacy Compliance)** | ไม่มีการจัดเก็บ IP Address ดิบของผู้สแกน (ใช้ Salted Hash `ip_hash`), ป้องกัน Open Redirect Vulnerability, และป้องกัน SSRF โดยตรวจสอบ URL ปลายทางอย่างเข้มงวด |
| **การส่งออกภาพความละเอียดสูงที่สแกนติดง่าย (High Scannability & Export Quality)** | QR Code ที่วาดลงบน Konva Canvas คมชัด ไม่แตก (ใช้ High Error Correction Level 'H' หรือ 'Q' และขนาด Canvas Scaling ที่ถูกต้อง) สแกนติดได้ทั้งบนหน้าจอมือถือและสื่อสิ่งพิมพ์ |
| **ความเข้ากันได้กับระบบเดิม (Zero Regression)** | ชุดทดสอบเดิมทั้ง 223 รายการในระบบ Poster ต้องผ่าน 100% โดยไม่กระทบฟังก์ชันจัดการมีเดีย, เทมเพลต, และการเผยแพร่โซเชียลเดิม |

---

## 3. Non-Goals (Out of Scope)

รายการความสามารถที่ตั้งใจ **ไม่รวม** ในขอบเขต MVP รอบนี้ (Shape Up No-Gos):

1. **การสร้าง QR Code แบบ Artwork ซับซ้อนด้วย AI (AI Generative QR Art):** ไม่ใช้โมเดล Stable Diffusion / ControlNet เพื่อสร้างภาพลวดลายซับซ้อนในตัวโค้ด มุ่งเน้นมาตรฐาน QR Code อุตสาหกรรม (ISO/IEC 18004) ที่สแกนติด 100%
2. **ระบบ Geolocation แบบพิกัด GPS แม่นยำระดับเสาเข็ม:** ไม่ขอสิทธิ์ GPS พิกัดละติจูด/ลองจิจูดจากเบราว์เซอร์ผู้สแกน เพื่อหลีกเลี่ยงการขึ้นหน้าต่าง Permission Pop-up ขัดจังหวะการเปิดเว็บ (ติดตามเพียง User-Agent, Device Type, Platform, Browser, Referrer และ Timestamp)
3. **ระบบคิดเงินหรือจำกัดโควตาการสแกน (Paid Subscription / Scan Limiting):** ไม่คิดค่าบริการตามจำนวนครั้งที่สแกน ให้องค์กรใช้งานได้อย่างไม่จำกัด
4. **การพิมพ์ฉลากบาร์โค้ดสินค้า (1D Barcode เช่น EAN-13, Code 128):** ระบบนี้มุ่งเน้น 2D Matrix (QR Code) สำหรับการเชื่อมโยงสื่อดิจิทัลและโปสเตอร์ข่าวสารโดยเฉพาะ

---

## 4. User Stories & Edge Cases

### US-01: ผู้ใช้สร้าง Dynamic QR Code ใหม่จากหน้าจัดการ
* **Given** ผู้ใช้ล็อกอินเข้าสู่ระบบ Poster และอยู่ที่หน้า `จัดการ Dynamic QR Code` (`/qr-codes`)
* **When** ผู้ใช้กดปุ่ม `"สร้าง QR Code ใหม่"`, กรอกชื่อ (เช่น `"ลิงก์ดาวน์โหลดระเบียบการรับสมัคร"`), กรอก URL ปลายทาง (เช่น `https://drive.google.com/...`), และเลือกรหัสสั้น (หรือปล่อยว่างให้ระบบสุ่มรหัส 8 ตัวอักษร เช่น `k7X9pL2a`) แล้วกดบันทึก
* **Then** ระบบสร้างรายการ QR Code ในฐานข้อมูล, แสดงผลภาพ QR Code ตัวอย่าง, แสดง URL สแกน `http://domain/qr/k7X9pL2a`, มีปุ่มคัดลอกลิงก์ และมีปุ่มดาวน์โหลดไฟล์ภาพ PNG / SVG

### US-02: ผู้ใช้แทรก Dynamic QR Code ลงในเครื่องมือแก้ไขรูป (Image Editor Modal)
* **Given** ผู้ใช้อยู่ในหน้าสร้าง/แก้ไขโพสต์หรือเทมเพลต และเปิดใช้งาน `เครื่องมือแก้ไขรูป (ImageEditorModal)`
* **When** ผู้ใช้กดปุ่มเครื่องมือ `"เพิ่ม QR Code"` บนแถบเครื่องมือด้านซ้าย
* **Then** มีหน้าต่างป็อปอัป/โมดัลแสดงขึ้นมา มี 2 แท็บ:
  1. *แท็บ "เลือกจากคลัง QR":* แสดงรายการ QR Code ที่เคยสร้างไว้ พร้อมช่องค้นหาและตัวอย่างภาพ
  2. *แท็บ "สร้างใหม่ทันที":* มีฟอร์มกรอกชื่อและ URL ปลายทาง เพื่อสร้างและแทรกลง Canvas ได้ทันทีโดยไม่ต้องสลับหน้าจอ
* **When** ผู้ใช้เลือก QR Code ที่ต้องการ
* **Then** QR Code จะถูกเพิ่มลงบนผืนผ้าใบ Canvas เป็นวัตถุที่สามารถ ลากย้ายตำแหน่ง, ย่อ/ขยาย (โดยรักษาสัดส่วน 1:1 จัตุรัส), หมุน, ปรับความโปร่งใส, และจัดลำดับ Layer ได้

### US-03: ผู้ใช้สแกน QR Code จากโปสเตอร์ที่เผยแพร่แล้ว
* **Given** โปสเตอร์ที่มี Dynamic QR Code ถูกเผยแพร่ลง Facebook หรือพิมพ์ออกมา
* **When** บุคคลทั่วไปนำโทรศัพท์มือถือมาสแกน QR Code (ซึ่งยิงมาที่ `GET /qr/{code}`)
* **Then** ระบบจะ:
  1. ค้นหาข้อมูล QR Code จาก `code`
  2. ตรวจสอบสถานะ `is_active` และวันหมดอายุ `expires_at`
  3. บันทึกสถิติการสแกน (เวลา, User-Agent, อุปกรณ์, เบราว์เซอร์, Referrer, Salted Hashed IP)
  4. เพิ่มจำนวน `scan_count`
  5. ส่ง HTTP 302 Redirect ไปยัง `target_url` ทันที

### US-04: ผู้ใช้อัปเดต URL ปลายทางของ QR Code ที่เผยแพร่ไปแล้ว
* **Given** โปสเตอร์ถูกแชร์ไปแล้ว แต่ต้องการเปลี่ยนปลายทางจาก Google Drive โฟลเดอร์เดิม ไปยังโฟลเดอร์ประกาศผล
* **When** ผู้ใช้เข้าไปที่หน้าจัดการ QR Code หรือคลิกแก้ไขจากในระบบ, เปลี่ยน `target_url` เป็น URL ใหม่ แล้วกดบันทึก
* **Then** ข้อมูลในฐานข้อมูลอัปเดตทันที และผู้ที่สแกน QR Code เดิมจากโปสเตอร์หลังจากนี้ทั้งหมดจะถูก Redirect ไปยัง URL ใหม่ทันที โดยไม่ต้องแก้ไขหรืออัปโหลดภาพโปสเตอร์ใหม่

---

### Edge Cases & Failure Paths

* **EC-01: ลิงก์ปลายทางไม่ถูกต้อง หรือเสี่ยงต่อ Open Redirect / SSRF**
  * *Condition:* ผู้ใช้กรอก `target_url` ที่เป็น `javascript:...`, `data:...`, หรือ Private IP (เช่น `http://192.168.1.1`, `http://localhost`)
  * *Handling:* Backend Form Request Validation ตรวจสอบด้วย `filter_var(..., FILTER_VALIDATE_URL)`, บังคับโปรโตคอล `http://` หรือ `https://`, และไม่อนุญาต Private/Reserved IP Range ใน Production พร้อมแสดงแจ้งเตือนภาษาไทย
* **EC-02: มีผู้สแกน QR Code ที่ถูกปิดการใช้งาน (`is_active = false`) หรือหมดอายุ (`expires_at < now()`)**
  * *Condition:* QR Code ไม่พร้อมใช้งาน
  * *Handling:* ไม่เกิด HTTP 500 หรือ White Screen แต่แสดงหน้า Landing Page ภาษาไทยอย่างสวยงามว่า *"ขออภัย ลิงก์ QR Code นี้ถูกระงับการใช้งานหรือหมดอายุแล้ว กรุณาติดต่อหน่วยงานต้นสังกัด"*
* **EC-03: มีผู้สแกนด้วยรหัสที่ไม่ปรากฏในระบบ (`404 Not Found`)**
  * *Condition:* ผู้ใช้พิมพ์ URL สั้นผิด หรือรหัสถูกลบไปแล้ว
  * *Handling:* แสดงหน้า 404 สไตล์ของ Poster แจ้งว่าไม่พบรหัส QR Code นี้ในระบบ พร้อมปุ่มกลับหน้าหลัก
* **EC-04: การสแกนจำนวนมากพร้อมกัน (High Concurrent Scans / Traffic Spike)**
  * *Condition:* โพสต์เป็นไวรัล มีคนสแกนพร้อมกันหลักหมื่นครั้งต่อนาที
  * *Handling:* การ Redirect เกิดขึ้นทันที การบันทึกสถิติลงตาราง `qr_code_scans` ทำงานผ่าน Queue Job (`RecordQrScanJob`) หรือ Lightweight Write และเพิ่มตัวนับ `scan_count` ด้วย Atomic Increment `DB::raw('scan_count + 1')` ไม่ให้ฐานข้อมูลเกิด Race Condition
* **EC-05: การบันทึกภาพโปสเตอร์ที่มี QR Code (Canvas Export Tainting)**
  * *Condition:* ภาพ QR Code ถูกโหลดเข้ามาใน Canvas จาก Cross-Origin หรือ External Source ทำให้ฟังก์ชัน `stage.toDataURL()` ล้มเหลวด้วย SecurityError (Tainted Canvas)
  * *Handling:* แหล่งที่มาของรูป QR Code ในเครื่องมือแก้ไขรูปจะใช้ Data URL (`data:image/png;base64,...` หรือ SVG Data URL) หรือ Same-Origin Endpoint (`/qr/{code}/image`) ที่ส่ง `Access-Control-Allow-Origin: *` เพื่อรับประกันว่า Canvas จะไม่มีวันถูก Taint และสามารถ Export เป็นไฟล์ PNG ส่งต่อให้ `uploadMedia()` ได้ 100%

---

## 5. Functional Requirements

### โมดูล 1: Dynamic QR Code Core & Redirection (Backend)
- **F1.1 ตารางฐานข้อมูล `qr_codes`:** จัดเก็บ `id`, `user_id`, `title`, `code` (Unique Index, 8-64 chars), `target_url` (text), `is_active` (boolean, default true), `expires_at` (nullable datetime), `scan_count` (unsigned integer, default 0), `styling` (json nullable สำหรับสี/error correction), `timestamps`.
- **F1.2 ตารางฐานข้อมูล `qr_code_scans`:** จัดเก็บ `id`, `qr_code_id` (Foreign Key Cascade), `scanned_at` (timestamp), `ip_hash` (string 64), `user_agent` (text nullable), `device_type` (mobile, tablet, desktop, robot), `platform` (iOS, Android, Windows, macOS, etc.), `browser` (Safari, Chrome, LINE, FB, etc.), `referrer` (text nullable).
- **F1.3 ระบบสุ่มรหัสที่ไม่ซ้ำ (Slug/Code Generator):** สุ่มรหัสสั้น 8 ตัวอักษรที่ไม่กำกวม (Alphanumeric Case-Sensitive หรือ Case-Insensitive URL-friendly) พร้อมรองรับการกำหนด Custom Slug ได้เอง (ตรวจสอบ Uniqueness).
- **F1.4 Public Redirect Controller (`GET /qr/{code}`):** ค้นหาและตรวจสอบสิทธิ์ Redirect แบบ 302, ทำงานรวดเร็ว, ส่งต่อผู้ใช้ไปยัง `target_url`, ป้องกัน Open Redirect.
- **F1.5 QR Image Rendering Endpoint (`GET /qr/{code}/image`):** สร้างภาพ QR Code แบบเวกเตอร์ (SVG) หรือบิตแมป (PNG) สดจากระบบ หรือคืนค่า Data URL.
- **F1.6 CRUD API สำหรับผู้ใช้งาน:**
  - `GET /qr-codes`: แสดงรายการ QR Code ของผู้ใช้ (หรือทั้งหมดในระบบ) พร้อมระบบค้นหา แบ่งหน้า และตัวกรอง
  - `POST /qr-codes`: สร้าง QR Code ใหม่
  - `GET /qr-codes/{qrCode}`: ข้อมูลละเอียดและสถิติการสแกน
  - `PUT /qr-codes/{qrCode}`: แก้ไขชื่อ, URL ปลายทาง, สถานะ เปิด/ปิด
  - `DELETE /qr-codes/{qrCode}`: ลบ QR Code และสถิติที่เกี่ยวข้อง
  - `GET /qr-codes/{qrCode}/analytics`: ข้อมูลสถิติแบ่งตาม วัน/สัปดาห์, อุปกรณ์, และเบราว์เซอร์

### โมดูล 2: การผสานเข้ากับเครื่องมือแก้ไขรูป (Image Editor Modal)
- **F2.1 ปุ่ม "เพิ่ม QR Code" บนแถบเครื่องมือ:** แสดงไอคอน QR Code ชัดเจนในแถบเครื่องมือด้านซ้ายของ `ImageEditorModal.jsx`.
- **F2.2 โมดัลเลือก/สร้าง QR Code (QR Selector & Creator Popover):**
  - แสดงรายการ QR Code ที่มีอยู่ พร้อมพรีวิวภาพและจำนวนสแกน
  - ค้นหา QR Code ตามชื่อหรือรหัส
  - ฟอร์มสร้าง QR Code ด่วน (Quick Create) กรอกเพียงชื่อและ URL ปลายทาง กดบันทึกแล้วเพิ่มลงผืนผ้าใบ Canvas ทันที
- **F2.3 วัตถุ QR Code บน Konva Canvas (`EditorElement`):**
  - รองรับประเภท element `type: 'image'` หรือ `type: 'qr'` โดยมีข้อมูลอ้างอิง `qrCodeId`, `code`, `targetUrl`
  - ปรับขนาดโดยคงอัตราส่วน 1:1 เสมอ (Keep Aspect Ratio) เพื่อความถูกต้องของสัดส่วน QR Code
  - ปรับความโปร่งใส (Opacity), ตำแหน่ง (X, Y), การหมุน (Rotation), และการจัดลำดับชั้น (Bring to Front / Send to Back)
- **F2.4 การแสดงผลข้อมูลในแผงควบคุมเมื่อเลือกวัตถุ QR:**
  - แสดงชื่อ QR Code, รหัสสั้น, ลิงก์ปลายทางปัจจุบัน
  - มีปุ่มทางลัด "แก้ไขลิงก์ปลายทาง" เพื่อเปลี่ยน URL ได้ทันที
- **F2.5 การส่งออกภาพที่สมบูรณ์ (Export Integration):**
  - เมื่อกด "บันทึกดีไซน์", วัตถุ QR Code จะถูก Flatten ลงในภาพ PNG คุณภาพสูงอย่างคมชัด
  - โครงสร้าง JSON `image_editor_data` จะบันทึก `qrCodeId` ไว้ เพื่อให้สามารถเปิดมาแก้ไขหรือรับรู้ว่าโปสเตอร์นี้มี QR Code ใดอยู่

### โมดูล 3: ส่วนติดต่อผู้ใช้หลัก (QR Code Management Dashboard)
- **F3.1 หน้าสารบัญและจัดการ Dynamic QR Code (`/qr-codes`):**
  - ตารางสรุป: รหัสสั้น, ชื่อ, ลิงก์ปลายทาง, สถานะ (Active/Inactive), ยอดสแกน (Scans), วันที่สร้าง, ปุ่ม Action
  - ปุ่ม Action: คัดลอกลิงก์สแกน, ดาวน์โหลด PNG, ดาวน์โหลด SVG, แก้ไข, ดูสถิติ, ลบ
- **F3.2 โมดัลแสดงสถิติการสแกน (Analytics Modal):**
  - การ์ดสรุป: ยอดสแกนทั้งหมด, สแกนใน 7 วันล่าสุด, อุปกรณ์ยอดนิยม
  - แผนภูมิหรือตารางแสดงแนวโน้มการสแกนรายวัน
  - รายการประวัติการสแกนล่าสุด (เวลา, อุปกรณ์, เบราว์เซอร์, แหล่งที่มา)

---

## 6. Acceptance Criteria

- [ ] มีตาราง migrations `qr_codes` และ `qr_code_scans` ในฐานข้อมูล พร้อม Foreign Key, Indexes (`code`, `user_id`, `created_at`) ครบถ้วน
- [ ] เมื่อสร้าง Dynamic QR Code สำเร็จ สามารถเรียกดูภาพ QR ผ่าน `/qr/{code}/image` ได้ทั้งรูปแบบ SVG และ PNG
- [ ] เมื่อเปิด URL `GET /qr/{code}` ระบบจะ Redirect แบบ 302 ไปยัง `target_url` ถูกต้อง 100%
- [ ] การสแกนแต่ละครั้ง บันทึกข้อมูลลงใน `qr_code_scans` และเพิ่ม `scan_count` ของ QR Code นั้นอย่างถูกต้อง
- [ ] หาก QR Code มีสถานะ `is_active = false` หรือหมดอายุ ระบบแสดงหน้าแจ้งเตือนภาษาไทยที่สุภาพ ไม่ Error
- [ ] ป้องกันการระบุ `target_url` ที่เป็นอันตราย (Non-HTTP/HTTPS, Localhost, Private IPs)
- [ ] ใน `ImageEditorModal.jsx` มีปุ่ม "เพิ่ม QR Code" สามารถกดเปิดหน้าต่างเลือกจากคลัง หรือสร้างใหม่ได้ทันที
- [ ] เมื่อเลือก QR Code จะปรากฏบน Konva Canvas เป็นวัตถุจัตุรัส สามารถย้าย, ย่อ/ขยาย, หมุน, และปรับเลเยอร์ได้
- [ ] เมื่อกดบันทึกดีไซน์ ภาพโปสเตอร์ที่ได้มี QR Code ฝังอยู่อย่างคมชัด และสแกนได้จริง
- [ ] มีเมนูเข้าสู่หน้า `/qr-codes` ในแถบนำทางหลัก (Authenticated Layout)
- [ ] มีชุดทดสอบ Feature Tests และ Unit Tests สำหรับการสร้าง, สแกน, แก้ไข, สิทธิ์การใช้งาน และความปลอดภัย ครอบคลุม 100%
- [ ] ชุดทดสอบเดิมทั้งหมดของระบบยังคงทำงานผ่านครบถ้วน (Zero Regression)
