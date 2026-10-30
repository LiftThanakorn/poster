# Poster Queue Worker — Supervisor

เอกสารนี้เป็น template สำหรับ production Linux/Ubuntu ที่ใช้ Supervisor เพื่อให้ queue worker ของ Poster เริ่มอัตโนมัติและ restart เมื่อ process ล้มเหลว

## 1. Supervisor program

ปรับ path ให้ตรงกับ production จริงก่อนใช้งาน:

```ini
[program:poster-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /www/wwwroot/poster/artisan queue:work database --sleep=2 --timeout=90 --tries=1 --max-time=3600
directory=/www/wwwroot/poster
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www
numprocs=1
redirect_stderr=true
stdout_logfile=/www/wwwroot/poster/storage/logs/worker.log
stopwaitsecs=120
```

> เปลี่ยน `/www/wwwroot/poster`, PHP path และ `user` ให้ตรงกับ aaPanel/Server จริง

## 2. Reload configuration

```bash
supervisorctl reread
supervisorctl update
supervisorctl start poster-worker:*
supervisorctl status
```

## 3. Deploy code ใหม่

หลัง deploy ให้ restart worker เพื่อโหลดโค้ดเวอร์ชันใหม่:

```bash
php artisan queue:restart
supervisorctl restart poster-worker:*
```

## 4. Laravel Scheduler

ตั้ง cron ให้เรียก scheduler ทุกนาที:

```cron
* * * * * cd /www/wwwroot/poster && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Scheduler ใช้สำหรับ recovery งาน publish ที่ค้าง `processing` เกินเวลาที่กำหนด
