# Poster — Local Development Guide

เอกสารนี้ใช้สำหรับพัฒนาโปรเจกต์ Poster บน Windows + Laragon

## Paths

Project:
```text
C:\laragon\www\Poster
```

PHP:
```text
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe
```

MySQL:
```text
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe
```

PHP และ MySQL อาจไม่อยู่ใน Windows PATH จึงควรใช้ full path สำหรับ Artisan, PHPUnit และ Pint
## Initial setup

```powershell
cd C:\laragon\www\Poster
composer install
npm install --legacy-peer-deps
copy .env.example .env
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan key:generate
```

ตั้งค่า MySQL:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=poster
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
```

ห้ามเปลี่ยนกลับไปใช้ SQLite
จากนั้นรัน:
```powershell
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan migrate
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan storage:link
npm run build
```

## Development server

```powershell
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan serve
npm run dev
```

ถ้าใช้ Laragon Virtual Host อยู่แล้ว อาจไม่จำเป็นต้องใช้ `artisan serve`
## Queue worker

รันด้วย:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-worker.ps1
```

Auto-start มีสคริปต์เตรียมไว้:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install-worker-task.ps1
```

อย่ารันคำสั่งติดตั้ง Scheduled Task โดยไม่ตั้งใจ เพราะเป็น action ระดับระบบปฏิบัติการ

ถอนออก:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\remove-worker-task.ps1
```
## Integrations

ระบบอ่าน Integration Settings จากฐานข้อมูลก่อน และ fallback ไป environment

WordPress:
```env
WORDPRESS_URL=https://example.com
WORDPRESS_USERNAME=
WORDPRESS_APP_PASSWORD=
```

Facebook:
```env
FACEBOOK_PAGE_ID=
FACEBOOK_ACCESS_TOKEN=
FACEBOOK_GRAPH_VERSION=v26.0
```

ทดสอบ health check ผ่านหน้าเชื่อมต่อโดยไม่สร้างโพสต์จริง
## Testing and formatting

Full PHPUnit:
```powershell
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe vendor\bin\phpunit
```

เฉพาะไฟล์:
```powershell
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe vendor\bin\phpunit tests\Feature\E2E\FrontendApiAndWorkflowE2ETest.php
```

Formatting:
```powershell
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe vendor\bin\pint --format agent
```

Frontend build:
```powershell
npm run build
```
## Troubleshooting

### MySQL
- ตรวจว่า MySQL ใน Laragon ทำงาน
- ตรวจ DB_DATABASE / DB_USERNAME / DB_PASSWORD
- ใช้ MySQL จริง ห้าม fallback ไป SQLite

### Queue
- เปิด `scripts/start-worker.ps1`
- ตรวจตาราง `jobs` / `failed_jobs`
- ตรวจ `storage/logs/laravel.log`
- ตรวจ `QUEUE_CONNECTION=database`

### Vite
- ถ้า manifest หา asset ไม่เจอ ให้รัน `npm run build`
- ถ้าพัฒนา UI ให้รัน `npm run dev`

### Storage link
ถ้ารูป `/storage/...` ไม่แสดง:
```powershell
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan storage:link
```
### SSL CA / Laragon
ถ้า WordPress/Facebook HTTPS ล้มเหลวเฉพาะ local ให้ตรวจ CA bundle ของ PHP/cURL ก่อน ห้ามปิด SSL verification เป็นทางแก้ถาวร

### Facebook API
- Code 190 = token หมดอายุ/ไม่ถูกต้อง
- ตรวจ Page ID และ permissions
- Health check ต้องไม่แสดง token ออก frontend

### WordPress
- ตรวจ `/wp-json/wp/v2/users/me`
- ใช้ Application Password
- ตรวจสิทธิ์ `publish_posts`
- ตรวจ URL และ REST endpoint

## Development safety
- ห้ามสร้าง Facebook / WordPress post จริงเพื่อ automated test
- ใช้ `Http::fake()` ใน tests
- ห้าม commit หรือ log secrets
- อย่าลบ PublishTarget เพื่อ retry
- ถ้ามี external_id แล้วต้อง update ไม่ create ซ้ำ
- อย่าติดตั้ง Scheduled Task จนกว่าจะตั้งใจเปิดใช้จริง
