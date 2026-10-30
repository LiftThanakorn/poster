<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteExternalPostJob;
use App\Jobs\DeleteReplacedFacebookPostJob;
use App\Jobs\PublishFacebookJob;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishWordPressJob;
use App\Models\MediaFolder;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Models\Template;
use App\Services\GoogleDriveFilenameBuilder;
use App\Services\IntegrationSettingsService;
use App\Services\QueueTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');

        $posts = Post::query()
            ->select(['id', 'title', 'status', 'created_at', 'updated_at'])
            ->when($status && $status !== 'all', function ($query) use ($status): void {
                if ($status === 'failed') {
                    $query->whereIn('status', ['failed', 'partially_published']);
                } else {
                    $query->where('status', $status);
                }
            })
            ->with(['targets' => fn ($query) => $query
                ->select(['id', 'post_id', 'channel', 'status', 'external_url'])
                ->where('status', '!=', 'detached')])
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Posts/Index', [
            'posts' => $posts,
            'currentStatus' => $status ?? 'all',
        ]);
    }

    public function create(Request $request)
    {
        $initialPdfMediaId = $request->query('pdf_media_id') ? (int) $request->query('pdf_media_id') : null;

        return Inertia::render('Posts/Create', [
            'templates' => Template::query()
                ->select(['id', 'name', 'wp_template', 'fb_template', 'fb_comment', 'featured_image_id', 'image_editor_data', 'fb_comment_2', 'action_buttons', 'pdf_media_id', 'drive_sync_enabled', 'drive_employee_category', 'drive_announcement_stage'])
                ->with([
                    'featuredImage:id,file_path,original_name,mime_type,file_size',
                    'pdfMedia:id,file_path,original_name,mime_type,file_size,drive_web_view_link,drive_file_name',
                ])
                ->orderBy('name')
                ->get(),
            'mediaList' => $this->postFormMediaList($initialPdfMediaId),
            'folders' => MediaFolder::query()->orderBy('name')->get(['id', 'name', 'color']),
            'categories' => PostMedia::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')->all(),
            'wpCategories' => $this->wordpressCategories(),
            'googleDriveConfig' => $this->googleDriveConfig(),
            'initialPdfMediaId' => $initialPdfMediaId,
        ]);
    }

    public function store(Request $request)
    {
        $this->rejectPdfForFacebook($request);

        if (is_string($request->input('action_buttons'))) {
            $decoded = json_decode($request->input('action_buttons'), true);
            if (is_array($decoded)) {
                $request->merge(['action_buttons' => $decoded]);
            }
        }

        if (is_string($request->input('channels'))) {
            $decoded = json_decode($request->input('channels'), true);
            if (is_array($decoded)) {
                $request->merge(['channels' => $decoded]);
            }
        }

        if (is_string($request->input('image_editor_data'))) {
            $decoded = json_decode($request->input('image_editor_data'), true);
            if (is_array($decoded)) {
                $request->merge(['image_editor_data' => $decoded]);
            }
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'wp_content' => 'nullable|string',
            'fb_caption' => 'nullable|string',
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
            'drive_document_date' => 'nullable|date',
            'drive_position_title' => 'nullable|string|max:255',
            'drive_custom_filename' => 'nullable|string|max:255',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:wordpress,facebook',
            'publish_action' => 'required|in:draft,publish,schedule',
            'scheduled_at' => 'nullable|required_if:publish_action,schedule|date|after:now',
            'featured_image_id' => 'nullable|integer',
            'image_editor_data' => 'nullable|array',
            'image_editor_data.canvasWidth' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.canvasHeight' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.backgroundColor' => 'nullable|string|max:32',
            'image_editor_data.backgroundImage' => 'nullable|array',
            'image_editor_data.backgroundImage.src' => 'nullable|string|max:4096',
            'image_editor_data.backgroundImage.mediaId' => 'nullable|integer|exists:post_media,id',
            'image_editor_data.elements' => 'nullable|array|max:100',
            'wp_category_id' => 'nullable|integer',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        $featuredImageId = $validated['featured_image_id'] ?? null;
        $uploadedMedia = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('media', 'public');

            $uploadedMedia = PostMedia::create([
                'post_id' => null,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'is_featured' => true,
            ]);
            $featuredImageId = $uploadedMedia->id;
        }

        $pdfMediaId = $validated['pdf_media_id'] ?? null;
        $uploadedPdfMedia = null;

        if ($request->hasFile('pdf_file')) {
            $pdfFile = $request->file('pdf_file');
            $pdfPath = $pdfFile->store('media', 'public');

            $uploadedPdfMedia = PostMedia::create([
                'post_id' => null,
                'file_path' => $pdfPath,
                'original_name' => $pdfFile->getClientOriginalName(),
                'mime_type' => $pdfFile->getMimeType(),
                'file_size' => $pdfFile->getSize(),
                'category' => 'เอกสารแนบ',
                'is_featured' => false,
            ]);
            $pdfMediaId = $uploadedPdfMedia->id;
        }

        $actionButtons = collect($request->input('action_buttons', []))
            ->filter(fn ($btn) => is_array($btn) && (trim((string) ($btn['label'] ?? '')) !== '' || trim((string) ($btn['url'] ?? '')) !== ''))
            ->map(fn ($btn) => [
                'label' => trim((string) ($btn['label'] ?? '')),
                'url' => trim((string) ($btn['url'] ?? '')),
            ])
            ->values()
            ->all();

        $status = 'draft';
        if ($validated['publish_action'] === 'publish') {
            $status = 'publishing';
        } elseif ($validated['publish_action'] === 'schedule') {
            $status = 'scheduled';
        }

        $post = Post::create([
            'title' => $validated['title'],
            'slug' => Str::slug($validated['title']).'-'.uniqid(),
            'wp_content' => $validated['wp_content'] ?? null,
            'fb_caption' => $validated['fb_caption'] ?? null,
            'fb_comment' => $validated['fb_comment'] ?? null,
            'fb_comment_2' => $validated['fb_comment_2'] ?? null,
            'action_buttons' => ! empty($actionButtons) ? $actionButtons : null,
            'pdf_media_id' => $pdfMediaId,
            'drive_sync_enabled' => $request->boolean('drive_sync_enabled', false),
            'drive_employee_category' => $validated['drive_employee_category'] ?? null,
            'drive_announcement_stage' => $validated['drive_announcement_stage'] ?? null,
            'drive_document_date' => $validated['drive_document_date'] ?? null,
            'drive_position_title' => $validated['drive_position_title'] ?? null,
            'status' => $status,
            'author_id' => $request->user()->id,
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'featured_image_id' => $featuredImageId,
            'image_editor_data' => $validated['image_editor_data'] ?? null,
            'wp_category_id' => $validated['wp_category_id'] ?? null,
            'published_at' => null,
        ]);

        if ($uploadedMedia) {
            $uploadedMedia->update(['post_id' => $post->id]);
        }

        $driveMediaUpdates = [
            'drive_employee_category' => $validated['drive_employee_category'] ?? null,
            'drive_announcement_stage' => $validated['drive_announcement_stage'] ?? null,
            'drive_document_date' => $validated['drive_document_date'] ?? null,
            'drive_position_title' => $validated['drive_position_title'] ?? null,
            'drive_custom_filename' => $validated['drive_custom_filename'] ?? null,
        ];

        if ($uploadedPdfMedia) {
            $uploadedPdfMedia->update(array_merge(['post_id' => $post->id], array_filter($driveMediaUpdates, fn ($v) => $v !== null)));
        } elseif ($pdfMediaId) {
            PostMedia::query()->where('id', $pdfMediaId)->update(array_filter($driveMediaUpdates, fn ($v) => $v !== null));
        }

        foreach ($validated['channels'] as $channel) {
            PublishTarget::create([
                'post_id' => $post->id,
                'channel' => $channel,
                'status' => 'pending',
            ]);
        }

        if ($status === 'publishing') {
            PublishPostJob::dispatch($post);
            QueueTriggerService::trigger();
        } elseif ($status === 'scheduled') {
            PublishPostJob::dispatch($post)->delay($post->scheduled_at);
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => 'สร้างและบันทึกโพสต์สำเร็จ',
                'post_id' => $post->id,
                'redirect_url' => route('posts.index'),
            ]);
        }

        return redirect()->route('posts.index')->with('success', 'สร้างและบันทึกโพสต์สำเร็จ');
    }

    public function show(Post $post)
    {
        $post->load([
            'author:id,name,email',
            'targets' => fn ($query) => $query
                ->where('status', '!=', 'detached')
                ->withCount(['publishAttempts', 'publishEvents']),
        ]);

        $originalPost = $post->replacement_of_post_id
            ? Post::query()->find($post->replacement_of_post_id)
            : null;
        $originalFacebook = $originalPost?->targets()
            ->where('channel', 'facebook')
            ->first();

        return Inertia::render('Posts/Show', [
            'post' => $post,
            'replacementOriginal' => $originalPost ? [
                'id' => $originalPost->id,
                'title' => $originalPost->title,
                'facebook_url' => $originalFacebook?->external_url,
                'facebook_status' => $originalFacebook?->status,
            ] : null,
        ]);
    }

    public function status(Post $post): JsonResponse
    {
        $post->load(['targets' => fn ($query) => $query->where('status', '!=', 'detached')]);

        $hasActiveTargets = $post->targets->contains(
            fn ($t) => in_array($t->status, ['queued', 'processing', 'retrying', 'pending'], true)
        );

        if ($hasActiveTargets) {
            QueueTriggerService::trigger();
        }

        $targets = $post->targets->map(function ($target): array {
            [$progress, $step] = match ($target->status) {
                'published' => [100, 'published'],
                'failed' => [100, 'failed'],
                'processing' => [$target->channel === 'facebook' ? 70 : 60, 'processing'],
                'retrying' => [35, 'retrying'],
                'queued' => [15, 'queued'],
                default => [10, 'pending'],
            };

            return [
                'id' => $target->id,
                'channel' => $target->channel,
                'status' => $target->status,
                'progress' => $progress,
                'step' => $step,
                'external_url' => $target->external_url,
                'error' => $target->error_message,
                'retry_count' => $target->retry_count,
            ];
        })->values();

        return response()->json([
            'id' => $post->id,
            'status' => $post->status,
            'is_terminal' => in_array($post->status, ['draft', 'published', 'failed', 'partially_published'], true),
            'targets' => $targets,
            'updated_at' => $post->updated_at?->toIso8601String(),
        ]);
    }

    public function timeline(Post $post): JsonResponse
    {
        return response()->json([
            'attempts' => $post->publishAttempts()->latest('id')->get(),
            'events' => $post->publishEvents()->latest('id')->get(),
        ]);
    }

    public function retry(Post $post, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, ['wordpress', 'facebook'], true), 404);

        $target = $post->targets()
            ->where('channel', $channel)
            ->where('status', '!=', 'detached')
            ->firstOrFail();

        if (! in_array($target->status, ['failed', 'retrying'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'สามารถลองใหม่ได้เฉพาะช่องทางที่ยังไม่สำเร็จเท่านั้น',
            ], 422);
        }

        $target->update([
            'status' => 'pending',
            'error_message' => null,
        ]);
        $post->update(['status' => 'publishing']);

        $job = $channel === 'wordpress'
            ? new PublishWordPressJob($post->id, $target->id)
            : new PublishFacebookJob($post->id, $target->id);

        dispatch($job)->onConnection('database');

        QueueTriggerService::trigger();

        return response()->json([
            'success' => true,
            'target' => [
                'id' => $target->id,
                'channel' => $target->channel,
                'status' => $target->fresh()->status,
            ],
        ]);
    }

    public function autosave(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'wp_content' => 'nullable|string',
            'fb_caption' => 'nullable|string',
            'fb_comment' => 'nullable|string',
            'fb_comment_2' => 'nullable|string',
            'action_buttons' => 'nullable|array',
            'action_buttons.*.label' => 'nullable|string|max:255',
            'action_buttons.*.url' => 'nullable|string|max:2048',
            'pdf_media_id' => 'nullable|integer|exists:post_media,id',
            'drive_sync_enabled' => 'nullable|boolean',
            'drive_employee_category' => 'nullable|string|max:100',
            'drive_announcement_stage' => 'nullable|string|max:100',
            'drive_document_date' => 'nullable|date',
            'drive_position_title' => 'nullable|string|max:255',
            'channels' => 'nullable|array',
            'channels.*' => 'in:wordpress,facebook',
            'wp_category_id' => 'nullable|integer',
            'featured_image_id' => 'nullable|integer|exists:post_media,id',
            'image_editor_data' => 'nullable|array',
        ]);

        $title = trim((string) ($validated['title'] ?? '')) ?: 'ร่างข่าวที่ยังไม่มีชื่อ';

        $actionButtons = collect($request->input('action_buttons', []))
            ->filter(fn ($btn) => is_array($btn) && (trim((string) ($btn['label'] ?? '')) !== '' || trim((string) ($btn['url'] ?? '')) !== ''))
            ->map(fn ($btn) => [
                'label' => trim((string) ($btn['label'] ?? '')),
                'url' => trim((string) ($btn['url'] ?? '')),
            ])
            ->values()
            ->all();

        $post = Post::query()->create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'wp_content' => $validated['wp_content'] ?? null,
            'fb_caption' => $validated['fb_caption'] ?? null,
            'fb_comment' => $validated['fb_comment'] ?? null,
            'fb_comment_2' => $validated['fb_comment_2'] ?? null,
            'action_buttons' => ! empty($actionButtons) ? $actionButtons : null,
            'pdf_media_id' => $validated['pdf_media_id'] ?? null,
            'drive_sync_enabled' => $request->boolean('drive_sync_enabled', false),
            'drive_employee_category' => $validated['drive_employee_category'] ?? null,
            'drive_announcement_stage' => $validated['drive_announcement_stage'] ?? null,
            'drive_document_date' => $validated['drive_document_date'] ?? null,
            'drive_position_title' => $validated['drive_position_title'] ?? null,
            'status' => 'draft',
            'author_id' => $request->user()->id,
            'wp_category_id' => $validated['wp_category_id'] ?? null,
            'featured_image_id' => $validated['featured_image_id'] ?? null,
            'image_editor_data' => $validated['image_editor_data'] ?? null,
        ]);

        foreach ($validated['channels'] ?? [] as $channel) {
            $post->targets()->create([
                'channel' => $channel,
                'status' => 'pending',
            ]);
        }

        return response()->json([
            'success' => true,
            'post_id' => $post->id,
            'saved_at' => now()->toIso8601String(),
        ]);
    }

    public function updateAutosave(Request $request, Post $post): JsonResponse
    {
        abort_if($post->author_id !== $request->user()->id, 403);

        if ($post->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Autosave ใช้ได้เฉพาะโพสต์ฉบับร่าง',
            ], 422);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'wp_content' => 'nullable|string',
            'fb_caption' => 'nullable|string',
            'fb_comment' => 'nullable|string',
            'fb_comment_2' => 'nullable|string',
            'action_buttons' => 'nullable|array',
            'action_buttons.*.label' => 'nullable|string|max:255',
            'action_buttons.*.url' => 'nullable|string|max:2048',
            'pdf_media_id' => 'nullable|integer|exists:post_media,id',
            'drive_sync_enabled' => 'nullable|boolean',
            'drive_employee_category' => 'nullable|string|max:100',
            'drive_announcement_stage' => 'nullable|string|max:100',
            'drive_document_date' => 'nullable|date',
            'drive_position_title' => 'nullable|string|max:255',
            'channels' => 'nullable|array',
            'channels.*' => 'in:wordpress,facebook',
            'wp_category_id' => 'nullable|integer',
            'featured_image_id' => 'nullable|integer|exists:post_media,id',
            'image_editor_data' => 'nullable|array',
        ]);

        if ($post->replacement_of_post_id && isset($validated['channels']) && $validated['channels'] !== ['facebook']) {
            return response()->json(['success' => false, 'message' => 'โพสต์ทดแทนต้องใช้ Facebook เท่านั้น'], 422);
        }

        $updates = collect($validated)->except('channels')->all();
        if (array_key_exists('title', $updates)) {
            $updates['title'] = trim((string) $updates['title']) ?: $post->title;
        }

        if (array_key_exists('action_buttons', $updates)) {
            $updates['action_buttons'] = collect($updates['action_buttons'] ?? [])
                ->filter(fn ($btn) => is_array($btn) && (trim((string) ($btn['label'] ?? '')) !== '' || trim((string) ($btn['url'] ?? '')) !== ''))
                ->map(fn ($btn) => [
                    'label' => trim((string) ($btn['label'] ?? '')),
                    'url' => trim((string) ($btn['url'] ?? '')),
                ])
                ->values()
                ->all();
            if (empty($updates['action_buttons'])) {
                $updates['action_buttons'] = null;
            }
        }

        $post->fill($updates);
        $post->status = 'draft';
        $post->save();

        if (array_key_exists('channels', $validated)) {
            $channels = $validated['channels'] ?? [];
            $post->targets()->whereNotIn('channel', $channels)->update(['status' => 'detached']);

            foreach ($channels as $channel) {
                $target = $post->targets()->firstOrNew(['channel' => $channel]);
                if (! $target->exists || $target->status === 'detached') {
                    $target->status = 'pending';
                    $target->external_id = null;
                    $target->external_url = null;
                    $target->error_message = null;
                }
                $target->save();
            }
        }

        return response()->json([
            'success' => true,
            'post_id' => $post->id,
            'saved_at' => now()->toIso8601String(),
        ]);
    }

    public function duplicate(Request $request, Post $post)
    {
        $copy = Post::query()->create([
            'title' => $post->title,
            'slug' => Str::slug($post->title).'-copy-'.Str::lower(Str::random(8)),
            'wp_content' => $post->wp_content,
            'fb_caption' => $post->fb_caption,
            'fb_comment' => $post->fb_comment,
            'fb_comment_2' => $post->fb_comment_2,
            'action_buttons' => $post->action_buttons,
            'pdf_media_id' => $post->pdf_media_id,
            'drive_sync_enabled' => $post->drive_sync_enabled,
            'drive_employee_category' => $post->drive_employee_category,
            'drive_announcement_stage' => $post->drive_announcement_stage,
            'drive_document_date' => $post->drive_document_date,
            'drive_position_title' => $post->drive_position_title,
            'wp_category_id' => $post->wp_category_id,
            'featured_image_id' => $post->featured_image_id,
            'image_editor_data' => $post->image_editor_data,
            'status' => 'draft',
            'author_id' => $request->user()->id,
            'scheduled_at' => null,
            'published_at' => null,
        ]);

        foreach ($post->targets()->where('status', '!=', 'detached')->get() as $target) {
            $copy->targets()->create([
                'channel' => $target->channel,
                'status' => 'pending',
                'external_id' => null,
                'external_url' => null,
                'error_message' => null,
                'retry_count' => 0,
            ]);
        }

        return redirect()->route('posts.edit', $copy)->with('success', 'สร้างสำเนาโพสต์เป็นฉบับร่างแล้ว');
    }

    public function facebookReplacement(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->author_id === $request->user()->id, 403);

        $originalTarget = $post->targets()
            ->where('channel', 'facebook')
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->firstOrFail();

        if (is_string($request->input('image_editor_data'))) {
            $decoded = json_decode($request->input('image_editor_data'), true);
            if (is_array($decoded)) {
                $request->merge(['image_editor_data' => $decoded]);
            }
        }

        if (is_string($request->input('action_buttons'))) {
            $decoded = json_decode($request->input('action_buttons'), true);
            if (is_array($decoded)) {
                $request->merge(['action_buttons' => $decoded]);
            }
        }

        $request->merge(['channels' => ['facebook']]);
        $this->rejectPdfForFacebook($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'wp_content' => 'nullable|string',
            'fb_caption' => 'nullable|string',
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
            'drive_document_date' => 'nullable|date',
            'drive_position_title' => 'nullable|string|max:255',
            'drive_custom_filename' => 'nullable|string|max:255',
            'featured_image_id' => 'nullable|integer|exists:post_media,id',
            'image_editor_data' => 'nullable|array',
            'image_editor_data.canvasWidth' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.canvasHeight' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.backgroundColor' => 'nullable|string|max:32',
            'image_editor_data.backgroundImage' => 'nullable|array',
            'image_editor_data.backgroundImage.src' => 'nullable|string|max:4096',
            'image_editor_data.backgroundImage.mediaId' => 'nullable|integer|exists:post_media,id',
            'image_editor_data.elements' => 'nullable|array|max:100',
            'wp_category_id' => 'nullable|integer',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $copy = DB::transaction(function () use ($request, $post, $validated): Post {
            $featuredImageId = $validated['featured_image_id'] ?? null;
            $pdfMediaId = $validated['pdf_media_id'] ?? $post->pdf_media_id;
            $newMedia = [];

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $newMedia[] = PostMedia::create([
                    'file_path' => $file->store('media', 'public'),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'is_featured' => true,
                ]);
                $featuredImageId = $newMedia[array_key_last($newMedia)]->id;
            }

            if ($request->hasFile('pdf_file')) {
                $file = $request->file('pdf_file');
                $newMedia[] = PostMedia::create([
                    'file_path' => $file->store('media', 'public'),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'category' => 'เอกสารแนบ',
                    'is_featured' => false,
                ]);
                $pdfMediaId = $newMedia[array_key_last($newMedia)]->id;
            }

            $actionButtons = collect($validated['action_buttons'] ?? [])
                ->filter(fn ($button) => trim((string) ($button['label'] ?? '')) !== '' || trim((string) ($button['url'] ?? '')) !== '')
                ->map(fn ($button) => [
                    'label' => trim((string) ($button['label'] ?? '')),
                    'url' => trim((string) ($button['url'] ?? '')),
                ])->values()->all();

            $copy = Post::query()->create([
                'replacement_of_post_id' => $post->id,
                'title' => $validated['title'],
                'slug' => Str::slug($validated['title']).'-replacement-'.Str::lower(Str::random(8)),
                'wp_content' => $validated['wp_content'] ?? $post->wp_content,
                'fb_caption' => $validated['fb_caption'] ?? null,
                'fb_comment' => $validated['fb_comment'] ?? null,
                'fb_comment_2' => $validated['fb_comment_2'] ?? null,
                'action_buttons' => $actionButtons ?: null,
                'pdf_media_id' => $pdfMediaId,
                'drive_sync_enabled' => $request->has('drive_sync_enabled') ? $request->boolean('drive_sync_enabled') : $post->drive_sync_enabled,
                'drive_employee_category' => $validated['drive_employee_category'] ?? $post->drive_employee_category,
                'drive_announcement_stage' => $validated['drive_announcement_stage'] ?? $post->drive_announcement_stage,
                'drive_document_date' => $validated['drive_document_date'] ?? $post->drive_document_date,
                'drive_position_title' => $validated['drive_position_title'] ?? $post->drive_position_title,
                'wp_category_id' => $validated['wp_category_id'] ?? $post->wp_category_id,
                'featured_image_id' => $featuredImageId,
                'image_editor_data' => $validated['image_editor_data'] ?? null,
                'status' => 'draft',
                'author_id' => $request->user()->id,
                'published_at' => null,
                'scheduled_at' => null,
            ]);

            foreach ($newMedia as $media) {
                $media->update(['post_id' => $copy->id]);
            }

            $copy->targets()->create(['channel' => 'facebook', 'status' => 'pending']);

            return $copy;
        });

        return response()->json([
            'success' => true,
            'message' => 'สร้างฉบับร่าง Facebook ใหม่แล้ว โพสต์เดิมยังอยู่',
            'post_id' => $copy->id,
            'redirect_url' => route('posts.edit', $copy),
        ]);
    }

    public function deleteReplacedFacebook(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->author_id === $request->user()->id, 403);
        abort_unless($post->replacement_of_post_id, 404);

        $newTarget = $post->targets()
            ->where('channel', 'facebook')
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->firstOrFail();

        $original = Post::query()->findOrFail($post->replacement_of_post_id);
        abort_unless($original->author_id === $request->user()->id, 403);

        $target = DB::transaction(function () use ($original): PublishTarget {
            $target = $original->targets()
                ->where('channel', 'facebook')
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($target->status === 'published' && $target->external_id, 422);

            $target->update(['status' => 'deleting', 'error_message' => null]);

            return $target;
        });

        DeleteReplacedFacebookPostJob::dispatch($target->id, $target->external_id)->onConnection('database');
        QueueTriggerService::trigger();

        return response()->json([
            'success' => true,
            'message' => 'ส่งคำขอลบโพสต์เก่าเข้าคิวแล้ว โพสต์ใหม่ไม่ถูกกระทบ',
        ]);
    }

    public function edit(Post $post)
    {
        $post->load([
            'targets' => fn ($query) => $query->where('status', '!=', 'detached'),
            'pdfMedia:id,file_path,original_name,mime_type,file_size,drive_file_id,drive_file_name,drive_web_view_link,drive_employee_category,drive_announcement_stage,drive_document_date,drive_position_title,drive_sync_status',
        ]);

        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'templates' => Template::query()
                ->select(['id', 'name', 'wp_template', 'fb_template', 'fb_comment', 'featured_image_id', 'image_editor_data', 'fb_comment_2', 'action_buttons', 'pdf_media_id', 'drive_sync_enabled', 'drive_employee_category', 'drive_announcement_stage'])
                ->with([
                    'featuredImage:id,file_path,original_name,mime_type,file_size',
                    'pdfMedia:id,file_path,original_name,mime_type,file_size,drive_web_view_link,drive_file_name',
                ])
                ->orderBy('name')
                ->get(),
            'mediaList' => $this->postFormMediaList($post->featured_image_id, $post->pdf_media_id),
            'folders' => MediaFolder::query()->orderBy('name')->get(['id', 'name', 'color']),
            'categories' => PostMedia::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')->all(),
            'wpCategories' => $this->wordpressCategories(),
            'googleDriveConfig' => $this->googleDriveConfig(),
        ]);
    }

    public function update(Request $request, Post $post)
    {
        $this->rejectPdfForFacebook($request);

        if (is_string($request->input('action_buttons'))) {
            $decoded = json_decode($request->input('action_buttons'), true);
            if (is_array($decoded)) {
                $request->merge(['action_buttons' => $decoded]);
            }
        }

        if (is_string($request->input('channels'))) {
            $decoded = json_decode($request->input('channels'), true);
            if (is_array($decoded)) {
                $request->merge(['channels' => $decoded]);
            }
        }

        if (is_string($request->input('image_editor_data'))) {
            $decoded = json_decode($request->input('image_editor_data'), true);
            if (is_array($decoded)) {
                $request->merge(['image_editor_data' => $decoded]);
            }
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'wp_content' => 'nullable|string',
            'fb_caption' => 'nullable|string',
            'fb_comment' => 'nullable|string',
            'fb_comment_2' => 'nullable|string',
            'action_buttons' => 'nullable|array',
            'action_buttons.*.label' => 'nullable|string|max:255',
            'action_buttons.*.url' => 'nullable|string|max:2048',
            'pdf_media_id' => 'nullable|integer',
            'pdf_file' => 'nullable|file|mimes:pdf|max:20480',
            'drive_sync_enabled' => 'nullable|boolean',
            'drive_employee_category' => 'nullable|string|max:100',
            'drive_announcement_stage' => 'nullable|string|max:100',
            'drive_document_date' => 'nullable|date',
            'drive_position_title' => 'nullable|string|max:255',
            'drive_custom_filename' => 'nullable|string|max:255',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:wordpress,facebook',
            'publish_action' => 'required|in:draft,publish,schedule',
            'scheduled_at' => 'nullable|required_if:publish_action,schedule|date|after:now',
            'featured_image_id' => 'nullable|integer',
            'image_editor_data' => 'nullable|array',
            'image_editor_data.canvasWidth' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.canvasHeight' => 'nullable|integer|min:320|max:4096',
            'image_editor_data.backgroundColor' => 'nullable|string|max:32',
            'image_editor_data.backgroundImage' => 'nullable|array',
            'image_editor_data.backgroundImage.src' => 'nullable|string|max:4096',
            'image_editor_data.backgroundImage.mediaId' => 'nullable|integer|exists:post_media,id',
            'image_editor_data.elements' => 'nullable|array|max:100',
            'wp_category_id' => 'nullable|integer',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        if ($post->replacement_of_post_id && $validated['channels'] !== ['facebook']) {
            throw ValidationException::withMessages([
                'channels' => 'โพสต์ทดแทนนี้ใช้เผยแพร่ Facebook เท่านั้น เพื่อป้องกันข่าว WordPress ซ้ำ',
            ]);
        }

        $hasExistingFacebookPost = $post->targets()
            ->where('channel', 'facebook')
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->exists();

        $imageChanged = $request->hasFile('file')
            || (array_key_exists('featured_image_id', $validated)
                && (string) ($validated['featured_image_id'] ?? '') !== (string) ($post->featured_image_id ?? ''));

        if ($hasExistingFacebookPost && $imageChanged
            && in_array('facebook', $validated['channels'], true)) {
            throw ValidationException::withMessages([
                'featured_image_id' => 'Facebook ไม่สามารถแทนที่รูปในโพสต์เดิมได้ กรุณาสร้างฉบับร่าง Facebook ใหม่เพื่อรักษาโพสต์ต้นฉบับ',
            ]);
        }

        $featuredImageId = $post->featured_image_id;
        if (array_key_exists('featured_image_id', $validated) || $request->exists('featured_image_id')) {
            $featuredImageId = ! empty($validated['featured_image_id']) ? (int) $validated['featured_image_id'] : null;
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('media', 'public');

            $media = PostMedia::create([
                'post_id' => $post->id,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'is_featured' => true,
            ]);
            $featuredImageId = $media->id;
        }

        $pdfMediaId = $post->pdf_media_id;
        if (array_key_exists('pdf_media_id', $validated) || $request->exists('pdf_media_id')) {
            $pdfMediaId = ! empty($validated['pdf_media_id']) ? (int) $validated['pdf_media_id'] : null;
        }

        if ($request->hasFile('pdf_file')) {
            $pdfFile = $request->file('pdf_file');
            $pdfPath = $pdfFile->store('media', 'public');

            $pdfMedia = PostMedia::create([
                'post_id' => $post->id,
                'file_path' => $pdfPath,
                'original_name' => $pdfFile->getClientOriginalName(),
                'mime_type' => $pdfFile->getMimeType(),
                'file_size' => $pdfFile->getSize(),
                'category' => 'เอกสารแนบ',
                'is_featured' => false,
            ]);
            $pdfMediaId = $pdfMedia->id;
        }

        $actionButtons = $post->action_buttons;
        if ($request->has('action_buttons')) {
            $actionButtons = collect($request->input('action_buttons', []))
                ->filter(fn ($btn) => is_array($btn) && (trim((string) ($btn['label'] ?? '')) !== '' || trim((string) ($btn['url'] ?? '')) !== ''))
                ->map(fn ($btn) => [
                    'label' => trim((string) ($btn['label'] ?? '')),
                    'url' => trim((string) ($btn['url'] ?? '')),
                ])
                ->values()
                ->all();
        }

        $status = $post->status;
        if ($validated['publish_action'] === 'publish') {
            $status = 'publishing';
        } elseif ($validated['publish_action'] === 'schedule') {
            $status = 'scheduled';
        }

        $driveMediaUpdates = [
            'drive_employee_category' => $validated['drive_employee_category'] ?? null,
            'drive_announcement_stage' => $validated['drive_announcement_stage'] ?? null,
            'drive_document_date' => $validated['drive_document_date'] ?? null,
            'drive_position_title' => $validated['drive_position_title'] ?? null,
            'drive_custom_filename' => $validated['drive_custom_filename'] ?? null,
        ];

        if (isset($pdfMedia)) {
            $pdfMedia->update(array_filter($driveMediaUpdates, fn ($v) => $v !== null));
        } elseif ($pdfMediaId) {
            PostMedia::query()->where('id', $pdfMediaId)->update(array_filter($driveMediaUpdates, fn ($v) => $v !== null));
        }

        $post->update([
            'title' => $validated['title'],
            'wp_content' => $validated['wp_content'] ?? null,
            'fb_caption' => $validated['fb_caption'] ?? null,
            'fb_comment' => $validated['fb_comment'] ?? null,
            'fb_comment_2' => $validated['fb_comment_2'] ?? null,
            'action_buttons' => ! empty($actionButtons) ? $actionButtons : null,
            'pdf_media_id' => $pdfMediaId,
            'drive_sync_enabled' => $request->has('drive_sync_enabled') ? $request->boolean('drive_sync_enabled') : $post->drive_sync_enabled,
            'drive_employee_category' => $validated['drive_employee_category'] ?? $post->drive_employee_category,
            'drive_announcement_stage' => $validated['drive_announcement_stage'] ?? $post->drive_announcement_stage,
            'drive_document_date' => $validated['drive_document_date'] ?? $post->drive_document_date,
            'drive_position_title' => $validated['drive_position_title'] ?? $post->drive_position_title,
            'status' => $status,
            'scheduled_at' => $validated['scheduled_at'] ?? $post->scheduled_at,
            'featured_image_id' => $featuredImageId,
            'image_editor_data' => $request->has('image_editor_data')
                ? ($validated['image_editor_data'] ?? null)
                : $post->image_editor_data,
            'wp_category_id' => $validated['wp_category_id'] ?? $post->wp_category_id,
        ]);

        $post->targets()
            ->whereNotIn('channel', $validated['channels'])
            ->update(['status' => 'detached']);

        foreach ($validated['channels'] as $channel) {
            $target = $post->targets()->firstOrNew(['channel' => $channel]);

            if ($status === 'publishing' || $status === 'scheduled' || ! $target->exists) {
                $target->status = 'pending';
                $target->error_message = null;
            }

            $target->save();
        }

        if ($status === 'publishing') {
            PublishPostJob::dispatch($post);
            QueueTriggerService::trigger();
        } elseif ($status === 'scheduled') {
            PublishPostJob::dispatch($post)->delay($post->scheduled_at);
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => 'บันทึกการแก้ไขโพสต์สำเร็จ',
                'post_id' => $post->id,
                'redirect_url' => route('posts.index'),
            ]);
        }

        return redirect()->route('posts.index')->with('success', 'บันทึกการแก้ไขโพสต์สำเร็จ');
    }

    private function postFormMediaList(?int ...$extraIds): Collection
    {
        $media = PostMedia::query()
            ->select(['id', 'file_path', 'mime_type', 'folder_id', 'category', 'original_name'])
            ->with(['folder:id,name,color'])
            ->latest()
            ->limit(100)
            ->get();

        $templateMediaIds = Template::query()
            ->pluck('featured_image_id')
            ->concat(Template::query()->pluck('pdf_media_id'))
            ->filter();

        $validExtraIds = collect($extraIds)->filter();

        $missingIds = $templateMediaIds->concat($validExtraIds)->diff($media->pluck('id'))->unique();

        if ($missingIds->isNotEmpty()) {
            $media = $media->concat(
                PostMedia::query()
                    ->select(['id', 'file_path', 'mime_type', 'folder_id', 'category', 'original_name'])
                    ->with(['folder:id,name,color'])
                    ->whereIn('id', $missingIds)
                    ->get(),
            );
        }

        return $media->unique('id')->values();
    }

    private function rejectPdfForFacebook(Request $request): void
    {
        $channels = (array) $request->input('channels', []);

        if (! in_array('facebook', $channels, true)) {
            return;
        }

        $file = $request->file('file');
        if ($file && $file->getMimeType() === 'application/pdf') {
            throw ValidationException::withMessages([
                'file' => 'Facebook ไม่รองรับไฟล์ PDF เป็นรูปประกอบ กรุณาเลือกไฟล์รูปภาพ JPG, PNG หรือ WEBP',
            ]);
        }

        $featuredImageId = $request->input('featured_image_id');
        if ($featuredImageId) {
            $media = PostMedia::query()->find($featuredImageId);

            if ($media?->mime_type === 'application/pdf') {
                throw ValidationException::withMessages([
                    'featured_image_id' => 'ไม่สามารถใช้ไฟล์ PDF เป็นรูปประกอบสำหรับ Facebook ได้',
                ]);
            }
        }
    }

    protected function wordpressCategories(): array
    {
        $wordpress = app(IntegrationSettingsService::class)->wordpress();
        $url = rtrim($wordpress['url'], '/');

        if ($url === '' || $wordpress['username'] === '') {
            return [];
        }

        return Cache::remember('wordpress.categories', now()->addHour(), function () use ($url): array {
            try {
                $response = Http::connectTimeout(2)
                    ->timeout(5)
                    ->get($url.'/wp-json/wp/v2/categories', ['per_page' => 100]);

                return $response->successful() ? $response->json() : [];
            } catch (\Throwable) {
                return [];
            }
        });
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

    public function destroy(Request $request, Post $post)
    {
        $remoteTargets = $request->boolean('delete_external')
            ? $post->targets()
                ->where('status', 'published')
                ->whereNotNull('external_id')
                ->get(['channel', 'external_id'])
            : collect();

        $postId = $post->id;
        $post->delete();

        foreach ($remoteTargets as $target) {
            DeleteExternalPostJob::dispatch($target->channel, $target->external_id, $postId);
        }

        if ($remoteTargets->isNotEmpty()) {
            QueueTriggerService::trigger();
        }

        $message = 'ลบโพสต์สำเร็จ';
        if ($remoteTargets->isNotEmpty()) {
            $channels = $remoteTargets
                ->map(fn ($target) => $target->channel === 'wordpress' ? 'WordPress' : 'Facebook')
                ->implode(' และ ');
            $message .= " — กำลังลบบน {$channels} เบื้องหลัง";
        }

        return redirect()->back()->with('success', $message);
    }
}
