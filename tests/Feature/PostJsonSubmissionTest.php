<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostJsonSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_post_via_json_request_with_action_buttons(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('posts.store'),
            [
                'title' => 'ทดสอบสร้างโพสต์ผ่านระบบติดตามสถานะ',
                'wp_content' => '<p>เนื้อหาทดสอบ</p>',
                'fb_caption' => 'แคปชั่น Facebook',
                'channels' => ['wordpress', 'facebook'],
                'publish_action' => 'draft',
                'action_buttons' => [
                    ['label' => 'ใบสมัครออนไลน์', 'url' => 'https://example.com/apply'],
                ],
            ],
            [
                'Accept' => 'application/json',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'สร้างและบันทึกโพสต์สำเร็จ')
            ->assertJsonPath('redirect_url', route('posts.index'));

        $this->assertDatabaseHas('posts', [
            'title' => 'ทดสอบสร้างโพสต์ผ่านระบบติดตามสถานะ',
            'status' => 'draft',
        ]);
    }

    public function test_can_update_post_via_json_request(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $post = Post::query()->create([
            'title' => 'โพสต์เดิมก่อนอัปเดต',
            'slug' => 'original-post',
            'wp_content' => '<p>เนื้อหาเดิม</p>',
            'status' => 'draft',
            'author_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(
            route('posts.update', $post),
            [
                '_method' => 'put',
                'title' => 'โพสต์ที่อัปเดตสำเร็จแล้ว',
                'wp_content' => '<p>เนื้อหาใหม่</p>',
                'fb_caption' => 'แคปชั่นใหม่',
                'channels' => ['wordpress'],
                'publish_action' => 'draft',
            ],
            [
                'Accept' => 'application/json',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'บันทึกการแก้ไขโพสต์สำเร็จ')
            ->assertJsonPath('post_id', $post->id)
            ->assertJsonPath('redirect_url', route('posts.index'));

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'โพสต์ที่อัปเดตสำเร็จแล้ว',
        ]);
    }

    public function test_validation_error_returns_422_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('posts.store'),
            [
                'title' => '',
                'channels' => [],
            ],
            [
                'Accept' => 'application/json',
            ],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'channels']);
    }
}
