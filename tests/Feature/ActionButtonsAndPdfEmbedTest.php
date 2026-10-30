<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\Publishers\FacebookPublisher;
use App\Services\Publishers\WordPressPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActionButtonsAndPdfEmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://wp.example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'secret-password'],
            'status' => 'connected',
        ]);

        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => 'page_test_123', 'version' => 'v19.0'],
            'encrypted_credentials' => ['access_token' => 'EAABsecret-token'],
            'status' => 'connected',
        ]);
    }

    public function test_post_can_store_and_update_with_action_buttons_pdf_media_and_fb_comment_2(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $pdfFile = UploadedFile::fake()->create('application_form.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'title' => 'เปิดรับสมัครงานตำแหน่งนักพัฒนาเว็บ',
            'wp_content' => '<p>รายละเอียดประกาศรับสมัครงาน...</p>',
            'fb_caption' => 'เปิดรับสมัครงานตำแหน่งนักพัฒนาเว็บ ดูลิงก์ด้านล่าง',
            'fb_comment' => 'คอมเมนต์แรก: ลิงก์รายละเอียด {wordpress_url}',
            'fb_comment_2' => 'คอมเมนต์ที่ 2: ดาวน์โหลดใบสมัคร {pdf_url}',
            'channels' => ['wordpress', 'facebook'],
            'publish_action' => 'draft',
            'action_buttons' => [
                ['label' => 'ดาวน์โหลดใบสมัคร (PDF)', 'url' => 'https://example.com/apply.pdf'],
                ['label' => 'กรอกฟอร์มออนไลน์', 'url' => 'https://forms.google.com/test'],
            ],
            'pdf_file' => $pdfFile,
        ]);

        $response->assertRedirect(route('posts.index'));

        $post = Post::query()->where('title', 'เปิดรับสมัครงานตำแหน่งนักพัฒนาเว็บ')->first();
        $this->assertNotNull($post);
        $this->assertSame('คอมเมนต์ที่ 2: ดาวน์โหลดใบสมัคร {pdf_url}', $post->fb_comment_2);
        $this->assertCount(2, $post->action_buttons);
        $this->assertSame('ดาวน์โหลดใบสมัคร (PDF)', $post->action_buttons[0]['label']);
        $this->assertNotNull($post->pdf_media_id);

        $pdfMedia = PostMedia::find($post->pdf_media_id);
        $this->assertNotNull($pdfMedia);
        $this->assertSame('application_form.pdf', $pdfMedia->original_name);
        $this->assertSame('application/pdf', $pdfMedia->mime_type);
        $this->assertSame($post->id, $pdfMedia->post_id);

        // Test update
        $updateResponse = $this->actingAs($user)->post(route('posts.update', $post->id), [
            '_method' => 'put',
            'title' => 'เปิดรับสมัครงานตำแหน่งนักพัฒนาเว็บ (ขยายเวลารับสมัคร)',
            'wp_content' => '<p>รายละเอียดใหม่...</p>',
            'fb_caption' => 'ขยายเวลา...',
            'fb_comment' => 'คอมเมนต์ 1 อัปเดต',
            'fb_comment_2' => 'คอมเมนต์ 2 อัปเดต: {application_links}',
            'channels' => ['wordpress'],
            'publish_action' => 'draft',
            'action_buttons' => [
                ['label' => 'ใบสมัครออนไลน์ปรับปรุงใหม่', 'url' => 'https://forms.google.com/updated'],
            ],
            'pdf_media_id' => $post->pdf_media_id,
        ]);

        $updateResponse->assertRedirect(route('posts.index'));
        $post->refresh();
        $this->assertSame('เปิดรับสมัครงานตำแหน่งนักพัฒนาเว็บ (ขยายเวลารับสมัคร)', $post->title);
        $this->assertSame('คอมเมนต์ 2 อัปเดต: {application_links}', $post->fb_comment_2);
        $this->assertCount(1, $post->action_buttons);
        $this->assertSame('ใบสมัครออนไลน์ปรับปรุงใหม่', $post->action_buttons[0]['label']);

        // Test removing attached PDF by passing empty/null pdf_media_id
        $removePdfResponse = $this->actingAs($user)->post(route('posts.update', $post->id), [
            '_method' => 'put',
            'title' => 'เปิดรับสมัครงานตำแหน่งนักพัฒนาเว็บ (ยกเลิกไฟล์แนบ)',
            'channels' => ['wordpress'],
            'publish_action' => 'draft',
            'pdf_media_id' => '',
        ]);

        $removePdfResponse->assertRedirect(route('posts.index'));
        $post->refresh();
        $this->assertNull($post->pdf_media_id);
    }

    public function test_wordpress_publisher_formats_action_buttons_and_embedded_pdf_viewer(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $pdfMedia = PostMedia::create([
            'file_path' => 'media/test_doc.pdf',
            'original_name' => 'ประกาศรับสมัคร.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
            'is_featured' => false,
        ]);
        Storage::disk('public')->put('media/test_doc.pdf', '%PDF-1.4 mock content');

        $post = Post::create([
            'title' => 'ข่าวประกาศรับสมัคร',
            'slug' => 'news-job-announcement',
            'wp_content' => '<p>เนื้อหาข่าวประชาสัมพันธ์รับสมัครงาน</p>',
            'status' => 'publishing',
            'author_id' => $user->id,
            'action_buttons' => [
                ['label' => 'ดาวน์โหลดใบสมัคร (PDF)', 'url' => 'https://agency.go.th/form.pdf'],
                ['label' => 'ยื่นใบสมัครออนไลน์', 'url' => 'https://agency.go.th/apply'],
            ],
            'pdf_media_id' => $pdfMedia->id,
        ]);

        $target = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 999,
                'source_url' => 'https://wp.example.com/wp-content/uploads/test_doc.pdf',
            ], 200),
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 888,
                'link' => 'https://wp.example.com/p/888',
            ], 200),
        ]);

        $publisher = new WordPressPublisher;
        $result = $publisher->publish($post, $target);

        $this->assertTrue($result['success']);
        $this->assertSame('888', $result['external_id']);

        // Assert that the request content sent to WordPress includes both action buttons and embedded iframe viewer
        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://wp.example.com/wp-json/wp/v2/posts') {
                return false;
            }

            $content = $request['content'];

            return str_contains($content, 'เนื้อหาข่าวประชาสัมพันธ์รับสมัครงาน')
                && str_contains($content, 'poster-action-buttons-wrap')
                && str_contains($content, 'ดาวน์โหลดใบสมัคร (PDF)')
                && str_contains($content, 'ยื่นใบสมัครออนไลน์')
                && str_contains($content, 'poster-pdf-viewer-wrap')
                && str_contains($content, '<iframe src="https://wp.example.com/wp-content/uploads/test_doc.pdf#toolbar=1')
                && str_contains($content, 'ดาวน์โหลดเอกสาร PDF');
        });
    }

    public function test_facebook_publisher_dispatches_comment_2_and_replaces_placeholders(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $pdfMedia = PostMedia::create([
            'file_path' => 'media/form.pdf',
            'original_name' => 'form.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
            'is_featured' => false,
        ]);
        Storage::disk('public')->put('media/form.pdf', '%PDF-1.4 mock content');

        $post = Post::create([
            'title' => 'ประกาศรับสมัครบุคคลเพื่อเลือกสรร',
            'slug' => 'recruit-officer-2026',
            'fb_caption' => 'เปิดรับสมัครเจ้าหน้าที่ใหม่ อ่านรายละเอียดที่ {wordpress_url}',
            'fb_comment' => 'คอมเมนต์ 1: อ่านข่าวเต็มได้ที่ {wordpress_url}',
            'fb_comment_2' => "คอมเมนต์ 2 (เอกสารแนบ):\n{application_links}\nไฟล์ PDF: {pdf_url}",
            'action_buttons' => [
                ['label' => 'ใบสมัคร', 'url' => 'https://example.com/apply-form'],
                ['label' => 'คู่มือสอบ', 'url' => 'https://example.com/handbook'],
            ],
            'pdf_media_id' => $pdfMedia->id,
            'status' => 'publishing',
            'author_id' => $user->id,
        ]);

        $wpTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'wordpress',
            'status' => 'published',
            'external_id' => '101',
            'external_url' => 'https://mygov.go.th/news/101',
        ]);

        $fbTarget = PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/me*' => Http::response([
                'id' => 'page_test_123',
            ], 200),
            'https://graph.facebook.com/v19.0/page_test_123/feed' => Http::response([
                'id' => 'page_test_123_post_999',
            ], 200),
            'https://graph.facebook.com/v19.0/page_test_123_post_999/comments' => Http::response([
                'id' => 'comment_id_123',
            ], 200),
        ]);

        $publisher = new FacebookPublisher;
        $result = $publisher->publish($post, $fbTarget);

        $this->assertTrue($result['success']);
        $this->assertSame('page_test_123_post_999', $result['external_id']);

        // Assert main feed post had {wordpress_url} replaced
        Http::assertSent(function (Request $request) {
            if ($request->url() === 'https://graph.facebook.com/v19.0/page_test_123/feed') {
                return str_contains($request['message'], 'https://mygov.go.th/news/101');
            }

            return false;
        });

        // Assert comment 1 was sent with resolved {wordpress_url}
        Http::assertSent(function (Request $request) {
            if ($request->url() === 'https://graph.facebook.com/v19.0/page_test_123_post_999/comments') {
                return str_contains($request['message'], 'คอมเมนต์ 1: อ่านข่าวเต็มได้ที่ https://mygov.go.th/news/101');
            }

            return false;
        });

        // Assert comment 2 was sent with resolved {application_links} and {pdf_url}
        Http::assertSent(function (Request $request) {
            if ($request->url() === 'https://graph.facebook.com/v19.0/page_test_123_post_999/comments') {
                $msg = $request['message'];

                return str_contains($msg, 'คอมเมนต์ 2 (เอกสารแนบ):')
                    && str_contains($msg, '1. ใบสมัคร: https://example.com/apply-form')
                    && str_contains($msg, '2. คู่มือสอบ: https://example.com/handbook')
                    && str_contains($msg, 'form.pdf');
            }

            return false;
        });
    }

    public function test_post_autosave_preserves_action_buttons_and_fb_comment_2(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('posts.autosave'), [
            'title' => 'แบบร่างข่าวประชาสัมพันธ์',
            'wp_content' => '<p>เนื้อหาร่าง</p>',
            'fb_caption' => 'แคปชั่นร่าง',
            'fb_comment' => 'คอมเมนต์ 1',
            'fb_comment_2' => 'คอมเมนต์ 2 พร้อม {application_links}',
            'channels' => ['wordpress', 'facebook'],
            'action_buttons' => [
                ['label' => 'ใบสมัครร่าง', 'url' => 'https://example.com/form'],
            ],
        ]);

        $response->assertOk();
        $postId = $response->json('post_id');
        $this->assertNotNull($postId);

        $post = Post::find($postId);
        $this->assertNotNull($post);
        $this->assertSame('คอมเมนต์ 2 พร้อม {application_links}', $post->fb_comment_2);
        $this->assertCount(1, $post->action_buttons);
        $this->assertSame('ใบสมัครร่าง', $post->action_buttons[0]['label']);

        // Update autosave
        $updateResponse = $this->actingAs($user)->putJson(route('posts.autosave.update', $post->id), [
            'title' => 'แบบร่างข่าวประชาสัมพันธ์ (ปรับปรุง)',
            'fb_comment_2' => 'คอมเมนต์ 2 อัปเดตใหม่',
            'action_buttons' => [
                ['label' => 'ปุ่ม 1', 'url' => 'https://example.com/1'],
                ['label' => 'ปุ่ม 2', 'url' => 'https://example.com/2'],
            ],
        ]);

        $updateResponse->assertOk();
        $post->refresh();
        $this->assertSame('คอมเมนต์ 2 อัปเดตใหม่', $post->fb_comment_2);
        $this->assertCount(2, $post->action_buttons);
    }
}
