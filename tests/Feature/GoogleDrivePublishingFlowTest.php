<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\Publishers\FacebookPublisher;
use App\Services\Publishers\PublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDrivePublishingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_post_creation_with_pdf_persists_drive_fields(): void
    {
        $user = User::factory()->create();
        $pdfFile = UploadedFile::fake()->create('contract_officer.pdf', 300, 'application/pdf');

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'title' => 'ประกาศรับสมัครเจ้าหน้าที่',
            'publish_action' => 'draft',
            'channels' => ['facebook', 'wordpress'],
            'pdf_file' => $pdfFile,
            'drive_sync_enabled' => true,
            'drive_employee_category' => 'พนักงานประจำตามสัญญา',
            'drive_announcement_stage' => 'รับสมัคร',
            'drive_document_date' => '2026-10-30',
            'drive_position_title' => 'เจ้าหน้าที่วิจัย',
            'fb_caption' => 'เปิดรับสมัครงานตำแหน่งเจ้าหน้าที่วิจัย',
            'fb_comment' => "📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}",
            'action_buttons' => [
                ['label' => 'ดูประกาศบน Drive', 'url' => '{drive_url}'],
            ],
        ]);

        $response->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('posts', [
            'title' => 'ประกาศรับสมัครเจ้าหน้าที่',
            'drive_sync_enabled' => true,
            'drive_employee_category' => 'พนักงานประจำตามสัญญา',
            'drive_announcement_stage' => 'รับสมัคร',
            'drive_position_title' => 'เจ้าหน้าที่วิจัย',
        ]);

        $this->assertDatabaseHas('post_media', [
            'drive_employee_category' => 'พนักงานประจำตามสัญญา',
            'drive_announcement_stage' => 'รับสมัคร',
            'drive_position_title' => 'เจ้าหน้าที่วิจัย',
        ]);
    }

    public function test_publishing_service_syncs_drive_and_replaces_placeholders(): void
    {
        $user = User::factory()->create();

        $pdfMedia = PostMedia::create([
            'file_path' => 'media/sample.pdf',
            'original_name' => 'sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'category' => 'เอกสารแนบ',
            'drive_sync_status' => 'pending',
            'drive_employee_category' => 'รายวัน',
            'drive_announcement_stage' => 'รับสมัคร',
            'drive_document_date' => '2026-10-30',
            'drive_position_title' => 'แม่บ้าน',
        ]);

        $post = Post::create([
            'title' => 'รับสมัครแม่บ้านรายวัน',
            'slug' => 'daily-maid-2026',
            'author_id' => $user->id,
            'pdf_media_id' => $pdfMedia->id,
            'drive_sync_enabled' => true,
            'drive_employee_category' => 'รายวัน',
            'drive_announcement_stage' => 'รับสมัคร',
            'drive_document_date' => '2026-10-30',
            'drive_position_title' => 'แม่บ้าน',
            'fb_caption' => 'เปิดรับสมัครแม่บ้านรายวัน',
            'fb_comment' => "ดาวน์โหลดประกาศ:\n{drive_url}",
            'status' => 'draft',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        // Mock GoogleDriveService
        $mockDrive = $this->createMock(GoogleDriveService::class);
        $mockDrive->expects($this->once())
            ->method('syncPdfMedia')
            ->willReturnCallback(function (PostMedia $media) {
                $media->update([
                    'drive_sync_status' => 'synced',
                    'drive_file_id' => 'mock_drive_file_abc',
                    'drive_web_view_link' => 'https://drive.google.com/file/d/mock_drive_file_abc/view',
                ]);

                return $media;
            });

        $this->app->instance(GoogleDriveService::class, $mockDrive);

        $publishingService = app(PublishingService::class);
        $syncResult = $publishingService->ensureDriveSyncedForPost($post);

        $this->assertTrue($syncResult);

        $pdfMedia->refresh();
        $this->assertSame('synced', $pdfMedia->drive_sync_status);
        $this->assertSame('https://drive.google.com/file/d/mock_drive_file_abc/view', $pdfMedia->drive_web_view_link);

        // Test FacebookPublisher placeholder resolution
        $fbPublisher = app(FacebookPublisher::class);
        $resolvedComment = $fbPublisher->resolveVariables($post->fb_comment, $post);

        $this->assertStringContainsString('https://drive.google.com/file/d/mock_drive_file_abc/view', $resolvedComment);
        $this->assertStringNotContainsString('{drive_url}', $resolvedComment);

        // Test idempotency: calling ensureDriveSyncedForPost again should return true without re-uploading
        $secondSync = $publishingService->ensureDriveSyncedForPost($post);
        $this->assertTrue($secondSync);
    }
}
