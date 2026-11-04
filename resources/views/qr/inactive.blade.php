<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code ไม่พร้อมใช้งาน - {{ config('app.name', 'Poster') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            max-width: 480px;
            width: 90%;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .icon-box {
            width: 64px;
            height: 64px;
            background: #fef3c7;
            color: #d97706;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem auto;
        }
        .icon-box svg {
            width: 32px;
            height: 32px;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.75rem 0;
        }
        p {
            font-size: 0.925rem;
            line-height: 1.6;
            color: #64748b;
            margin: 0 0 1.5rem 0;
        }
        .code-pill {
            display: inline-block;
            background: #f1f5f9;
            color: #475569;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-family: monospace;
            margin-bottom: 1.5rem;
        }
        .footer-note {
            font-size: 0.8rem;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
            padding-top: 1rem;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="card">
        @if ($isPending ?? false)
            <div class="icon-box" style="background: #eef2ff; color: #4338ca;">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h1>เอกสารกำลังอยู่ระหว่างการประมวลผล</h1>
            <p>
                ระบบกำลังเตรียมไฟล์และซิงก์ข้อมูลไปยัง Google Drive หรือเว็บไซต์ปลายทาง กรุณาลองสแกนใหม่อีกครั้งใน 1 - 2 นาที
            </p>
        @else
            <div class="icon-box">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h1>ลิงก์ QR Code นี้ไม่พร้อมใช้งาน</h1>
            <p>
                ขออภัย ลิงก์ปลายทางของ QR Code นี้ถูกระงับการใช้งานชั่วคราว หรือหมดอายุระยะเวลาการประชาสัมพันธ์แล้ว กรุณาติดต่อหน่วยงานต้นสังกัดเพื่อขอรับข้อมูลล่าสุด
            </p>
        @endif
        @if (!empty($code))
            <div class="code-pill">รหัส: {{ $code }}</div>
        @endif
        <div class="footer-note">
            ระบบประชาสัมพันธ์ {{ config('app.name', 'Poster') }}
        </div>
    </div>
</body>
</html>
