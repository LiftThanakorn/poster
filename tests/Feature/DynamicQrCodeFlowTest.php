<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DynamicQrCodeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_dynamic_qr_code(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('qr-codes.store'), [
            'title' => 'แบบฟอร์มลงทะเบียน',
            'code' => 'reg-2026',
            'target_url' => 'https://example.com/register',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('qr_codes', [
            'code' => 'reg-2026',
            'target_url' => 'https://example.com/register',
            'user_id' => $user->id,
            'is_active' => 1,
        ]);
    }

    public function test_public_scanner_redirects_302_and_records_telemetry(): void
    {
        $user = User::factory()->create();
        $qr = QrCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'jump-fast',
            'target_url' => 'https://google.com/destination',
            'is_active' => true,
            'scan_count' => 0,
        ]);

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Line/14.0.0',
            'Referer' => 'https://facebook.com/poster',
        ])->get(route('qr.redirect', ['code' => 'jump-fast']));

        $response->assertStatus(302);
        $response->assertRedirect('https://google.com/destination');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        $qr->refresh();
        $this->assertSame(1, $qr->scan_count);

        $this->assertDatabaseHas('qr_code_scans', [
            'qr_code_id' => $qr->id,
            'device_type' => 'mobile',
            'platform' => 'iOS',
            'browser' => 'LINE In-App',
        ]);
    }

    public function test_updating_target_url_immediately_invalidates_cache_and_redirects_to_new_url(): void
    {
        $user = User::factory()->create();
        $qr = QrCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'dynamic-test',
            'target_url' => 'https://old-target.com',
            'is_active' => true,
        ]);

        // First scan seeds cache
        $this->get(route('qr.redirect', ['code' => 'dynamic-test']))
            ->assertRedirect('https://old-target.com');

        // Update target URL
        $this->actingAs($user)->putJson(route('qr-codes.update', $qr), [
            'title' => 'Updated title',
            'code' => 'dynamic-test',
            'target_url' => 'https://new-target.com/fresh',
            'is_active' => true,
        ])->assertOk();

        // Next scan must immediately redirect to new target
        $this->get(route('qr.redirect', ['code' => 'dynamic-test']))
            ->assertRedirect('https://new-target.com/fresh');
    }

    public function test_inactive_or_expired_qr_code_renders_inactive_page(): void
    {
        $user = User::factory()->create();
        $inactiveQr = QrCode::factory()->inactive()->create([
            'user_id' => $user->id,
            'code' => 'off-now',
        ]);

        $response = $this->get(route('qr.redirect', ['code' => 'off-now']));
        $response->assertStatus(410);
        $response->assertSee('ลิงก์ QR Code นี้ไม่พร้อมใช้งาน');
    }

    public function test_image_endpoint_renders_svg_and_png(): void
    {
        $user = User::factory()->create();
        $qr = QrCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'img-render',
        ]);

        $svgResponse = $this->get(route('qr.image', ['code' => 'img-render', 'format' => 'svg']));
        $svgResponse->assertOk();
        $svgResponse->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svgResponse->getContent());

        $pngResponse = $this->get(route('qr.image', ['code' => 'img-render', 'format' => 'png']));
        $pngResponse->assertOk();
        $pngResponse->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $pngResponse->getContent());
    }

    public function test_cannot_delete_qr_code_when_used_in_post_or_template(): void
    {
        $user = User::factory()->create();
        $qr = QrCode::factory()->create(['user_id' => $user->id]);

        $post = Post::query()->create([
            'title' => 'Post with QR',
            'slug' => 'post-with-qr',
            'status' => 'draft',
            'author_id' => $user->id,
            'image_editor_data' => [
                'canvasWidth' => 1080,
                'canvasHeight' => 1080,
                'elements' => [
                    [
                        'id' => 'qr-1',
                        'type' => 'qr',
                        'qrCodeId' => $qr->id,
                        'code' => $qr->code,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->deleteJson(route('qr-codes.destroy', $qr));
        $response->assertStatus(422);
        $this->assertDatabaseHas('qr_codes', ['id' => $qr->id]);

        // Clean post editor data
        $post->update(['image_editor_data' => null]);

        $response = $this->actingAs($user)->deleteJson(route('qr-codes.destroy', $qr));
        $response->assertOk();
        $this->assertDatabaseMissing('qr_codes', ['id' => $qr->id]);
    }

    public function test_can_create_and_render_qr_with_logo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $image = UploadedFile::fake()->image('logo.png', 100, 100);
        $path = $image->store('media', 'public');

        $media = PostMedia::query()->create([
            'file_path' => $path,
            'original_name' => 'logo.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
        ]);

        $response = $this->actingAs($user)->postJson(route('qr-codes.store'), [
            'title' => 'QR with Logo',
            'code' => 'branded-qr',
            'target_url' => 'https://example.com/branded',
            'is_active' => true,
            'styling' => [
                'logoMediaId' => $media->id,
                'logoUrl' => $media->url,
                'errorCorrection' => 'H',
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('qr_codes', [
            'code' => 'branded-qr',
        ]);

        $qr = QrCode::where('code', 'branded-qr')->first();
        $this->assertSame($media->id, $qr->styling['logoMediaId']);

        // Test SVG rendering with logo
        $svgResponse = $this->get(route('qr.image', ['code' => 'branded-qr', 'format' => 'svg']));
        $svgResponse->assertOk();
        $this->assertStringContainsString('<svg', $svgResponse->getContent());

        // Test PNG rendering with logo
        $pngResponse = $this->get(route('qr.image', ['code' => 'branded-qr', 'format' => 'png']));
        $pngResponse->assertOk();
        $this->assertStringStartsWith("\x89PNG", $pngResponse->getContent());
    }
}
