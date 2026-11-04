<?php

namespace App\Http\Controllers;

use App\Models\QrCode;
use App\Services\QrTelemetryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class QrRedirectController extends Controller
{
    public function __construct(
        protected QrTelemetryService $telemetryService,
    ) {}

    public function redirect(Request $request, string $code): RedirectResponse|Response
    {
        /** @var QrCode|null $qrCode */
        $qrCode = Cache::remember("qr_code:{$code}", 600, function () use ($code) {
            return QrCode::query()->where('code', $code)->first();
        });

        if (! $qrCode) {
            abort(404, 'ไม่พบรหัส QR Code นี้ในระบบ');
        }

        if (! $qrCode->isRedirectable()) {
            return response()
                ->view('qr.inactive', ['code' => $code], 410)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache');
        }

        // Record scan analytics without blocking user
        $this->telemetryService->recordScan($qrCode, $request);

        return redirect()->away($qrCode->target_url, 302, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
