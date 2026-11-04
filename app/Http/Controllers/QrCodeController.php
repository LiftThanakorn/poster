<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQrCodeRequest;
use App\Http\Requests\UpdateQrCodeRequest;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\QrCode;
use App\Models\Template;
use App\Services\QrCodeGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class QrCodeController extends Controller
{
    public function __construct(
        protected QrCodeGeneratorService $qrGenerator,
    ) {}

    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status', 'all'); // all, active, inactive
        $sort = $request->input('sort', 'latest'); // latest, scans

        $query = QrCode::query()
            ->with('user:id,name,email')
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('target_url', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false)->orWhere('expires_at', '<=', now()))
            ->when($sort === 'scans', fn ($q) => $q->orderByDesc('scan_count'), fn ($q) => $q->latest());

        if ($request->wantsJson()) {
            return response()->json([
                'qr_codes' => $query->limit(100)->get()->map(fn (QrCode $qr) => $this->transformQr($qr)),
            ]);
        }

        $qrCodes = $query->paginate(20)->withQueryString()->through(fn (QrCode $qr) => $this->transformQr($qr));

        $totalQrs = QrCode::query()->count();
        $totalScans = (int) QrCode::query()->sum('scan_count');
        $activeQrs = QrCode::query()->active()->count();

        // 7-day scans
        $recentScansCount = DB::table('qr_code_scans')
            ->where('scanned_at', '>=', now()->subDays(7))
            ->count();

        $mediaList = PostMedia::query()
            ->select(['id', 'file_path', 'original_name', 'mime_type', 'file_size'])
            ->where('mime_type', 'like', 'image/%')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'original_name' => $m->original_name,
                'file_path' => $m->file_path,
                'url' => Storage::disk('public')->url($m->file_path),
            ]);

        return Inertia::render('QrCodes/Index', [
            'qrCodes' => $qrCodes,
            'mediaList' => $mediaList,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'sort' => $sort,
            ],
            'metrics' => [
                'total_qrs' => $totalQrs,
                'active_qrs' => $activeQrs,
                'total_scans' => $totalScans,
                'recent_scans' => $recentScansCount,
            ],
        ]);
    }

    public function store(StoreQrCodeRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        $code = $validated['code'] ?? null;
        if (! $code) {
            do {
                $code = Str::lower(Str::random(8));
            } while (QrCode::query()->where('code', $code)->exists());
        }

        $qrCode = QrCode::query()->create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'code' => $code,
            'target_url' => $validated['target_url'],
            'is_active' => $request->boolean('is_active', true),
            'expires_at' => $validated['expires_at'] ?? null,
            'scan_count' => 0,
            'styling' => $validated['styling'] ?? [
                'darkColor' => '#000000',
                'lightColor' => '#ffffff',
                'transparentBg' => false,
                'margin' => 2,
                'errorCorrection' => 'H',
            ],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'สร้าง Dynamic QR Code สำเร็จ',
                'qr_code' => $this->transformQr($qrCode),
            ], 201);
        }

        return redirect()->route('qr-codes.index')->with('success', 'สร้าง Dynamic QR Code สำเร็จ');
    }

    public function show(QrCode $qrCode): JsonResponse
    {
        return response()->json([
            'qr_code' => $this->transformQr($qrCode),
        ]);
    }

    public function update(UpdateQrCodeRequest $request, QrCode $qrCode): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        $qrCode->update([
            'title' => $validated['title'],
            'code' => $validated['code'],
            'target_url' => $validated['target_url'],
            'is_active' => $request->boolean('is_active'),
            'expires_at' => $validated['expires_at'] ?? null,
            'styling' => $validated['styling'] ?? $qrCode->styling,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'แก้ไข Dynamic QR Code สำเร็จ',
                'qr_code' => $this->transformQr($qrCode->fresh()),
            ]);
        }

        return redirect()->route('qr-codes.index')->with('success', 'แก้ไข Dynamic QR Code สำเร็จ');
    }

    public function destroy(Request $request, QrCode $qrCode): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->can('delete', $qrCode), 403);

        $usedInPosts = $this->isUsedInPosts($qrCode);
        $usedInTemplates = $this->isUsedInTemplates($qrCode);

        if ($usedInPosts || $usedInTemplates) {
            $message = 'ไม่สามารถลบ QR Code นี้ได้เนื่องจากถูกแทรกอยู่ในรูปภาพของโพสต์หรือเทมเพลต กรุณาสลับสถานะเป็น "ปิดใช้งาน" แทน';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        $qrCode->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'ลบ QR Code สำเร็จ']);
        }

        return redirect()->route('qr-codes.index')->with('success', 'ลบ QR Code สำเร็จ');
    }

    public function analytics(Request $request, QrCode $qrCode): JsonResponse
    {
        $days = max(1, min(90, (int) $request->input('days', 30)));
        $startDate = now()->subDays($days)->startOfDay();

        $scans = $qrCode->scans()
            ->where('scanned_at', '>=', $startDate)
            ->get();

        // Cross-DB daily aggregation
        $timelineMap = [];
        for ($i = $days; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $timelineMap[$d] = 0;
        }

        foreach ($scans as $scan) {
            $day = $scan->scanned_at->format('Y-m-d');
            if (isset($timelineMap[$day])) {
                $timelineMap[$day]++;
            }
        }

        $timeline = [];
        foreach ($timelineMap as $date => $count) {
            $timeline[] = ['date' => $date, 'scans' => $count];
        }

        $deviceCounts = $scans->groupBy('device_type')->map->count();
        $platformCounts = $scans->groupBy('platform')->map->count();
        $browserCounts = $scans->groupBy('browser')->map->count();

        $recentScans = $scans->sortByDesc('id')->take(20)->values()->map(fn ($s) => [
            'id' => $s->id,
            'scanned_at' => $s->scanned_at->toIso8601String(),
            'device_type' => $s->device_type,
            'platform' => $s->platform,
            'browser' => $s->browser,
            'referrer' => $s->referrer,
        ]);

        return response()->json([
            'qr_code' => [
                'id' => $qrCode->id,
                'title' => $qrCode->title,
                'code' => $qrCode->code,
                'target_url' => $qrCode->target_url,
                'scan_url' => $qrCode->scan_url,
                'total_scans' => $qrCode->scan_count,
            ],
            'period_days' => $days,
            'timeline' => $timeline,
            'devices' => $deviceCounts,
            'platforms' => $platformCounts,
            'browsers' => $browserCounts,
            'recent_scans' => $recentScans,
        ]);
    }

    public function image(Request $request, string $code): Response
    {
        $qrCode = QrCode::query()->where('code', $code)->firstOrFail();
        $styling = (array) ($qrCode->styling ?? []);

        $format = strtolower((string) $request->input('format', 'svg'));
        $size = max(100, min(2000, (int) $request->input('size', 400)));
        $margin = max(0, min(50, (int) ($styling['margin'] ?? 2) * 8));
        $ecc = (string) ($styling['errorCorrection'] ?? 'H');
        $dark = (string) ($styling['darkColor'] ?? '#000000');
        $light = (string) ($styling['lightColor'] ?? '#ffffff');
        $transparent = (bool) ($styling['transparentBg'] ?? false);

        $download = $request->boolean('download');
        $filename = "qrcode-{$qrCode->code}.{$format}";

        // Resolve logo path from media library if provided
        $logoPath = null;
        $logoMediaId = $styling['logoMediaId'] ?? null;
        if ($logoMediaId) {
            $media = PostMedia::query()->find($logoMediaId);
            if ($media && Storage::disk('public')->exists($media->file_path)) {
                $logoPath = Storage::disk('public')->path($media->file_path);
            }
        }

        if ($format === 'png') {
            $content = $this->qrGenerator->generatePng(
                $qrCode->scan_url,
                $size,
                $margin,
                $ecc,
                $dark,
                $light,
                $transparent,
                $logoPath,
            );
            $mime = 'image/png';
        } else {
            $content = $this->qrGenerator->generateSvg(
                $qrCode->scan_url,
                $size,
                $margin,
                $ecc,
                $dark,
                $light,
                $transparent,
                $logoPath,
            );
            $mime = 'image/svg+xml';
            $format = 'svg';
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=3600',
        ];

        if ($download) {
            $headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        }

        return response($content, 200, $headers);
    }

    private function transformQr(QrCode $qr): array
    {
        return [
            'id' => $qr->id,
            'title' => $qr->title,
            'code' => $qr->code,
            'target_url' => $qr->target_url,
            'is_active' => $qr->is_active,
            'is_expired' => $qr->isExpired(),
            'is_redirectable' => $qr->isRedirectable(),
            'expires_at' => $qr->expires_at?->toIso8601String(),
            'scan_count' => (int) $qr->scan_count,
            'styling' => $qr->styling,
            'scan_url' => $qr->scan_url,
            'image_url' => $qr->image_url,
            'creator' => $qr->user ? ['id' => $qr->user->id, 'name' => $qr->user->name] : null,
            'created_at' => $qr->created_at?->toIso8601String(),
            'used_in_count' => $this->countUsage($qr->id),
        ];
    }

    private function isUsedInPosts(QrCode $qrCode): bool
    {
        return Post::query()
            ->whereNotNull('image_editor_data')
            ->get(['image_editor_data'])
            ->contains(function (Post $post) use ($qrCode): bool {
                $elements = data_get($post->image_editor_data, 'elements', []);

                return collect($elements)->contains(
                    fn (array $el): bool => (int) ($el['qrCodeId'] ?? 0) === $qrCode->id
                );
            });
    }

    private function isUsedInTemplates(QrCode $qrCode): bool
    {
        return Template::query()
            ->whereNotNull('image_editor_data')
            ->get(['image_editor_data'])
            ->contains(function (Template $template) use ($qrCode): bool {
                $elements = data_get($template->image_editor_data, 'elements', []);

                return collect($elements)->contains(
                    fn (array $el): bool => (int) ($el['qrCodeId'] ?? 0) === $qrCode->id
                );
            });
    }

    private function countUsage(int $qrCodeId): int
    {
        $postCount = Post::query()
            ->whereNotNull('image_editor_data')
            ->get(['image_editor_data'])
            ->filter(fn (Post $p) => collect(data_get($p->image_editor_data, 'elements', []))->contains(
                fn ($el) => (int) ($el['qrCodeId'] ?? 0) === $qrCodeId
            ))
            ->count();

        $templateCount = Template::query()
            ->whereNotNull('image_editor_data')
            ->get(['image_editor_data'])
            ->filter(fn (Template $t) => collect(data_get($t->image_editor_data, 'elements', []))->contains(
                fn ($el) => (int) ($el['qrCodeId'] ?? 0) === $qrCodeId
            ))
            ->count();

        return $postCount + $templateCount;
    }
}
