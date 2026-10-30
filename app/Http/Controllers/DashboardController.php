<?php

namespace App\Http\Controllers;

use App\Models\MediaFolder;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Models\Template;
use App\Services\IntegrationSettingsService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly IntegrationSettingsService $settings,
    ) {}

    public function index(): Response
    {
        // 1. Post Counts by Status
        $counts = Post::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published")
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'partially_published') THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft")
            ->selectRaw("SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled")
            ->selectRaw("SUM(CASE WHEN status IN ('publishing', 'queued', 'processing', 'retrying') THEN 1 ELSE 0 END) as processing")
            ->first();

        $stats = [
            'total' => (int) ($counts->total ?? 0),
            'published' => (int) ($counts->published ?? 0),
            'failed' => (int) ($counts->failed ?? 0),
            'draft' => (int) ($counts->draft ?? 0),
            'scheduled' => (int) ($counts->scheduled ?? 0),
            'processing' => (int) ($counts->processing ?? 0),
            'wordpress_published' => PublishTarget::query()->where('channel', 'wordpress')->where('status', 'published')->count(),
            'facebook_published' => PublishTarget::query()->where('channel', 'facebook')->where('status', 'published')->count(),
        ];

        // 2. Posts Needing Attention (failed or partially_published)
        $attentionPosts = Post::query()
            ->select(['id', 'title', 'status', 'created_at', 'updated_at'])
            ->whereIn('status', ['failed', 'partially_published'])
            ->with(['targets' => fn ($query) => $query
                ->select(['id', 'post_id', 'channel', 'status', 'error_message', 'retry_count'])
                ->where('status', '!=', 'detached')])
            ->latest('updated_at')
            ->take(5)
            ->get();

        // 3. Upcoming Scheduled Posts
        $scheduledPosts = Post::query()
            ->select(['id', 'title', 'status', 'scheduled_at', 'created_at'])
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->with(['targets' => fn ($query) => $query
                ->select(['id', 'post_id', 'channel', 'status'])
                ->where('status', '!=', 'detached')])
            ->orderBy('scheduled_at')
            ->take(5)
            ->get();

        // 4. Recent Posts
        $recentPosts = Post::query()
            ->select(['id', 'title', 'status', 'author_id', 'created_at', 'published_at', 'scheduled_at'])
            ->with([
                'author:id,name',
                'targets' => fn ($query) => $query
                    ->select(['id', 'post_id', 'channel', 'status', 'external_url'])
                    ->where('status', '!=', 'detached'),
            ])
            ->latest()
            ->take(8)
            ->get();

        // 5. Integrations Status Summary
        $wp = $this->settings->wordpress();
        $fb = $this->settings->facebook();

        $wpConfigured = $wp['url'] !== '' && $wp['username'] !== '' && $wp['password'] !== '';
        $fbConfigured = $fb['page_id'] !== '' && $fb['access_token'] !== '';

        $integrations = [
            'wordpress' => [
                'name' => 'WordPress Website',
                'url' => $wp['url'],
                'username' => $wp['username'],
                'configured' => $wpConfigured,
                'status' => $wpConfigured ? ($wp['status'] === 'connected' ? 'connected' : ($wp['status'] === 'error' ? 'error' : 'configured')) : 'disconnected',
                'last_checked_at' => $wp['last_checked_at']?->toIso8601String(),
            ],
            'facebook' => [
                'name' => 'Facebook Page',
                'page_id' => $fb['page_id'],
                'configured' => $fbConfigured,
                'status' => $fbConfigured ? ($fb['status'] === 'connected' ? 'connected' : ($fb['status'] === 'error' ? 'error' : 'configured')) : 'disconnected',
                'last_checked_at' => $fb['last_checked_at']?->toIso8601String(),
            ],
        ];

        // 6. Media Library & Storage Stats
        $mediaStats = [
            'total_files' => PostMedia::query()->count(),
            'images' => PostMedia::query()->where('mime_type', 'like', 'image/%')->count(),
            'pdfs' => PostMedia::query()->where('mime_type', 'application/pdf')->count(),
            'folders' => MediaFolder::query()->count(),
            'bytes' => (int) PostMedia::query()->sum('file_size'),
            'templates_count' => Template::query()->count(),
        ];

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'attentionPosts' => $attentionPosts,
            'scheduledPosts' => $scheduledPosts,
            'recentPosts' => $recentPosts,
            'integrations' => $integrations,
            'mediaStats' => $mediaStats,
        ]);
    }
}
