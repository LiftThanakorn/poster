<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PublishTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_dashboard_with_operational_metrics(): void
    {
        $user = User::factory()->create();

        // 1. Create Published Post with target
        $published = Post::query()->create([
            'title' => 'ข่าวประกาศผลการสอบ',
            'slug' => 'exam-results',
            'status' => 'published',
            'author_id' => $user->id,
            'published_at' => now(),
        ]);
        PublishTarget::query()->create([
            'post_id' => $published->id,
            'channel' => 'wordpress',
            'status' => 'published',
        ]);

        // 2. Create Scheduled Post
        $scheduled = Post::query()->create([
            'title' => 'เปิดรับสมัครงานบุคคลใหม่',
            'slug' => 'hiring-announcement',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(2),
            'author_id' => $user->id,
        ]);
        PublishTarget::query()->create([
            'post_id' => $scheduled->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        // 3. Create Failed Post (Needs Attention)
        $failed = Post::query()->create([
            'title' => 'ข่าวจัดซื้อจัดจ้างด่วน',
            'slug' => 'procurement-news',
            'status' => 'failed',
            'author_id' => $user->id,
        ]);
        PublishTarget::query()->create([
            'post_id' => $failed->id,
            'channel' => 'facebook',
            'status' => 'failed',
            'error_message' => 'Token Expired',
        ]);

        // 4. Create Draft Post
        Post::query()->create([
            'title' => 'ร่างข่าวสารประจำสัปดาห์',
            'slug' => 'draft-weekly',
            'status' => 'draft',
            'author_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.total', 4)
                ->where('stats.published', 1)
                ->where('stats.scheduled', 1)
                ->where('stats.failed', 1)
                ->where('stats.draft', 1)
                ->where('stats.wordpress_published', 1)
                ->has('attentionPosts', 1)
                ->where('attentionPosts.0.title', 'ข่าวจัดซื้อจัดจ้างด่วน')
                ->has('scheduledPosts', 1)
                ->where('scheduledPosts.0.title', 'เปิดรับสมัครงานบุคคลใหม่')
                ->has('recentPosts', 4)
                ->has('integrations.wordpress')
                ->has('integrations.facebook')
                ->has('mediaStats')
            );
    }

    public function test_posts_index_supports_status_filtering(): void
    {
        $user = User::factory()->create();

        Post::query()->create([
            'title' => 'ข่าวฉบับร่าง 1',
            'slug' => 'draft-1',
            'status' => 'draft',
            'author_id' => $user->id,
        ]);

        Post::query()->create([
            'title' => 'ข่าวเผยแพร่แล้ว 1',
            'slug' => 'published-1',
            'status' => 'published',
            'author_id' => $user->id,
        ]);

        // Filter by 'published'
        $response = $this->actingAs($user)->get(route('posts.index', ['status' => 'published']));
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Posts/Index')
                ->where('currentStatus', 'published')
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'ข่าวเผยแพร่แล้ว 1')
            );

        // Filter by 'draft'
        $response = $this->actingAs($user)->get(route('posts.index', ['status' => 'draft']));
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Posts/Index')
                ->where('currentStatus', 'draft')
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'ข่าวฉบับร่าง 1')
            );
    }
}
