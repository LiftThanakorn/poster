<?php

namespace App\Services;

use App\Models\QrCode;
use App\Models\QrCodeScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

class QrTelemetryService
{
    /**
     * Record a scan asynchronously or after response has been sent to client.
     */
    public function recordScan(QrCode $qrCode, Request $request): void
    {
        $payload = $this->extractPayload($qrCode->id, $request);

        $recordCallback = function () use ($qrCode, $payload): void {
            try {
                QrCodeScan::query()->create($payload);
                $qrCode->increment('scan_count');
            } catch (\Throwable) {
                // Silently ignore telemetry write failure to protect user redirect
            }
        };

        if (App::runningUnitTests()) {
            $recordCallback();
        } else {
            app()->terminating($recordCallback);
        }
    }

    /**
     * Extract analytics payload from request.
     *
     * @return array<string, mixed>
     */
    public function extractPayload(int $qrCodeId, Request $request): array
    {
        $rawIp = (string) ($request->ip() ?: '127.0.0.1');
        $appKey = (string) config('app.key', 'fallback-key');
        $ipHash = hash_hmac('sha256', $rawIp, $appKey);

        $ua = (string) $request->header('User-Agent', '');
        $classification = $this->classifyUserAgent($ua);

        return [
            'qr_code_id' => $qrCodeId,
            'scanned_at' => now(),
            'ip_hash' => $ipHash,
            'user_agent' => Str::limit($ua, 1000),
            'device_type' => $classification['device_type'],
            'platform' => $classification['platform'],
            'browser' => $classification['browser'],
            'referrer' => Str::limit((string) $request->header('referer'), 1000) ?: null,
        ];
    }

    /**
     * Classify user agent into device, platform, and browser.
     *
     * @return array{device_type: string, platform: string, browser: string}
     */
    public function classifyUserAgent(string $ua): array
    {
        $device = 'desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|NetFront|Silk-Accelerated|(hpw|web)OS|Fennec|Minimo|Opera M(obi|ini)|Blazer/i', $ua)) {
            $device = 'mobile';
        }

        $platform = 'Other';
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $platform = 'iOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $platform = 'Android';
        } elseif (preg_match('/Windows NT/i', $ua)) {
            $platform = 'Windows';
        } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            $platform = 'macOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $platform = 'Linux';
        }

        $browser = 'Other';
        if (preg_match('/Line\//i', $ua)) {
            $browser = 'LINE In-App';
        } elseif (preg_match('/FBAN|FBAV/i', $ua)) {
            $browser = 'Facebook In-App';
        } elseif (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\//i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/Firefox\//i', $ua)) {
            $browser = 'Firefox';
        }

        return [
            'device_type' => $device,
            'platform' => $platform,
            'browser' => $browser,
        ];
    }
}
