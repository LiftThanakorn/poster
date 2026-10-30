<?php

namespace Tests\Feature;

use App\Models\PostMedia;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TemplateImageEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_upload_can_return_json_for_image_editor(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('media.store'),
            [
                'file' => UploadedFile::fake()->image('layer.png', 800, 800),
                'is_featured' => true,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('media.mime_type', 'image/png');

        $this->assertDatabaseCount('post_media', 1);
    }

    public function test_template_saves_featured_image_and_editable_canvas_data(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $media = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/template.png',
            'original_name' => 'template.png',
            'mime_type' => 'image/png',
            'file_size' => 1234,
            'is_featured' => true,
        ]);

        $editorData = [
            'version' => 1,
            'canvasWidth' => 1080,
            'canvasHeight' => 1080,
            'backgroundColor' => '#ffffff',
            'elements' => [
                [
                    'id' => 'text-1',
                    'type' => 'text',
                    'text' => 'ประกาศรับสมัครงาน',
                    'x' => 100,
                    'y' => 120,
                    'fontSize' => 64,
                ],
            ],
        ];

        $response = $this->actingAs($user)->post(route('templates.store'), [
            'name' => 'Recruitment Template',
            'wp_template' => '<p>{{title}}</p>',
            'fb_template' => '{{title}} {wordpress_url}',
            'featured_image_id' => $media->id,
            'image_editor_data' => $editorData,
        ]);

        $response->assertRedirect(route('templates.index'));

        $template = Template::query()->firstOrFail();
        $this->assertSame($media->id, $template->featured_image_id);
        $this->assertSame(1080, $template->image_editor_data['canvasWidth']);
        $this->assertSame('ประกาศรับสมัครงาน', $template->image_editor_data['elements'][0]['text']);
    }

    public function test_template_rejects_pdf_as_featured_image(): void
    {
        $user = User::factory()->create();
        $media = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/document.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'is_featured' => false,
        ]);

        $response = $this->actingAs($user)
            ->from(route('templates.create'))
            ->post(route('templates.store'), [
                'name' => 'Invalid Image Template',
                'featured_image_id' => $media->id,
            ]);

        $response->assertRedirect(route('templates.create'))
            ->assertSessionHasErrors('featured_image_id');

        $this->assertDatabaseCount('templates', 0);
    }

    public function test_posts_create_receives_template_featured_image_even_when_media_is_old(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $templateMedia = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/template-old.png',
            'original_name' => 'template-old.png',
            'mime_type' => 'image/png',
            'file_size' => 1000,
            'is_featured' => true,
        ]);

        Template::query()->create([
            'name' => 'Template With Image',
            'featured_image_id' => $templateMedia->id,
        ]);

        foreach (range(1, 105) as $index) {
            PostMedia::query()->create([
                'post_id' => null,
                'file_path' => "media/new-{$index}.png",
                'original_name' => "new-{$index}.png",
                'mime_type' => 'image/png',
                'file_size' => 1000,
                'is_featured' => false,
            ]);
        }

        $this->actingAs($user)
            ->get(route('posts.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Posts/Create')
                ->where('templates.0.featured_image_id', $templateMedia->id)
                ->where('mediaList', fn ($items) => collect($items)
                    ->contains(fn ($item) => (int) $item['id'] === $templateMedia->id)));
    }

    public function test_media_used_as_editor_layer_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $media = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/layer.png',
            'original_name' => 'layer.png',
            'mime_type' => 'image/png',
            'file_size' => 1200,
            'is_featured' => false,
        ]);

        Template::query()->create([
            'name' => 'Editable Design',
            'image_editor_data' => [
                'canvasWidth' => 1080,
                'canvasHeight' => 1080,
                'elements' => [[
                    'id' => 'image-1',
                    'type' => 'image',
                    'mediaId' => $media->id,
                    'src' => '/storage/media/layer.png',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('media.destroy', $media->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('post_media', ['id' => $media->id]);
    }

    public function test_media_in_use_by_template_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $media = PostMedia::query()->create([
            'post_id' => null,
            'file_path' => 'media/used.png',
            'original_name' => 'used.png',
            'mime_type' => 'image/png',
            'file_size' => 1234,
            'is_featured' => true,
        ]);

        Template::query()->create([
            'name' => 'Uses Image',
            'featured_image_id' => $media->id,
        ]);

        $this->actingAs($user)
            ->delete(route('media.destroy', $media->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('post_media', ['id' => $media->id]);
    }
}
