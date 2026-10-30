<?php

namespace App\Http\Controllers;

use App\Models\PostMedia;
use App\Services\GoogleDriveFilenameBuilder;
use App\Services\GoogleDriveService;
use App\Services\IntegrationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AnnouncementController extends Controller
{
    public function __construct(
        private readonly IntegrationSettingsService $settingsService,
        private readonly GoogleDriveService $driveService,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', 'all'));
        $stage = trim((string) $request->query('stage', 'all'));
        $syncStatus = trim((string) $request->query('status', 'all'));
        $yearMonth = trim((string) $request->query('month', ''));

        $driveSettings = $this->settingsService->getSettings('google_drive');
        $googleDriveConfig = $this->settingsService->googleDrive();

        $query = PostMedia::query()
            ->where(function ($q) {
                $q->where('mime_type', 'application/pdf')
                    ->orWhereNotNull('drive_file_id')
                    ->orWhereNotNull('drive_employee_category');
            })
            ->with(['post:id,title,status,created_at'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('original_name', 'like', "%{$search}%")
                        ->orWhere('drive_file_name', 'like', "%{$search}%")
                        ->orWhere('drive_position_title', 'like', "%{$search}%")
                        ->orWhereHas('post', function ($postQuery) use ($search) {
                            $postQuery->where('title', 'like', "%{$search}%");
                        });
                });
            })
            ->when($category !== '' && $category !== 'all', fn ($q) => $q->where('drive_employee_category', $category))
            ->when($stage !== '' && $stage !== 'all', fn ($q) => $q->where('drive_announcement_stage', $stage))
            ->when($syncStatus !== '' && $syncStatus !== 'all', fn ($q) => $q->where('drive_sync_status', $syncStatus))
            ->when($yearMonth !== '', function ($q) use ($yearMonth) {
                $q->where(function ($sub) use ($yearMonth) {
                    $sub->where('drive_document_date', 'like', "{$yearMonth}%")
                        ->orWhere('created_at', 'like', "{$yearMonth}%");
                });
            });

        $announcements = $query->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (PostMedia $media): array => [
                'id' => $media->id,
                'original_name' => $media->original_name ?: basename((string) $media->file_path),
                'drive_file_id' => $media->drive_file_id,
                'drive_file_name' => $media->drive_file_name,
                'drive_web_view_link' => $media->drive_web_view_link,
                'drive_folder_id' => $media->drive_folder_id,
                'drive_folder_path' => $media->drive_folder_path,
                'drive_employee_category' => $media->drive_employee_category,
                'drive_announcement_stage' => $media->drive_announcement_stage,
                'drive_document_date' => $media->drive_document_date?->format('Y-m-d'),
                'drive_position_title' => $media->drive_position_title,
                'drive_sync_status' => $media->drive_sync_status ?? 'none',
                'drive_permission_status' => $media->drive_permission_status,
                'drive_synced_at' => $media->drive_synced_at?->toIso8601String(),
                'drive_error_message' => $media->drive_error_message,
                'file_size' => $media->file_size,
                'local_url' => Storage::disk('public')->url((string) $media->file_path),
                'created_at' => $media->created_at?->toIso8601String(),
                'post' => $media->post ? [
                    'id' => $media->post->id,
                    'title' => $media->post->title,
                    'status' => $media->post->status,
                ] : null,
            ]);

        $stats = [
            'total' => PostMedia::query()->where('mime_type', 'application/pdf')->count(),
            'synced' => PostMedia::query()->where('mime_type', 'application/pdf')->where('drive_sync_status', 'synced')->count(),
            'pending' => PostMedia::query()->where('mime_type', 'application/pdf')->whereIn('drive_sync_status', ['pending', 'uploading', 'none'])->count(),
            'failed' => PostMedia::query()->where('mime_type', 'application/pdf')->where('drive_sync_status', 'failed')->count(),
        ];

        return Inertia::render('Announcements/Index', [
            'announcements' => $announcements,
            'categories' => (array) ($driveSettings['employee_categories'] ?? []),
            'stages' => (array) ($driveSettings['announcement_stages'] ?? []),
            'googleDrive' => [
                'configured' => $googleDriveConfig['configured'],
                'status' => $googleDriveConfig['status'],
                'root_folder_name' => $googleDriveConfig['root_folder_name'],
                'connected_email' => $googleDriveConfig['connected_email'],
            ],
            'filters' => [
                'q' => $search,
                'category' => $category,
                'stage' => $stage,
                'status' => $syncStatus,
                'month' => $yearMonth,
            ],
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pdf_file' => 'required|file|mimes:pdf|max:20480',
            'employee_category' => 'required|string|max:120',
            'announcement_stage' => 'required|string|max:120',
            'document_date' => 'required|date',
            'position_title' => 'nullable|string|max:255',
            'custom_filename' => 'nullable|string|max:255',
            'auto_sync' => 'nullable|boolean',
        ]);

        $file = $request->file('pdf_file');
        $path = $file->store('media', 'public');

        $category = trim($validated['employee_category']);
        $stage = trim($validated['announcement_stage']);
        $date = trim($validated['document_date']);
        $position = trim((string) ($validated['position_title'] ?? ''));
        $customFilename = trim((string) ($validated['custom_filename'] ?? ''));

        $targetFilename = GoogleDriveFilenameBuilder::build($date, $stage, $category, $position, $customFilename);

        $media = PostMedia::create([
            'post_id' => null,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => 'application/pdf',
            'file_size' => $file->getSize(),
            'category' => 'ประกาศ/รับสมัคร',
            'is_featured' => false,
            'drive_file_name' => $targetFilename,
            'drive_employee_category' => $category,
            'drive_announcement_stage' => $stage,
            'drive_document_date' => $date,
            'drive_position_title' => $position !== '' ? $position : null,
            'drive_sync_status' => 'pending',
        ]);

        $autoSync = $request->boolean('auto_sync', true);
        $googleConfigured = $this->settingsService->googleDrive()['configured'];

        if ($autoSync && $googleConfigured) {
            try {
                $this->driveService->syncPdfMedia($media, [
                    'employee_category' => $category,
                    'announcement_stage' => $stage,
                    'document_date' => $date,
                    'position_title' => $position,
                    'custom_filename' => $customFilename,
                    'force' => true,
                ]);

                return to_route('announcements.index')
                    ->with('success', "อัปโหลดและจัดเก็บเอกสารเข้า Google Drive เรียบร้อยแล้ว ({$targetFilename})");
            } catch (Throwable $e) {
                return to_route('announcements.index')
                    ->with('error', 'บันทึกไฟล์ในเครื่องสำเร็จ แต่การส่งขึ้น Google Drive เกิดข้อผิดพลาด: '.$e->getMessage());
            }
        }

        return to_route('announcements.index')
            ->with('success', "บันทึกไฟล์ประกาศเรียบร้อยแล้ว ({$targetFilename})");
    }

    public function sync(Request $request, PostMedia $media): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'employee_category' => 'nullable|string|max:120',
            'announcement_stage' => 'nullable|string|max:120',
            'document_date' => 'nullable|date',
            'position_title' => 'nullable|string|max:255',
            'custom_filename' => 'nullable|string|max:255',
        ]);

        $category = trim((string) ($validated['employee_category'] ?? $media->drive_employee_category ?? 'ทั่วไป'));
        $stage = trim((string) ($validated['announcement_stage'] ?? $media->drive_announcement_stage ?? 'รับสมัคร'));
        $date = trim((string) ($validated['document_date'] ?? $media->drive_document_date?->format('Y-m-d') ?? date('Y-m-d')));
        $position = trim((string) ($validated['position_title'] ?? $media->drive_position_title ?? ''));
        $customFilename = trim((string) ($validated['custom_filename'] ?? ''));

        try {
            $syncedMedia = $this->driveService->syncPdfMedia($media, [
                'employee_category' => $category,
                'announcement_stage' => $stage,
                'document_date' => $date,
                'position_title' => $position,
                'custom_filename' => $customFilename,
                'force' => true,
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'ซิงก์ไฟล์ขึ้น Google Drive สำเร็จ',
                    'drive_web_view_link' => $syncedMedia->drive_web_view_link,
                    'drive_file_name' => $syncedMedia->drive_file_name,
                    'drive_folder_path' => $syncedMedia->drive_folder_path,
                ]);
            }

            return back()->with('success', "ซิงก์ไฟล์ขึ้น Google Drive สำเร็จ ({$syncedMedia->drive_file_name})");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'ซิงก์ไฟล์ไม่สำเร็จ: '.$e->getMessage(),
                ], 422);
            }

            return back()->with('error', 'ซิงก์ไฟล์ไม่สำเร็จ: '.$e->getMessage());
        }
    }
}
