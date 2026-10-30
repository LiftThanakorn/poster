<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_integrations_page_with_google_drive(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.integrations'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Integrations')
            ->has('googleDrive')
        );
    }

    public function test_user_can_update_google_drive_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.integrations.update'), [
            'wordpress_url' => 'https://example.com',
            'wordpress_username' => 'admin',
            'facebook_page_id' => '123456789',
            'google_drive_client_id' => 'test-client-id.apps.googleusercontent.com',
            'google_drive_client_secret' => 'GOCSPX-secret123',
            'google_drive_root_folder_id' => 'https://drive.google.com/drive/folders/rootFolderId123',
            'google_drive_default_category' => 'พนักงานมหาวิทยาลัย',
            'google_drive_default_stage' => 'รับสมัคร',
            'google_drive_default_comment_template' => "ประกาศ:\n{drive_url}",
        ]);

        $response->assertRedirect(route('settings.integrations'));

        $settings = app(IntegrationSettingsService::class)->googleDrive();
        $credentials = app(IntegrationSettingsService::class)->getCredentials('google_drive');
        $this->assertSame('test-client-id.apps.googleusercontent.com', $settings['client_id']);
        $this->assertSame('GOCSPX-secret123', $credentials['client_secret']);
        $this->assertSame('rootFolderId123', $settings['root_folder_id']);
        $this->assertSame('พนักงานมหาวิทยาลัย', $settings['default_category']);
    }

    public function test_google_drive_redirect_stores_session_state_and_redirects(): void
    {
        $user = User::factory()->create();

        // Configure client id
        app(IntegrationSettingsService::class)->updateGoogleDrive('mock-client-id');

        $response = $this->actingAs($user)->get(route('settings.integrations.google-drive.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
        $this->assertNotNull(session('google_oauth_state'));
    }

    public function test_google_drive_callback_exchanges_code_for_tokens(): void
    {
        $user = User::factory()->create();

        app(IntegrationSettingsService::class)->updateGoogleDrive('mock-client-id', 'mock-client-secret');

        $state = 'test_state_12345';

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.mock_access_token',
                'refresh_token' => '1//mock_refresh_token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'email' => 'officer@university.ac.th',
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['google_oauth_state' => $state])
            ->get(route('settings.integrations.google-drive.callback', [
                'state' => $state,
                'code' => '4/0mock_authorization_code',
            ]));

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('success');

        $credentials = app(IntegrationSettingsService::class)->getCredentials('google_drive');
        $settings = app(IntegrationSettingsService::class)->googleDrive();
        $this->assertSame('1//mock_refresh_token', $credentials['refresh_token']);
        $this->assertSame('ya29.mock_access_token', $credentials['access_token']);
        $this->assertSame('officer@university.ac.th', $settings['connected_email']);
    }

    public function test_user_can_disconnect_google_drive(): void
    {
        $user = User::factory()->create();

        app(IntegrationSettingsService::class)->updateGoogleDrive('mock-client-id', 'mock-client-secret');
        app(IntegrationSettingsService::class)->saveGoogleOAuthTokens('1//existing_token', 'mock_access', 'user@ac.th');

        $response = $this->actingAs($user)->post(route('settings.integrations.google-drive.disconnect'));

        $response->assertRedirect(route('settings.integrations'));

        $credentials = app(IntegrationSettingsService::class)->getCredentials('google_drive');
        $settings = app(IntegrationSettingsService::class)->googleDrive();
        $this->assertEmpty($credentials['refresh_token'] ?? null);
        $this->assertEmpty($settings['connected_email'] ?? null);
    }
}
