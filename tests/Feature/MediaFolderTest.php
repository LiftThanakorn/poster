<?php

namespace Tests\Feature;

use App\Models\MediaFolder;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MediaFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_delete_folder(): void
    {
        $user = User::factory()->create();

        // 1. Create
        $response = $this->actingAs($user)->post(route('media.folders.store'), [
            'name' => 'รูปกิจกรรม',
            'color' => '#E91E63',
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('media_folders', [
            'name' => 'รูปกิจกรรม',
            'color' => '#E91E63',
        ]);

        $folder = MediaFolder::query()->firstOrFail();

        // 2. Update
        $this->actingAs($user)->put(route('media.folders.update', $folder->id), [
            'name' => 'รูปกิจกรรม ประจำปี 2026',
            'color' => '#4CAF50',
        ])->assertRedirect();

        $this->assertDatabaseHas('media_folders', [
            'id' => $folder->id,
            'name' => 'รูปกิจกรรม ประจำปี 2026',
            'color' => '#4CAF50',
        ]);

        // 3. Move media to folder
        $media = PostMedia::query()->create([
            'file_path' => 'media/test.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->actingAs($user)->post(route('media.move'), [
            'media_ids' => [$media->id],
            'folder_id' => $folder->id,
        ])->assertRedirect();

        $this->assertSame($folder->id, $media->fresh()->folder_id);

        // 4. Delete folder -> media moved to root
        $this->actingAs($user)->delete(route('media.folders.destroy', $folder->id))->assertRedirect();
        $this->assertDatabaseMissing('media_folders', ['id' => $folder->id]);
        $this->assertNull($media->fresh()->folder_id);
    }

    public function test_user_can_create_nested_subfolders(): void
    {
        $user = User::factory()->create();

        // 1. Create Parent Folder
        $this->actingAs($user)->post(route('media.folders.store'), [
            'name' => 'งานบุคคล',
            'color' => '#4285F4',
        ])->assertRedirect();

        $parent = MediaFolder::query()->where('name', 'งานบุคคล')->firstOrFail();

        // 2. Create Nested Subfolder
        $this->actingAs($user)->post(route('media.folders.store'), [
            'name' => 'รับสมัครงาน 2567',
            'color' => '#34A853',
            'parent_id' => $parent->id,
        ])->assertRedirect();

        $child = MediaFolder::query()->where('name', 'รับสมัครงาน 2567')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);

        // 3. Check hierarchy path helper
        $this->assertSame('งานบุคคล / รับสมัครงาน 2567', $child->fullPath());
        $this->assertCount(1, $child->ancestors());
        $this->assertSame($parent->id, $child->ancestors()[0]['id']);

        // 4. View child folder in index
        $this->actingAs($user)->get(route('media.index', ['folder_id' => $child->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->where('currentFolder.id', $child->id)
                ->where('breadcrumbs.0.name', 'คลังหลัก')
                ->where('breadcrumbs.1.name', 'งานบุคคล')
                ->where('breadcrumbs.2.name', 'รับสมัครงาน 2567'));
    }

    public function test_same_folder_can_have_multiple_categories_and_filter_by_category(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $folder = MediaFolder::query()->create([
            'name' => 'รับสมัครงาน',
            'color' => '#EA4335',
        ]);

        // Upload/Create File 1 in this folder with Category "ประกาศ/รับสมัคร"
        $file1 = PostMedia::query()->create([
            'file_path' => 'media/announcement.pdf',
            'original_name' => 'announcement.pdf',
            'mime_type' => 'application/pdf',
            'folder_id' => $folder->id,
            'category' => 'ประกาศ/รับสมัคร',
        ]);

        // Upload/Create File 2 in the SAME folder with Category "แบนเนอร์/อินโฟกราฟิก"
        $file2 = PostMedia::query()->create([
            'file_path' => 'media/banner.png',
            'original_name' => 'banner.png',
            'mime_type' => 'image/png',
            'folder_id' => $folder->id,
            'category' => 'แบนเนอร์/อินโฟกราฟิก',
        ]);

        // Upload/Create File 3 in the SAME folder with Category "ภาพบุคคล/ผู้บริหาร"
        $file3 = PostMedia::query()->create([
            'file_path' => 'media/director.jpg',
            'original_name' => 'director.jpg',
            'mime_type' => 'image/jpeg',
            'folder_id' => $folder->id,
            'category' => 'ภาพบุคคล/ผู้บริหาร',
        ]);

        // Verify all 3 files are in the same folder with distinct categories
        $this->assertSame($folder->id, $file1->folder_id);
        $this->assertSame($folder->id, $file2->folder_id);
        $this->assertSame($folder->id, $file3->folder_id);
        $this->assertSame(3, $folder->media()->count());

        // Test filtering by category inside this folder: filter "ประกาศ/รับสมัคร"
        $this->actingAs($user)->get(route('media.index', [
            'folder_id' => $folder->id,
            'category' => 'ประกาศ/รับสมัคร',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->has('media.data', 1)
                ->where('media.data.0.original_name', 'announcement.pdf')
                ->where('media.data.0.category', 'ประกาศ/รับสมัคร'));

        // Test filtering "แบนเนอร์/อินโฟกราฟิก"
        $this->actingAs($user)->get(route('media.index', [
            'folder_id' => $folder->id,
            'category' => 'แบนเนอร์/อินโฟกราฟิก',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->has('media.data', 1)
                ->where('media.data.0.original_name', 'banner.png')
                ->where('media.data.0.category', 'แบนเนอร์/อินโฟกราฟิก'));
    }

    public function test_user_can_update_media_category_and_name(): void
    {
        $user = User::factory()->create();

        $media = PostMedia::query()->create([
            'file_path' => 'media/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'category' => null,
        ]);

        $this->actingAs($user)->put(route('media.update', $media->id), [
            'original_name' => 'ภาพกิจกรรมวันเด็ก.jpg',
            'category' => 'ภาพกิจกรรม',
        ])->assertRedirect();

        $fresh = $media->fresh();
        $this->assertSame('ภาพกิจกรรมวันเด็ก.jpg', $fresh->original_name);
        $this->assertSame('ภาพกิจกรรม', $fresh->category);
    }
}
