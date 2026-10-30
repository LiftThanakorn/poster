<?php

namespace App\Http\Controllers;

use App\Models\MediaFolder;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MediaController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', 'all');
        $folderId = $request->query('folder_id');
        $category = trim((string) $request->query('category', 'all'));

        if (! in_array($type, ['all', 'image', 'pdf'], true)) {
            $type = 'all';
        }

        // When search is performed and no folder_id is specified, search across all files
        if ($search !== '' && ($folderId === null || $folderId === '')) {
            $folderId = 'all';
        } elseif ($folderId === null || $folderId === '') {
            $folderId = 'root';
        }

        $currentFolder = null;
        if (is_numeric($folderId)) {
            $currentFolder = MediaFolder::query()->with('parent')->find($folderId);
        }

        // Build breadcrumbs path
        $breadcrumbs = [];
        if ($folderId === 'all') {
            $breadcrumbs[] = ['id' => 'all', 'name' => 'ไฟล์ทั้งหมด'];
        } else {
            $breadcrumbs[] = ['id' => 'root', 'name' => 'คลังหลัก'];
            if ($currentFolder) {
                foreach ($currentFolder->ancestors() as $ancestor) {
                    $breadcrumbs[] = [
                        'id' => $ancestor['id'],
                        'name' => $ancestor['name'],
                        'color' => $ancestor['color'],
                    ];
                }
                $breadcrumbs[] = [
                    'id' => $currentFolder->id,
                    'name' => $currentFolder->name,
                    'color' => $currentFolder->color,
                ];
            }
        }

        // Subfolders for current level
        if ($currentFolder) {
            $subfolders = MediaFolder::query()
                ->where('parent_id', $currentFolder->id)
                ->withCount('media')
                ->orderBy('name')
                ->get(['id', 'name', 'color', 'parent_id', 'created_at']);
        } elseif ($folderId === 'all') {
            $subfolders = collect();
        } else {
            // At Root: show top-level folders
            $subfolders = MediaFolder::query()
                ->whereNull('parent_id')
                ->withCount('media')
                ->orderBy('name')
                ->get(['id', 'name', 'color', 'parent_id', 'created_at']);
        }

        // Query files
        $mediaQuery = PostMedia::query()
            ->select([
                'id',
                'post_id',
                'folder_id',
                'category',
                'file_path',
                'original_name',
                'mime_type',
                'file_size',
                'is_featured',
                'created_at',
            ])
            ->with(['folder:id,name,color,parent_id'])
            ->withCount(['featuredInPosts', 'featuredInTemplates'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('original_name', 'like', "%{$search}%")
                        ->orWhere('file_path', 'like', "%{$search}%");
                });
            })
            ->when($type === 'image', fn ($query) => $query->where('mime_type', 'like', 'image/%'))
            ->when($type === 'pdf', fn ($query) => $query->where('mime_type', 'application/pdf'))
            ->when($category !== '' && $category !== 'all', fn ($query) => $query->where('category', $category))
            ->when($folderId === 'root', fn ($query) => $query->whereNull('folder_id'))
            ->when(is_numeric($folderId), fn ($query) => $query->where('folder_id', (int) $folderId));

        $media = $mediaQuery
            ->latest()
            ->paginate(24)
            ->withQueryString()
            ->through(fn (PostMedia $item): array => $this->presentMedia($item));

        // Available categories list
        $defaultCategories = [
            'ข่าวประชาสัมพันธ์',
            'ภาพกิจกรรม',
            'ประกาศ/รับสมัคร',
            'บุคลากร/ผู้บริหาร',
            'แบนเนอร์/อินโฟกราฟิก',
            'เอกสาร/คำสั่ง',
            'ทั่วไป',
        ];
        $existingCategories = PostMedia::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category')
            ->all();
        $categories = array_values(array_unique(array_merge($defaultCategories, $existingCategories)));

        // Flat list of all folders with full hierarchical paths for move pickers and tree navigation
        $allFoldersFlat = MediaFolder::query()
            ->with('parent')
            ->withCount('media')
            ->get()
            ->map(fn (MediaFolder $f): array => [
                'id' => $f->id,
                'name' => $f->name,
                'color' => $f->color,
                'parent_id' => $f->parent_id,
                'media_count' => (int) $f->media_count,
                'full_path' => $f->fullPath(),
            ])
            ->sortBy('full_path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $stats = [
            'total' => PostMedia::query()->count(),
            'uncategorized' => PostMedia::query()->whereNull('folder_id')->count(),
            'images' => PostMedia::query()->where('mime_type', 'like', 'image/%')->count(),
            'pdfs' => PostMedia::query()->where('mime_type', 'application/pdf')->count(),
            'bytes' => (int) PostMedia::query()->sum('file_size'),
            'folders' => MediaFolder::query()->count(),
        ];

        return Inertia::render('Media/Index', [
            'media' => $media,
            'subfolders' => $subfolders,
            'allFolders' => $allFoldersFlat,
            'categories' => $categories,
            'breadcrumbs' => $breadcrumbs,
            'currentFolder' => $currentFolder ? [
                'id' => $currentFolder->id,
                'name' => $currentFolder->name,
                'color' => $currentFolder->color,
                'parent_id' => $currentFolder->parent_id,
            ] : null,
            'filters' => [
                'q' => $search,
                'type' => $type,
                'folder_id' => $folderId,
                'category' => $category,
            ],
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $rawFolderId = $request->input('folder_id');
        $resolvedFolderId = null;
        if (is_numeric($rawFolderId) && (int) $rawFolderId > 0) {
            $resolvedFolderId = (int) $rawFolderId;
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'post_id' => 'nullable|integer|exists:posts,id',
            'folder_id' => 'nullable',
            'category' => 'nullable|string|max:100',
            'is_featured' => 'nullable|boolean',
        ]);

        $file = $request->file('file');

        if (! $file) {
            return back()->with('error', 'ไม่พบไฟล์ที่ต้องการอัปโหลด');
        }

        $path = $file->store('media', 'public');

        $media = PostMedia::query()->create([
            'post_id' => $validated['post_id'] ?? null,
            'folder_id' => $resolvedFolderId,
            'category' => trim((string) ($validated['category'] ?? '')) ?: null,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'media' => $this->presentMedia($media),
            ]);
        }

        return back()->with('success', 'อัปโหลดไฟล์เรียบร้อยแล้ว');
    }

    public function update(Request $request, PostMedia $media): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'original_name' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'folder_id' => 'nullable',
        ]);

        $rawFolderId = $request->input('folder_id');
        $resolvedFolderId = null;
        if (is_numeric($rawFolderId) && (int) $rawFolderId > 0) {
            $resolvedFolderId = (int) $rawFolderId;
        }

        $updateData = [];
        if ($request->has('original_name') && trim((string) $validated['original_name']) !== '') {
            $updateData['original_name'] = trim((string) $validated['original_name']);
        }
        if ($request->has('category')) {
            $updateData['category'] = trim((string) $validated['category']) ?: null;
        }
        if ($request->has('folder_id')) {
            $updateData['folder_id'] = $resolvedFolderId;
        }

        if (! empty($updateData)) {
            $media->update($updateData);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'บันทึกข้อมูลไฟล์เรียบร้อยแล้ว',
                'media' => $this->presentMedia($media->fresh()),
            ]);
        }

        return back()->with('success', 'บันทึกข้อมูลไฟล์เรียบร้อยแล้ว');
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $rawParentId = $request->input('parent_id');
        $resolvedParentId = null;
        if (is_numeric($rawParentId) && (int) $rawParentId > 0) {
            $resolvedParentId = (int) $rawParentId;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:media_folders,id',
        ]);

        MediaFolder::query()->create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?: '#4285F4',
            'parent_id' => $resolvedParentId,
        ]);

        return back()->with('success', 'สร้างโฟลเดอร์เรียบร้อยแล้ว');
    }

    public function updateFolder(Request $request, MediaFolder $folder): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:media_folders,id',
        ]);

        $rawParentId = $request->input('parent_id');
        $resolvedParentId = null;
        if (is_numeric($rawParentId) && (int) $rawParentId > 0 && (int) $rawParentId !== $folder->id) {
            $resolvedParentId = (int) $rawParentId;
        }

        $folder->update([
            'name' => $validated['name'],
            'color' => $validated['color'] ?: $folder->color,
            'parent_id' => $request->has('parent_id') ? $resolvedParentId : $folder->parent_id,
        ]);

        return back()->with('success', 'แก้ไขโฟลเดอร์เรียบร้อยแล้ว');
    }

    public function destroyFolder(MediaFolder $folder): RedirectResponse
    {
        // Safely move files in this folder to this folder's parent (or root if no parent)
        PostMedia::query()->where('folder_id', $folder->id)->update(['folder_id' => $folder->parent_id]);

        // Move subfolders to this folder's parent
        MediaFolder::query()->where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);

        $redirectFolderId = $folder->parent_id ? (string) $folder->parent_id : 'root';
        $folder->delete();

        return redirect()->route('media.index', ['folder_id' => $redirectFolderId])
            ->with('success', 'ลบโฟลเดอร์เรียบร้อยแล้ว (ไฟล์ถูกย้ายกลับมาที่โฟลเดอร์แม่)');
    }

    public function move(Request $request): RedirectResponse|JsonResponse
    {
        $rawFolderId = $request->input('folder_id');
        $resolvedFolderId = null;
        if (is_numeric($rawFolderId) && (int) $rawFolderId > 0) {
            $resolvedFolderId = (int) $rawFolderId;
        }

        $validated = $request->validate([
            'media_ids' => 'required|array|min:1',
            'media_ids.*' => 'integer|exists:post_media,id',
            'folder_id' => 'nullable',
            'category' => 'nullable|string|max:100',
        ]);

        $updatePayload = ['folder_id' => $resolvedFolderId];
        if ($request->has('category')) {
            $updatePayload['category'] = trim((string) $request->input('category')) ?: null;
        }

        PostMedia::query()
            ->whereIn('id', $validated['media_ids'])
            ->update($updatePayload);

        $folderName = $resolvedFolderId
            ? (MediaFolder::find($resolvedFolderId)?->name ?? 'โฟลเดอร์')
            : 'คลังหลัก';

        $message = "ย้ายไฟล์ไปยัง {$folderName} เรียบร้อยแล้ว";

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    public function destroy(string $id): RedirectResponse
    {
        $media = PostMedia::query()->findOrFail($id);

        if (
            $media->featuredInPosts()->exists()
            || $media->featuredInTemplates()->exists()
            || $this->isUsedInPostEditor($media)
            || $this->isUsedInTemplateEditor($media)
        ) {
            return back()->with(
                'error',
                'ไฟล์นี้กำลังถูกใช้เป็นรูปประกอบข่าวหรืออยู่ในงานออกแบบโพสต์/เทมเพลต กรุณาเปลี่ยนรูปก่อนลบไฟล์',
            );
        }

        if (Storage::disk('public')->exists($media->file_path)) {
            Storage::disk('public')->delete($media->file_path);
        }

        $media->delete();

        return back()->with('success', 'ลบไฟล์เรียบร้อยแล้ว');
    }

    private function isUsedInPostEditor(PostMedia $media): bool
    {
        return Post::query()
            ->whereNotNull('image_editor_data')
            ->get(['image_editor_data'])
            ->contains(function (Post $post) use ($media): bool {
                $editorData = $post->image_editor_data ?? [];
                $backgroundMediaId = (int) data_get($editorData, 'backgroundImage.mediaId', 0);

                if ($backgroundMediaId === $media->id) {
                    return true;
                }

                $elements = data_get($editorData, 'elements', []);

                return collect($elements)->contains(
                    fn (array $element): bool => (int) ($element['mediaId'] ?? 0) === $media->id,
                );
            });
    }

    private function isUsedInTemplateEditor(PostMedia $media): bool
    {
        return Template::query()
            ->whereNotNull('image_editor_data')
            ->get(['image_editor_data'])
            ->contains(function (Template $template) use ($media): bool {
                $editorData = $template->image_editor_data ?? [];
                $backgroundMediaId = (int) data_get($editorData, 'backgroundImage.mediaId', 0);

                if ($backgroundMediaId === $media->id) {
                    return true;
                }

                $elements = data_get($editorData, 'elements', []);

                return collect($elements)->contains(
                    fn (array $element): bool => (int) ($element['mediaId'] ?? 0) === $media->id,
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMedia(PostMedia $media): array
    {
        $size = $media->file_size;

        if ($size === null && Storage::disk('public')->exists($media->file_path)) {
            $size = Storage::disk('public')->size($media->file_path);
        }

        return [
            'id' => $media->id,
            'post_id' => $media->post_id,
            'folder_id' => $media->folder_id,
            'folder_name' => $media->folder?->name,
            'folder_color' => $media->folder?->color,
            'category' => $media->category,
            'file_path' => $media->file_path,
            'original_name' => $media->original_name ?: basename($media->file_path),
            'mime_type' => $media->mime_type,
            'file_size' => $size,
            'is_featured' => $media->is_featured,
            'created_at' => $media->created_at?->toIso8601String(),
            'url' => Storage::disk('public')->url($media->file_path),
            'used_by_posts_count' => (int) ($media->featured_in_posts_count ?? 0),
            'used_by_templates_count' => (int) ($media->featured_in_templates_count ?? 0),
        ];
    }
}
