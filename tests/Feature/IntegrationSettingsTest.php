<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_save_integration_settings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.integrations.update'), [
            'wordpress_url' => 'https://example.com',
            'wordpress_username' => 'editor',
            'wordpress_password' => 'wp-secret-password',
            'facebook_page_id' => '123456789',
            'facebook_access_token' => 'facebook-secret-token',
        ]);

        $response
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'บันทึกการตั้งค่าการเชื่อมต่อเรียบร้อยแล้ว');

        $wordpress = IntegrationSetting::query()->where('channel', 'wordpress')->firstOrFail();
        $facebook = IntegrationSetting::query()->where('channel', 'facebook')->firstOrFail();

        $this->assertSame('https://example.com', $wordpress->settings['url']);
        $this->assertSame('editor', $wordpress->settings['username']);
        $this->assertSame('wp-secret-password', $wordpress->encrypted_credentials['password']);
        $this->assertSame('123456789', $facebook->settings['page_id']);
        $this->assertSame('facebook-secret-token', $facebook->encrypted_credentials['access_token']);

        $rawWordPress = DB::table('integration_settings')->where('channel', 'wordpress')->value('encrypted_credentials');
        $rawFacebook = DB::table('integration_settings')->where('channel', 'facebook')->value('encrypted_credentials');

        $this->assertStringNotContainsString('wp-secret-password', $rawWordPress);
        $this->assertStringNotContainsString('facebook-secret-token', $rawFacebook);
    }

    public function test_blank_secret_fields_keep_existing_credentials(): void
    {
        $user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://old.example.com', 'username' => 'old-user'],
            'encrypted_credentials' => ['password' => 'existing-password'],
        ]);

        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '111', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'existing-token'],
        ]);

        $this->actingAs($user)->post(route('settings.integrations.update'), [
            'wordpress_url' => 'https://new.example.com',
            'wordpress_username' => 'new-user',
            'wordpress_password' => '',
            'facebook_page_id' => '222',
            'facebook_access_token' => '',
        ])->assertRedirect(route('settings.integrations'));

        $this->assertSame(
            'existing-password',
            IntegrationSetting::query()->where('channel', 'wordpress')->firstOrFail()->encrypted_credentials['password'],
        );
        $this->assertSame(
            'existing-token',
            IntegrationSetting::query()->where('channel', 'facebook')->firstOrFail()->encrypted_credentials['access_token'],
        );
    }

    public function test_wordpress_connection_can_be_checked_manually(): void
    {
        $user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'app-password'],
        ]);

        Http::fake([
            'https://example.com/wp-json/wp/v2/users/me' => Http::response(['id' => 1], 200),
        ]);

        $this->actingAs($user)
            ->post(route('settings.integrations.check', 'wordpress'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'เชื่อมต่อ WordPress สำเร็จ');

        $this->assertSame(
            'connected',
            IntegrationSetting::query()->where('channel', 'wordpress')->firstOrFail()->status,
        );
    }

    public function test_facebook_connection_can_be_checked_manually(): void
    {
        $user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '123', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'page-token'],
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => '123', 'name' => 'Test Page'], 200),
        ]);

        $this->actingAs($user)
            ->post(route('settings.integrations.check', 'facebook'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'เชื่อมต่อ Facebook Page สำเร็จ');

        $this->assertSame(
            'connected',
            IntegrationSetting::query()->where('channel', 'facebook')->firstOrFail()->status,
        );
    }

    public function test_integrations_page_never_exposes_saved_secrets(): void
    {
        $user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'wordpress',
            'settings' => ['url' => 'https://example.com', 'username' => 'editor'],
            'encrypted_credentials' => ['password' => 'wp-secret'],
        ]);
        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '123', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'fb-secret'],
        ]);

        Http::fake([
            'https://example.com/wp-json/wp/v2/users/me' => Http::response(['id' => 1], 200),
            'https://graph.facebook.com/*' => Http::response(['id' => '123', 'name' => 'Test Page'], 200),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Integrations')
                ->where('wordpress.password', '')
                ->where('wordpress.password_configured', true)
                ->where('wordpress.status', 'connected')
                ->where('facebook.access_token', '')
                ->where('facebook.token_configured', true)
                ->where('facebook.status', 'connected'));
    }
}
