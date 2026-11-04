<?php

namespace App\Http\Controllers;

use App\Models\Post;
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
        $qrCode = Cache::remember("qr_code:{$code}", 60, function () use ($code) {
            return QrCode::query()->where('code', $code)->first();
        });

        if (! $qrCode) {
            abort(404, 'ไม่พบรหัส QR Code นี้ในระบบ');
        }

        // If target_url is a placeholder (e.g. {drive_url}, {wordpress_url}), attempt dynamic on-the-fly resolution
        if (str_starts_with((string) $qrCode->target_url, '{')) {
            $resolvedUrl = $this->attemptOnTheFlyResolution($qrCode);
            if ($resolvedUrl) {
                $qrCode->update(['target_url' => $resolvedUrl]);
            }
        }

        if (! $qrCode->isRedirectable()) {
            $isPending = str_starts_with((string) $qrCode->target_url, '{');

            return response()
                ->view('qr.inactive', [
                    'code' => $code,
                    'isPending' => $isPending,
                ], 410)
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

    private function attemptOnTheFlyResolution(QrCode $qrCode): ?string
    {
        $target = trim((string) $qrCode->target_url);

        // Find post that matches title or has this qr in elements
        $post = null;
        if (! empty($qrCode->title)) {
            $post = Post::query()
                ->where('title', 'like', "%{$qrCode->title}%")
                ->orWhere('title', $qrCode->title)
                ->latest()
                ->first();
        }

        if (! $post) {
            $post = Post::query()->latest()->first();
        }

        if (! $post) {
            return null;
        }

        if (in_array($target, ['{drive_url}', '{{drive_url}}'], true)) {
            $driveLink = $post->pdfMedia?->drive_web_view_link;
            if ($driveLink) {
                return $driveLink;
            }
        }

        if (in_array($target, ['{wordpress_url}', '{{wordpress_url}}'], true)) {
            $wpTarget = $post->targets()->where('channel', 'wordpress')->first();
            $wpUrl = $wpTarget?->published_url ?? $post->wordpress_post_url;
            if ($wpUrl) {
                return $wpUrl;
            }
        }

        return null;
    }
}
