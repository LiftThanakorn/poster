<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\Post;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\Publishers\PublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiWordPressAndGeneralPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_general_post_without_pdf_for_wordpress_secondary(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'title' => 'ข่าวประชาสัมพันธ์ทั่วไปประจำสัปดาห์',
            'post_type' => 'general',
            'wp_content' => '<p>เนื้อหาข่าวประชาสัมพันธ์ทั่วไป ไม่จำเป็นต้องมีไฟล์ PDF ประกาศ</p>',
            'channels' => ['wordpress_secondary'],
            'publish_action' => 'draft',
            'wp_secondary_category_id' => 12,
        ]);

        $response->assertRedirect(route('posts.index'));

        $post = Post::query()->where('title', 'ข่าวประชาสัมพันธ์ทั่วไปประจำสัปดาห์')->firstOrFail();

        $this->assertSame('general', $post->post_type);
        $this->assertNull($post->pdf_media_id);
        $this->assertSame(12, $post->wp_secondary_category_id);

        $this->assertDatabaseHas('publish_targets', [
            'post_id' => $post->id,
            'channel' => 'wordpress_secondary',
            'status' => 'pending',
        ]);
    }

    public function test_user_can_create_post_publishing_to_both_web1_and_web2(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'title' => 'ประกาศเผยแพร่ 2 เว็บไซต์พร้อมกัน',
            'post_type' => 'general',
            'wp_content' => '<p>ข่าวประกาศลงทั้งสองเว็บไซต์</p>',
            'channels' => ['wordpress', 'wordpress_secondary'],
            'publish_action' => 'draft',
            'wp_category_id' => 5,
            'wp_secondary_category_id' => 15,
        ]);

        $response->assertRedirect(route('posts.index'));

        $post = Post::query()->where('title', 'ประกาศเผยแพร่ 2 เว็บไซต์พร้อมกัน')->firstOrFail();

        $targets = $post->targets()->pluck('channel')->all();
        $this->assertContains('wordpress', $targets);
        $this->assertContains('wordpress_secondary', $targets);
        $this->assertSame(5, $post->wp_category_id);
        $this->assertSame(15, $post->wp_secondary_category_id);
    }

    public function test_publishing_service_publishes_to_wordpress_secondary(): void
    {
        $user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress_secondary',
            'settings' => ['url' => 'https://web2.example.com', 'username' => 'editor2'],
            'encrypted_credentials' => ['password' => 'app-pass-2'],
        ]);

        Http::fake([
            'https://web2.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 888,
                'link' => 'https://web2.example.com/news-888',
            ], 201),
        ]);

        $post = Post::query()->create([
            'title' => 'ทดสอบส่งขึ้น Web 2 จริง',
            'post_type' => 'general',
            'slug' => 'test-web-2',
            'wp_content' => '<p>ทดสอบเนื้อหา</p>',
            'wp_secondary_category_id' => 20,
            'status' => 'publishing',
            'author_id' => $user->id,
        ]);

        $target = PublishTarget::query()->create([
            'post_id' => $post->id,
            'channel' => 'wordpress_secondary',
            'status' => 'pending',
        ]);

        app(PublishingService::class)->publishPost($post);

        $target->refresh();
        $this->assertSame('published', $target->status);
        $this->assertSame('888', $target->external_id);
        $this->assertSame('https://web2.example.com/news-888', $target->external_url);
    }
}
