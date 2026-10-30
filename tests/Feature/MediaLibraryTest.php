<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_media_with_metadata(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('manual.pdf', 128, 'application/pdf');

        $response = $this->actingAs($user)->post(route('media.store'), [
            'file' => $file,
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'อัปโหลดไฟล์เรียบร้อยแล้ว');

        $media = PostMedia::query()->firstOrFail();

        $this->assertSame('manual.pdf', $media->original_name);
        $this->assertSame('application/pdf', $media->mime_type);
        $this->assertGreaterThan(0, $media->file_size);
        Storage::disk('public')->assertExists($media->file_path);
    }

    public function test_media_page_supports_type_filter_and_exposes_usage_state(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $image = PostMedia::query()->create([
            'file_path' => 'media/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1200,
        ]);

        PostMedia::query()->create([
            'file_path' => 'media/manual.pdf',
            'original_name' => 'manual.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2400,
        ]);

        Post::query()->create([
            'title' => 'ข่าวทดสอบ',
            'slug' => 'media-library-test',
            'status' => 'draft',
            'featured_image_id' => $image->id,
            'author_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('media.index', ['type' => 'image']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->where('filters.type', 'image')
                ->where('stats.total', 2)
                ->where('stats.images', 1)
                ->where('stats.pdfs', 1)
                ->has('media.data', 1)
                ->where('media.data.0.original_name', 'photo.jpg')
                ->where('media.data.0.used_by_posts_count', 1));
    }

    public function test_media_used_as_featured_image_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Storage::disk('public')->put('media/used.jpg', 'image-bytes');

        $media = PostMedia::query()->create([
            'file_path' => 'media/used.jpg',
            'original_name' => 'used.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
        ]);

        Post::query()->create([
            'title' => 'ข่าวที่ใช้รูป',
            'slug' => 'post-using-media',
            'status' => 'draft',
            'featured_image_id' => $media->id,
            'author_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->delete(route('media.destroy', $media->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('post_media', ['id' => $media->id]);
        Storage::disk('public')->assertExists('media/used.jpg');
    }
}
