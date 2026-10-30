<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostUpdateWithFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_can_be_updated_using_post_method_spoofing_with_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $post = Post::query()->create([
            'title' => 'Initial Title',
            'slug' => 'initial-title',
            'author_id' => $user->id,
            'status' => 'draft',
        ]);

        $file = UploadedFile::fake()->image('photo.jpg');

        $response = $this->actingAs($user)->post(route('posts.update', $post->id), [
            '_method' => 'put',
            'title' => 'Updated Title With File',
            'wp_content' => 'Sample content',
            'fb_caption' => 'Sample caption',
            'channels' => ['facebook'],
            'publish_action' => 'draft',
            'file' => $file,
        ]);

        $response->assertRedirect(route('posts.index'));
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'Updated Title With File',
        ]);
        $this->assertDatabaseHas('post_media', [
            'post_id' => $post->id,
            'original_name' => 'photo.jpg',
        ]);
    }
}
