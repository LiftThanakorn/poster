<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TemplateActionButtonsAndPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_index_renders_action_buttons_and_pdf_media(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $pdfMedia = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/application.pdf',
            'original_name' => 'application.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
            'category' => 'เอกสารแนบเทมเพลต',
        ]);

        $template = Template::query()->create([
            'name' => 'เทมเพลตรับสมัครงาน',
            'wp_template' => '<p>รายละเอียดงาน {{title}}</p>',
            'fb_template' => 'เปิดรับสมัคร: {{title}}',
            'fb_comment_2' => 'ดาวน์โหลดใบสมัครได้ที่ {pdf_url}',
            'action_buttons' => [
                ['label' => 'ดาวน์โหลดใบสมัคร (PDF)', 'url' => '{pdf_url}'],
                ['label' => 'กรอกฟอร์มออนไลน์', 'url' => 'https://forms.google.com/test'],
            ],
            'pdf_media_id' => $pdfMedia->id,
        ]);

        $response = $this->actingAs($user)->get(route('templates.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Templates/Index')
                ->has('templates', 1)
                ->where('templates.0.id', $template->id)
                ->where('templates.0.name', 'เทมเพลตรับสมัครงาน')
                ->where('templates.0.pdf_media_id', $pdfMedia->id)
                ->where('templates.0.fb_comment_2', 'ดาวน์โหลดใบสมัครได้ที่ {pdf_url}')
                ->has('templates.0.action_buttons', 2)
            );
    }

    public function test_can_create_template_with_action_buttons_pdf_media_and_fb_comment_2(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $pdfMedia = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/doc.pdf',
            'original_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20000,
        ]);

        $response = $this->actingAs($user)->post(route('templates.store'), [
            'name' => 'เทมเพลตฝึกอบรม',
            'wp_template' => '<p>เนื้อหาหลัก</p>',
            'fb_template' => 'ข้อความ Facebook',
            'fb_comment_2' => 'สนใจลงทะเบียน คลิก {wordpress_url}',
            'pdf_media_id' => $pdfMedia->id,
            'action_buttons' => [
                ['label' => 'ดาวน์โหลดเอกสารอบรม', 'url' => '{pdf_url}'],
                ['label' => 'ลงทะเบียน', 'url' => 'https://example.com/register'],
            ],
        ]);

        $response->assertRedirect(route('templates.index'));

        $this->assertDatabaseHas('templates', [
            'name' => 'เทมเพลตฝึกอบรม',
            'pdf_media_id' => $pdfMedia->id,
            'fb_comment_2' => 'สนใจลงทะเบียน คลิก {wordpress_url}',
        ]);

        $created = Template::query()->where('name', 'เทมเพลตฝึกอบรม')->firstOrFail();
        $this->assertCount(2, $created->action_buttons);
        $this->assertSame('ดาวน์โหลดเอกสารอบรม', $created->action_buttons[0]['label']);
    }

    public function test_can_create_template_with_uploaded_pdf_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $pdfFile = UploadedFile::fake()->create('sample-application.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->post(route('templates.store'), [
            'name' => 'เทมเพลตพร้อมไฟล์แนบ',
            'wp_template' => '<p>รายละเอียด</p>',
            'fb_template' => 'ข้อความ',
            'pdf_file' => $pdfFile,
            'action_buttons' => [
                ['label' => 'ดาวน์โหลดใบสมัคร', 'url' => '{pdf_url}'],
            ],
        ]);

        $response->assertRedirect(route('templates.index'));

        $this->assertDatabaseHas('post_media', [
            'original_name' => 'sample-application.pdf',
            'mime_type' => 'application/pdf',
            'category' => 'เอกสารแนบเทมเพลต',
        ]);

        $createdMedia = PostMedia::query()->where('original_name', 'sample-application.pdf')->firstOrFail();
        $this->assertDatabaseHas('templates', [
            'name' => 'เทมเพลตพร้อมไฟล์แนบ',
            'pdf_media_id' => $createdMedia->id,
        ]);
    }

    public function test_can_update_template_with_new_action_buttons_and_uploaded_pdf(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $template = Template::query()->create([
            'name' => 'เทมเพลตเดิม',
            'wp_template' => '<p>เนื้อหาเดิม</p>',
            'fb_template' => 'แคปชั่นเดิม',
            'fb_comment_2' => null,
            'action_buttons' => null,
            'pdf_media_id' => null,
        ]);

        $newPdf = UploadedFile::fake()->create('updated-doc.pdf', 300, 'application/pdf');

        $response = $this->actingAs($user)->put(route('templates.update', $template), [
            'name' => 'เทมเพลตอัปเดตแล้ว',
            'wp_template' => '<p>เนื้อหาใหม่</p>',
            'fb_template' => 'แคปชั่นใหม่',
            'fb_comment_2' => 'คอมเมนต์ที่สองใหม่',
            'pdf_file' => $newPdf,
            'action_buttons' => [
                ['label' => 'สมัครงานใหม่', 'url' => 'https://jobs.example.com'],
            ],
        ]);

        $response->assertRedirect(route('templates.index'));

        $template->refresh();
        $this->assertSame('เทมเพลตอัปเดตแล้ว', $template->name);
        $this->assertSame('คอมเมนต์ที่สองใหม่', $template->fb_comment_2);
        $this->assertNotNull($template->pdf_media_id);
        $this->assertSame('updated-doc.pdf', $template->pdfMedia->original_name);
        $this->assertCount(1, $template->action_buttons);
        $this->assertSame('สมัครงานใหม่', $template->action_buttons[0]['label']);
    }

    public function test_empty_action_buttons_are_cleaned_or_nulled(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('templates.store'), [
            'name' => 'เทมเพลตปุ่มว่าง',
            'action_buttons' => [
                ['label' => '', 'url' => ''],
                ['label' => 'ปุ่มที่มีข้อมูล', 'url' => 'https://test.com'],
                ['label' => '   ', 'url' => ''],
            ],
        ]);

        $response->assertRedirect(route('templates.index'));

        $template = Template::query()->where('name', 'เทมเพลตปุ่มว่าง')->firstOrFail();
        $this->assertCount(1, $template->action_buttons);
        $this->assertSame('ปุ่มที่มีข้อมูล', $template->action_buttons[0]['label']);
    }

    public function test_validation_rejects_non_pdf_file_as_pdf(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $badFile = UploadedFile::fake()->create('document.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->actingAs($user)->post(route('templates.store'), [
            'name' => 'เทมเพลตผิดประเภทไฟล์',
            'pdf_file' => $badFile,
        ]);

        $response->assertSessionHasErrors('pdf_file');
    }

    public function test_posts_create_and_edit_receive_template_action_buttons_and_pdf(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $pdfMedia = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/form.pdf',
            'original_name' => 'form.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 15000,
        ]);

        $template = Template::query()->create([
            'name' => 'เทมเพลตใบสมัครพร้อมปุ่ม',
            'wp_template' => '<p>ยินดีต้อนรับ</p>',
            'fb_template' => 'ยินดีต้อนรับ FB',
            'fb_comment_2' => 'เอกสาร: {pdf_url}',
            'action_buttons' => [
                ['label' => 'ดาวน์โหลด', 'url' => '{pdf_url}'],
            ],
            'pdf_media_id' => $pdfMedia->id,
        ]);

        $responseCreate = $this->actingAs($user)->get(route('posts.create'));

        $responseCreate->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Posts/Create')
                ->has('templates', 1)
                ->where('templates.0.id', $template->id)
                ->where('templates.0.pdf_media_id', $pdfMedia->id)
                ->where('templates.0.fb_comment_2', 'เอกสาร: {pdf_url}')
                ->has('templates.0.action_buttons', 1)
            );

        $post = Post::query()->create([
            'title' => 'โพสต์ทดสอบ',
            'slug' => 'test-post',
            'wp_content' => 'เนื้อหา',
            'status' => 'draft',
            'author_id' => $user->id,
        ]);

        $responseEdit = $this->actingAs($user)->get(route('posts.edit', $post));

        $responseEdit->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Posts/Edit')
                ->has('templates', 1)
                ->where('templates.0.id', $template->id)
                ->where('templates.0.pdf_media_id', $pdfMedia->id)
                ->where('templates.0.fb_comment_2', 'เอกสาร: {pdf_url}')
                ->has('templates.0.action_buttons', 1)
            );
    }
}
