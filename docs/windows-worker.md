# Poster Queue Worker on Windows

โปรเจกต์มีสคริปต์สำหรับรัน Laravel database queue worker แบบ local บน Windows/Laragon

## รัน worker ด้วยตนเอง

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-worker.ps1
```

สคริปต์จะ:
- หา `php.exe` จาก Laragon ก่อน แล้ว fallback ไป PHP ใน PATH
- ป้องกันการเปิด worker ของโปรเจกต์ Poster ซ้ำ
- รัน `artisan queue:work database --sleep=1 --timeout=90 --tries=1`

## ติดตั้ง Auto-start

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install-worker-task.ps1
```

ระบบจะสร้าง Windows Scheduled Task ชื่อ **Poster Queue Worker** และเริ่ม worker อัตโนมัติเมื่อผู้ใช้ Windows ลงชื่อเข้าใช้

## ถอน Auto-start

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\remove-worker-task.ps1
```

> deployment ควรเลือกว่าจะติดตั้ง task บนเครื่องใดอย่างชัดเจน ไม่ควรสร้าง Scheduled Task บนทุกเครื่องโดยอัตโนมัติ
