<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostImageEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_featured_image_and_editor_layout(): void
    {
        $user = User::factory()->create();
        $media = $this->createImageMedia('media/post-design.png');

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'title' => 'Post with design',
            'channels' => ['wordpress'],
            'publish_action' => 'draft',
            'featured_image_id' => $media->id,
            'image_editor_data' => $this->editorData($media->id),
        ]);

        $response->assertRedirect(route('posts.index'));
        $post = Post::query()->firstOrFail();

        $this->assertSame($media->id, $post->featured_image_id);
        $this->assertSame(1080, $post->image_editor_data['canvasWidth']);
        $this->assertSame(
            'ข้อความทดสอบ',
            $post->image_editor_data['elements'][0]['text'],
        );
    }

    public function test_update_replaces_featured_image_and_editor_layout(): void
    {
        $user = User::factory()->create();
        $oldMedia = $this->createImageMedia('media/old.png');
        $newMedia = $this->createImageMedia('media/new.png');

        $post = Post::query()->create([
            'title' => 'Existing post',
            'slug' => 'existing-post',
            'status' => 'draft',
            'author_id' => $user->id,
            'featured_image_id' => $oldMedia->id,
        ]);
        $response = $this->actingAs($user)->put(route('posts.update', $post), [
            'title' => 'Existing post',
            'channels' => ['wordpress'],
            'publish_action' => 'draft',
            'featured_image_id' => $newMedia->id,
            'image_editor_data' => $this->editorData($newMedia->id),
        ]);

        $response->assertRedirect(route('posts.index'));

        $post->refresh();
        $this->assertSame($newMedia->id, $post->featured_image_id);
        $this->assertSame(
            $newMedia->id,
            $post->image_editor_data['elements'][1]['mediaId'],
        );
    }

    public function test_media_used_by_post_editor_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $media = $this->createImageMedia('media/layer.png');

        Post::query()->create([
            'title' => 'Editable post',
            'slug' => 'editable-post',
            'status' => 'draft',
            'author_id' => $user->id,
            'image_editor_data' => $this->editorData($media->id),
        ]);

        $this->actingAs($user)
            ->delete(route('media.destroy', $media->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('post_media', ['id' => $media->id]);
    }

    public function test_duplicate_preserves_editor_layout(): void
    {
        $user = User::factory()->create();
        $media = $this->createImageMedia('media/duplicate.png');

        $post = Post::query()->create([
            'title' => 'Original',
            'slug' => 'original',
            'status' => 'published',
            'author_id' => $user->id,
            'featured_image_id' => $media->id,
            'image_editor_data' => $this->editorData($media->id),
        ]);

        $this->actingAs($user)
            ->post(route('posts.duplicate', $post))
            ->assertRedirect();

        $copy = Post::query()->whereKeyNot($post->id)->firstOrFail();

        $this->assertSame($media->id, $copy->featured_image_id);
        $this->assertSame(
            $post->image_editor_data,
            $copy->image_editor_data,
        );
    }

    private function createImageMedia(string $path): PostMedia
    {
        return PostMedia::query()->create([
            'file_path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'image/png',
            'file_size' => 1234,
            'is_featured' => false,
        ]);
    }

    private function editorData(int $mediaId): array
    {
        return [
            'version' => 1,
            'canvasWidth' => 1080,
            'canvasHeight' => 1080,
            'backgroundColor' => '#ffffff',
            'backgroundImage' => null,
            'elements' => [
                [
                    'id' => 'text-1',
                    'type' => 'text',
                    'text' => 'ข้อความทดสอบ',
                    'x' => 80,
                    'y' => 80,
                    'fontSize' => 64,
                ],
                [
                    'id' => 'image-1',
                    'type' => 'image',
                    'mediaId' => $mediaId,
                    'src' => "/storage/media/layer-{$mediaId}.png",
                ],
            ],
        ];
    }
}
