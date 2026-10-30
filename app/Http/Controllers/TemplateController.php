<?php

namespace App\Http\Controllers;

use App\Models\PostMedia;
use App\Models\Template;
use App\Services\GoogleDriveFilenameBuilder;
use App\Services\IntegrationSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TemplateController extends Controller
{
    public function index()
    {
        $templates = Template::query()
            ->select(['id', 'name', 'featured_image_id', 'pdf_media_id', 'action_buttons', 'fb_comment_2', 'created_at', 'updated_at'])
            ->with(['featuredImage:id,file_path', 'pdfMedia:id,original_name,file_path'])
            ->latest()
            ->get();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create()
    {
        return Inertia::render('Templates/Create', [
            'mediaList' => $this->imageMediaList(),
            'pdfList' => $this->pdfMediaList(),
            'googleDriveConfig' => $this->googleDriveConfig(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateTemplate($request);

        Template::query()->create($validated);

        return redirect()->route('templates.index')->with('success', 'สร้างเทมเพลตสำเร็จ');
    }

    public function show(string $id)
    {
        //
    }

    public function edit(Template $template)
    {
        $template->load(['featuredImage:id,file_path', 'pdfMedia:id,original_name,file_path,file_size']);

        return Inertia::render('Templates/Edit', [
            'template' => $template,
            'mediaList' => $this->imageMediaList($template->featured_image_id),
            'pdfList' => $this->pdfMediaList($template->pdf_media_id),
            'googleDriveConfig' => $this->googleDriveConfig(),
        ]);
    }

    public function update(Request $request, Template $template)
    {
        $validated = $this->validateTemplate($request);

        $template->update($validated);

        return redirect()->route('templates.index')->with('success', 'แก้ไขเทมเพลตสำเร็จ');
    }

    public function destroy(Template $template)
    {
        $template->delete();

        return redirect()->back()->with('success', 'ลบเทมเพลตสำเร็จ');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'wp_template' => 'nullable|string',
            'fb_template' => 'nullable|string',
            'fb_comment' => 'nullable|string',
            'fb_comment_2' => 'nullable|string',
            'action_buttons' => 'nullable|array',
            'action_buttons.*.label' => 'nullable|string|max:255',
            'action_buttons.*.url' => 'nullable|string|max:2048',
            'pdf_media_id' => 'nullable|integer|exists:post_media,id',
            'pdf_file' => 'nullable|file|mimes:pdf|max:20480',
            'drive_sync_enabled' => 'nullable|boolean',
            'drive_employee_category' => 'nullable|string|max:100',
            'drive_announcement_stage' => 'nullable|string|max:100',
            'featured_image_id' => 'nullable|integer|exists:post_media,id',
            'image_editor_data' => 'nullable|array',
            'image_editor_data.canvasWidth' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.canvasHeight' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.backgroundColor' => 'nullable|string|max:32',
            'image_editor_data.backgroundImage' => 'nullable|array',
            'image_editor_data.backgroundImage.src' => 'nullable|string|max:4096',
            'image_editor_data.backgroundImage.mediaId' => 'nullable|integer|exists:post_media,id',
            'image_editor_data.elements' => 'nullable|array|max:100',
        ]);

        $featuredImageId = $validated['featured_image_id'] ?? null;

        if ($featuredImageId) {
            $media = PostMedia::query()->find($featuredImageId);

            if (! $media || ! Str::startsWith((string) $media->mime_type, 'image/')) {
                throw ValidationException::withMessages([
                    'featured_image_id' => 'รูปประจำเทมเพลตต้องเป็นไฟล์รูปภาพเท่านั้น',
                ]);
            }
        }

        if ($request->hasFile('pdf_file')) {
            $pdfFile = $request->file('pdf_file');
            $pdfPath = $pdfFile->store('media', 'public');

            $pdfMedia = PostMedia::create([
                'post_id' => null,
                'file_path' => $pdfPath,
                'original_name' => $pdfFile->getClientOriginalName(),
                'mime_type' => $pdfFile->getMimeType(),
                'file_size' => $pdfFile->getSize(),
                'category' => 'เอกสารแนบเทมเพลต',
                'is_featured' => false,
            ]);

            $validated['pdf_media_id'] = $pdfMedia->id;
        }

        unset($validated['pdf_file']);

        if (array_key_exists('action_buttons', $validated)) {
            $validated['action_buttons'] = collect($validated['action_buttons'] ?? [])
                ->filter(fn ($btn) => is_array($btn) && (trim((string) ($btn['label'] ?? '')) !== '' || trim((string) ($btn['url'] ?? '')) !== ''))
                ->map(fn ($btn) => [
                    'label' => trim((string) ($btn['label'] ?? '')),
                    'url' => trim((string) ($btn['url'] ?? '')),
                ])
                ->values()
                ->all();

            if (empty($validated['action_buttons'])) {
                $validated['action_buttons'] = null;
            }
        }

        return $validated;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function imageMediaList(?int $ensureId = null): array
    {
        $media = PostMedia::query()
            ->select(['id', 'file_path', 'original_name', 'mime_type', 'file_size'])
            ->where('mime_type', 'like', 'image/%')
            ->latest()
            ->limit(100)
            ->get();

        if ($ensureId && ! $media->contains('id', $ensureId)) {
            $selected = PostMedia::query()
                ->select(['id', 'file_path', 'original_name', 'mime_type', 'file_size'])
                ->whereKey($ensureId)
                ->where('mime_type', 'like', 'image/%')
                ->first();

            if ($selected) {
                $media->prepend($selected);
            }
        }

        return $media
            ->map(fn (PostMedia $item): array => [
                'id' => $item->id,
                'file_path' => $item->file_path,
                'original_name' => $item->original_name ?: basename($item->file_path),
                'mime_type' => $item->mime_type,
                'file_size' => $item->file_size,
                'url' => Storage::disk('public')->url($item->file_path),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pdfMediaList(?int $ensureId = null): array
    {
        $media = PostMedia::query()
            ->select(['id', 'file_path', 'original_name', 'mime_type', 'file_size'])
            ->where('mime_type', 'application/pdf')
            ->latest()
            ->limit(100)
            ->get();

        if ($ensureId && ! $media->contains('id', $ensureId)) {
            $selected = PostMedia::query()
                ->select(['id', 'file_path', 'original_name', 'mime_type', 'file_size'])
                ->whereKey($ensureId)
                ->where('mime_type', 'application/pdf')
                ->first();

            if ($selected) {
                $media->prepend($selected);
            }
        }

        return $media
            ->map(fn (PostMedia $item): array => [
                'id' => $item->id,
                'file_path' => $item->file_path,
                'original_name' => $item->original_name ?: basename($item->file_path),
                'mime_type' => $item->mime_type,
                'file_size' => $item->file_size,
                'url' => Storage::disk('public')->url($item->file_path),
            ])
            ->values()
            ->all();
    }

    protected function googleDriveConfig(): array
    {
        $drive = app(IntegrationSettingsService::class)->googleDrive();

        return [
            'enabled' => (bool) ($drive['enabled'] ?? false),
            'is_connected' => ! empty($drive['refresh_token'] ?? null),
            'categories' => GoogleDriveFilenameBuilder::categories(),
            'stages' => GoogleDriveFilenameBuilder::stages(),
            'default_category' => $drive['default_category'] ?? GoogleDriveFilenameBuilder::CATEGORY_DAILY,
            'default_stage' => $drive['default_stage'] ?? GoogleDriveFilenameBuilder::STAGE_ANNOUNCEMENT,
            'default_comment_template' => $drive['default_comment_template'] ?? "📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}",
        ];
    }
}
