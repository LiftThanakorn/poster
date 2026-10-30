<?php

namespace Tests\Feature;

use App\Models\PostMedia;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\IntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_can_view_announcements_index_with_statistics(): void
    {
        $user = User::factory()->create();

        PostMedia::create([
            'file_path' => 'media/test.pdf',
            'original_name' => 'announcement.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'category' => 'เอกสารแนบ',
            'drive_sync_status' => 'synced',
            'drive_employee_category' => 'รายวัน',
            'drive_announcement_stage' => 'รับสมัคร',
            'drive_document_date' => '2026-10-30',
        ]);

        $response = $this->actingAs($user)->get(route('announcements.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Announcements/Index')
            ->has('announcements.data', 1)
            ->where('stats.total', 1)
            ->where('stats.synced', 1)
        );
    }

    public function test_user_can_upload_standalone_announcement_and_sync_to_drive(): void
    {
        $user = User::factory()->create();

        app(IntegrationSettingsService::class)->updateGoogleDrive('mock-client', 'mock-secret', 'mock-folder');
        app(IntegrationSettingsService::class)->saveCredentials('google_drive', ['refresh_token' => '1//token']);

        // Mock GoogleDriveService
        $mockDrive = $this->createMock(GoogleDriveService::class);
        $mockDrive->expects($this->once())
            ->method('syncPdfMedia')
            ->willReturnCallback(function (PostMedia $media) {
                $media->update([
                    'drive_sync_status' => 'synced',
                    'drive_file_id' => 'mock_drive_file_id_789',
                    'drive_web_view_link' => 'https://drive.google.com/file/d/mock_drive_file_id_789/view',
                ]);

                return $media;
            });

        $this->app->instance(GoogleDriveService::class, $mockDrive);

        $pdfFile = UploadedFile::fake()->create('raw_announcement.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->post(route('announcements.store'), [
            'pdf_file' => $pdfFile,
            'employee_category' => 'รายวัน',
            'announcement_stage' => 'รับสมัคร',
            'document_date' => '2026-10-30',
            'position_title' => 'พนักงานขับรถ',
        ]);

        $response->assertRedirect(route('announcements.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('post_media', [
            'drive_sync_status' => 'synced',
            'drive_file_id' => 'mock_drive_file_id_789',
            'drive_employee_category' => 'รายวัน',
            'drive_announcement_stage' => 'รับสมัคร',
            'drive_position_title' => 'พนักงานขับรถ',
        ]);
    }

    public function test_user_can_filter_announcements_by_category_and_stage(): void
    {
        $user = User::factory()->create();

        PostMedia::create([
            'file_path' => 'media/daily.pdf',
            'original_name' => 'daily.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'drive_sync_status' => 'synced',
            'drive_employee_category' => 'รายวัน',
            'drive_announcement_stage' => 'รับสมัคร',
        ]);

        PostMedia::create([
            'file_path' => 'media/uni.pdf',
            'original_name' => 'uni.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'drive_sync_status' => 'synced',
            'drive_employee_category' => 'พนักงานมหาวิทยาลัย',
            'drive_announcement_stage' => 'ผลการคัดเลือก',
        ]);

        $res1 = $this->actingAs($user)->get(route('announcements.index', [
            'category' => 'รายวัน',
            'stage' => 'รับสมัคร',
        ]));
        $res1->assertOk();
        $res1->assertInertia(fn ($page) => $page->has('announcements.data', 1));

        $res2 = $this->actingAs($user)->get(route('announcements.index', [
            'category' => 'พนักงานมหาวิทยาลัย',
        ]));
        $res2->assertOk();
        $res2->assertInertia(fn ($page) => $page->has('announcements.data', 1));
    }
}
