# Poster — Production Deployment & Operations Runbook

คู่มือนี้สำหรับนำระบบ **Poster (Laravel 13 + React Inertia + Tailwind CSS)** ขึ้นสู่ Production Server บน **Linux VPS (Ubuntu / Debian / aaPanel)** โดยอ้างอิงสถาปัตยกรรมระบบปัจจุบัน

---

## 1. Runtime Requirements & Prerequisites

ก่อนเริ่มติดตั้ง ตรวจสอบว่า Server ติดตั้งแพ็กเกจเหล่านี้เรียบร้อยแล้ว:

- **OS:** Ubuntu 22.04 LTS หรือ Ubuntu 24.04 LTS (หรือ aaPanel บน Linux)
- **Web Server:** Nginx 1.18+
- **PHP:** PHP 8.3+ พร้อมส่วนขยาย (Extensions):
  `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `tokenizer`, `xml`, `zip`
- **Database:** MySQL 8.0+ หรือ MariaDB 10.11+ (ตั้งค่า Collation: `utf8mb4_unicode_ci`)
- **Package Managers:** Composer 2.7+ และ Node.js 20+ (LTS) พร้อม npm
- **Process Manager:** Supervisor (สำหรับดูแล Queue Worker)
- **Cron:** Linux Crontab (สำหรับ Laravel Scheduler)

### คำสั่งติดตั้ง Packages บน Ubuntu 22.04/24.04:
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx git curl unzip supervisor

# เพิ่ม PPA สำหรับ PHP 8.3 (ถ้ายังไม่มี)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-bcmath php8.3-zip php8.3-sodium php8.3-intl php8.3-tokenizer

# ติดตั้ง Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# ติดตั้ง Node.js 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

---

## 2. ขั้นตอนการติดตั้งครั้งแรก (Initial Deployment)

### 2.1 โคลนโค้ดและตั้งสิทธิ์โฟลเดอร์
```bash
# Clone เข้าสู่ไดเรกทอรี /var/www/poster (หรือไดเรกทอรีเว็บไซต์ใน aaPanel)
sudo git clone <YOUR_GIT_REPOSITORY_URL> /var/www/poster
cd /var/www/poster

# กำหนดสิทธิ์ไดเรกทอรี (www-data สำหรับ Ubuntu ทั่วไป หรือ www สำหรับ aaPanel)
sudo chown -R www-data:www-data /var/www/poster
sudo chmod -R 775 storage bootstrap/cache
```

### 2.2 ตั้งค่า Environment (.env)
คัดลอกไฟล์เทมเพลตจาก `.env.production.example`:
```bash
cp .env.production.example .env
nano .env
```

**สิ่งที่ต้องแก้ไขใน `.env`:**
1. `APP_URL=https://your-domain.com`
2. `DB_HOST=127.0.0.1`, `DB_DATABASE=poster`, `DB_USERNAME=...`, `DB_PASSWORD=...`
3. `QUEUE_CONNECTION=database`
4. `QUEUE_AUTO_TRIGGER=false` (เพื่อให้ Supervisor เป็นผู้ดูแล Worker เพียงผู้เดียว)
5. `SESSION_DRIVER=database`

### 2.3 สร้าง Application Encryption Key (เฉพาะครั้งแรก!)
```bash
php artisan key:generate
```
> [!CAUTION]
> **คำเตือนเรื่อง APP_KEY:**
> `APP_KEY` ใช้สำหรับเข้ารหัส Credentials ของ WordPress และ Facebook ในฐานข้อมูล
> **ห้ามรันคำสั่ง `key:generate` ซ้ำอีกหลังจากเริ่มใช้งานและมีการบันทึกการเชื่อมต่อแล้ว** เพราะจะทำให้ไม่สามารถถอดรหัสรหัสผ่านเดิมได้

### 2.4 ติดตั้ง Dependencies และ Build Assets
```bash
# ติดตั้ง PHP packages (เฉพาะ production)
composer install --no-dev --optimize-autoloader

# ติดตั้ง Frontend packages และ Build Vite Assets
npm ci
npm run build

# รัน Migration สร้างตาราง
php artisan migrate --force

# สร้าง Symlink สำหรับไฟล์สื่อและเอกสาร PDF
php artisan storage:link

# ทำการ Optimize Cache
php artisan optimize
```

---

## 3. การตั้งค่า Nginx & SSL

### 3.1 คัดลอกคอนฟิก Nginx
ใช้ไฟล์เทมเพลตจาก `scripts/nginx/poster.conf`:
```bash
sudo cp scripts/nginx/poster.conf /etc/nginx/sites-available/poster.conf
sudo nano /etc/nginx/sites-available/poster.conf
```
*แก้ไข `server_name` ให้เป็นชื่อโดเมนจริงของคุณ*

เปิดใช้งานไซต์และทดสอบคอนฟิก:
```bash
sudo ln -s /etc/nginx/sites-available/poster.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 3.2 ขอใบรับรอง SSL ฟรีด้วย Certbot
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

---

## 4. การตั้งค่า Queue Worker (Supervisor)

ระบบ Poster เผยแพร่บทความไปยัง WordPress และ Facebook ผ่านคิวแบบ Asynchronous จึงจำเป็นต้องมี Daemon คอยรัน Worker ตลอดเวลา

### 4.1 คัดลอกคอนฟิก Supervisor
ใช้ไฟล์เทมเพลตจาก `scripts/supervisor/poster-worker.conf`:
```bash
sudo cp scripts/supervisor/poster-worker.conf /etc/supervisor/conf.d/poster-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start poster-worker:*
```

### 4.2 ตรวจสอบสถานะ Worker
```bash
sudo supervisorctl status poster-worker:*
```
*ต้องแสดงสถานะ `RUNNING`*

---

## 5. การตั้งค่า Crontab (Laravel Scheduler)

ระบบมีคำสั่งกู้คืนงานค้าง (`poster:recover-stale-jobs`) ที่ตั้งเวลาไว้ทุก 5 นาที และการเคลียร์ Session ที่หมดอายุ

เปิด Crontab ของผู้ใช้ `www-data` (หรือ `root`):
```bash
sudo crontab -u www-data -e
```
เพิ่มบรรทัดนี้ลงไป:
```cron
* * * * * cd /var/www/poster && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

ตรวจสอบรายการ Schedule:
```bash
php artisan schedule:list
```

---

## 6. การ Deploy และอัปเดตระบบในอนาคต (Automated Script)

เมื่อมีการ Push โค้ดใหม่ขึ้น GitHub/GitLab ให้เชื่อมต่อไปยัง Server ผ่าน SSH แล้วรันคำสั่งเดียว:

```bash
cd /var/www/poster
chmod +x scripts/deploy.sh
./scripts/deploy.sh main
```

**สิ่งที่สคริปต์ `deploy.sh` ทำโดยอัตโนมัติ:**
1. เปิดโหมดซ่อมบำรุงชั่วคราว (`php artisan down`)
2. ดึงโค้ดล่าสุดจาก Git (`git pull`)
3. อัปเดต Composer packages และ Build React Assets ด้วย Vite (`npm run build`)
4. รัน Database Migration อัตโนมัติ (`php artisan migrate --force`)
5. ล้างแคชเก่าและสร้าง Config/Route cache ใหม่ (`php artisan optimize`)
6. รีสตาร์ต Queue Worker (`php artisan queue:restart` และ `supervisorctl restart`)
7. ปิดโหมดซ่อมบำรุงและกลับสู่สถานะพร้อมใช้งานทันที (`php artisan up`)

---

## 7. คำแนะนำสำหรับผู้ใช้งาน aaPanel

หาก Server ติดตั้งผ่าน **aaPanel**:
1. **Website:** สร้าง PHP Project โดยระบุ Root Path เป็น `/www/wwwroot/poster/public` และเลือก PHP 8.3
2. **PHP Settings:** ในเมนู App Store > PHP 8.3 ให้เปิดใช้งาน Extensions: `fileinfo`, `sodium`, `intl` และปรับ `upload_max_size` เป็น `64M`
3. **Supervisor:** ติดตั้ง **Supervisor Manager** จาก App Store > เพิ่ม Daemon:
   - Name: `poster-worker`
   - Run User: `www`
   - Run Dir: `/www/wwwroot/poster`
   - Start Command: `/usr/bin/php /www/wwwroot/poster/artisan queue:work database --sleep=2 --timeout=90 --tries=1 --max-time=3600`
   - Processes: `2`
4. **Cron:** ในเมนู Cron ของ aaPanel ให้เพิ่มประเภท `Shell Script` รันทุก 1 นาที:
   ```bash
   cd /www/wwwroot/poster && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

---

## 8. Health Checks & Verification

หลังจากการ Deploy ให้ตรวจสอบดังนี้:
1. **Health Check Endpoint:** เปิด `https://your-domain.com/up` ต้องตอบกลับ Status 200 OK
2. **Web Interface:** เข้าหน้า `https://your-domain.com/login` ตรวจสอบ CSS/JS โหลดปกติ
3. **Queue Status:** ตรวจสอบว่าไม่มี Failed Jobs: `php artisan queue:failed`
4. **Integration Health Check:** เข้าเมนู **การตั้งค่า (Settings) > การเชื่อมต่อ (Integrations)** และกดปุ่ม **"ตรวจสอบการเชื่อมต่อ"** เพื่อทดสอบ Health Check Probe กับ WordPress และ Facebook Page API
