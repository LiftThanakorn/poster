<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveService;
use App\Services\IntegrationSettingsService;
use App\Services\Publishers\FacebookPublisher;
use App\Services\Publishers\WordPressPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationSettingsService $settings,
    ) {}

    public function index(): Response
    {
        $wordpress = $this->settings->wordpress();
        $facebook = $this->settings->facebook();
        $googleDrive = $this->settings->googleDrive();

        $wordpressConfigured = $wordpress['url'] !== ''
            && $wordpress['username'] !== ''
            && $wordpress['password'] !== '';
        $facebookConfigured = $facebook['page_id'] !== ''
            && $facebook['access_token'] !== '';
        $googleDriveConfigured = $googleDrive['configured'];

        $wordpressHealth = $wordpressConfigured
            ? Cache::remember('integrations.wordpress.health', now()->addMinutes(5), function (): bool {
                $connected = app(WordPressPublisher::class)->healthCheck();
                $this->settings->markHealth('wordpress', $connected, $connected ? null : 'ไม่สามารถเชื่อมต่อ WordPress ได้');

                return $connected;
            })
            : false;

        $facebookHealth = $facebookConfigured
            ? Cache::remember('integrations.facebook.health', now()->addMinutes(5), function (): bool {
                $connected = app(FacebookPublisher::class)->healthCheck();
                $this->settings->markHealth('facebook', $connected, $connected ? null : 'ไม่สามารถเชื่อมต่อ Facebook Page ได้');

                return $connected;
            })
            : false;

        $googleDriveHealth = $googleDriveConfigured
            ? Cache::remember('integrations.google_drive.health', now()->addMinutes(5), function (): bool {
                $check = $this->settings->checkGoogleDriveHealth();

                return ($check['status'] ?? 'unhealthy') === 'healthy';
            })
            : false;

        $wordpress = $this->settings->wordpress();
        $facebook = $this->settings->facebook();
        $googleDrive = $this->settings->googleDrive();

        return Inertia::render('Settings/Integrations', [
            'wordpress' => [
                'url' => $wordpress['url'],
                'username' => $wordpress['username'],
                'password' => '',
                'password_configured' => $wordpress['password'] !== '',
                'status' => $wordpressConfigured ? ($wordpressHealth ? 'connected' : 'error') : 'disconnected',
                'configured' => $wordpressConfigured,
                'last_checked_at' => $wordpress['last_checked_at']?->toIso8601String(),
            ],
            'facebook' => [
                'page_id' => $facebook['page_id'],
                'access_token' => '',
                'token_configured' => $facebook['access_token'] !== '',
                'status' => $facebookConfigured ? ($facebookHealth ? 'connected' : 'error') : 'disconnected',
                'configured' => $facebookConfigured,
                'graph_version' => $facebook['version'],
                'last_checked_at' => $facebook['last_checked_at']?->toIso8601String(),
            ],
            'googleDrive' => [
                'client_id' => $googleDrive['client_id'],
                'client_secret' => '',
                'client_secret_configured' => $googleDrive['client_secret_configured'],
                'has_refresh_token' => $googleDrive['has_refresh_token'],
                'root_folder_id' => $googleDrive['root_folder_id'],
                'root_folder_name' => $googleDrive['root_folder_name'],
                'connected_email' => $googleDrive['connected_email'],
                'employee_categories' => $googleDrive['employee_categories'],
                'announcement_stages' => $googleDrive['announcement_stages'],
                'default_fb_comment_template' => $googleDrive['default_fb_comment_template'],
                'status' => $googleDriveConfigured ? ($googleDriveHealth ? 'connected' : 'error') : 'disconnected',
                'configured' => $googleDriveConfigured,
                'last_checked_at' => $googleDrive['last_checked_at']?->toIso8601String(),
                'last_error' => $googleDrive['last_error'],
                'redirect_uri' => route('settings.integrations.google-drive.callback'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'wordpress_url' => 'nullable|url|max:2048',
            'wordpress_username' => 'nullable|string|max:255',
            'wordpress_password' => 'nullable|string|max:1024',
            'facebook_page_id' => 'nullable|string|max:255',
            'facebook_access_token' => 'nullable|string|max:4096',
            'google_drive_client_id' => 'nullable|string|max:512',
            'google_drive_client_secret' => 'nullable|string|max:512',
            'google_drive_root_folder_id' => 'nullable|string|max:512',
            'google_drive_default_category' => 'nullable|string|max:100',
            'google_drive_default_stage' => 'nullable|string|max:100',
            'google_drive_default_comment_template' => 'nullable|string|max:1024',
            'google_drive_employee_categories' => 'nullable|array',
            'google_drive_announcement_stages' => 'nullable|array',
        ]);

        $this->settings->updateWordPress(
            (string) ($validated['wordpress_url'] ?? ''),
            (string) ($validated['wordpress_username'] ?? ''),
            $validated['wordpress_password'] ?? null,
        );

        $this->settings->updateFacebook(
            (string) ($validated['facebook_page_id'] ?? ''),
            $validated['facebook_access_token'] ?? null,
        );

        if ($request->has('google_drive_client_id') || $request->has('google_drive_root_folder_id')) {
            $this->settings->updateGoogleDrive(
                clientId: (string) ($validated['google_drive_client_id'] ?? ''),
                clientSecret: $validated['google_drive_client_secret'] ?? null,
                rootFolderId: $validated['google_drive_root_folder_id'] ?? null,
                defaultCommentTemplate: $validated['google_drive_default_comment_template'] ?? null,
                employeeCategories: $validated['google_drive_employee_categories'] ?? null,
                announcementStages: $validated['google_drive_announcement_stages'] ?? null,
                defaultCategory: $validated['google_drive_default_category'] ?? null,
                defaultStage: $validated['google_drive_default_stage'] ?? null,
            );
        }

        $this->forgetIntegrationCaches();

        return to_route('settings.integrations')
            ->with('success', 'บันทึกการตั้งค่าการเชื่อมต่อเรียบร้อยแล้ว');
    }

    public function check(string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, ['wordpress', 'facebook', 'google_drive'], true), 404);

        Cache::forget("integrations.{$channel}.health");
        Cache::forget("integrations.{$channel}.health_check");

        if ($channel === 'google_drive') {
            $checkResult = $this->settings->checkGoogleDriveHealth(true);
            $connected = ($checkResult['status'] ?? 'unhealthy') === 'healthy';
            $label = 'Google Drive';
            $errorMsg = $checkResult['error'] ?? 'ไม่สามารถเชื่อมต่อ Google Drive ได้';
        } else {
            $connected = match ($channel) {
                'wordpress' => app(WordPressPublisher::class)->healthCheck(),
                'facebook' => app(FacebookPublisher::class)->healthCheck(),
            };
            $label = $channel === 'wordpress' ? 'WordPress' : 'Facebook Page';
            $errorMsg = "ไม่สามารถเชื่อมต่อ {$label} ได้";
            $this->settings->markHealth($channel, $connected, $connected ? null : $errorMsg);
        }

        Cache::put("integrations.{$channel}.health", $connected, now()->addMinutes(5));

        return to_route('settings.integrations')->with(
            $connected ? 'success' : 'error',
            $connected
                ? "เชื่อมต่อ {$label} สำเร็จ"
                : "ตรวจสอบ {$label} ไม่สำเร็จ: {$errorMsg}",
        );
    }

    public function googleDriveRedirect(Request $request): RedirectResponse
    {
        $settings = $this->settingsService->getSettings('google_drive');
        $credentials = $this->settingsService->getCredentials('google_drive');

        if (empty($settings['client_id']) || empty($credentials['client_secret'])) {
            return to_route('settings.integrations')->with('error', 'กรุณากรอก Google Client ID และ Client Secret แล้วกด "บันทึกการตั้งค่า" ก่อนกดเชื่อมต่อ Google Account');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $redirectUri = route('settings.integrations.google-drive.callback');
        $authUrl = app(GoogleDriveService::class)->buildAuthUrl($redirectUri, $state);

        return redirect()->away($authUrl);
    }

    public function googleDriveCallback(Request $request): RedirectResponse
    {
        $sessionState = $request->session()->pull('google_oauth_state');
        $inputState = $request->query('state');
        $code = $request->query('code');
        $error = $request->query('error');

        if ($error) {
            return to_route('settings.integrations')->with('error', "Google OAuth ถูกปฏิเสธ: {$error}");
        }

        if (! $sessionState || $sessionState !== $inputState || ! $code) {
            return to_route('settings.integrations')->with('error', 'การยืนยันตัวตน Google ไม่ถูกต้องหรือหมดอายุ (Invalid OAuth State)');
        }

        $redirectUri = route('settings.integrations.google-drive.callback');
        $result = app(GoogleDriveService::class)->exchangeCode((string) $code, $redirectUri);

        if (! $result['success']) {
            return to_route('settings.integrations')->with('error', $result['error'] ?? 'เชื่อมต่อ Google ไม่สำเร็จ');
        }

        $this->forgetIntegrationCaches();

        $emailText = $result['email'] ? " ({$result['email']})" : '';

        return to_route('settings.integrations')->with('success', "เชื่อมต่อบัญชี Google Drive สำเร็จ{$emailText}");
    }

    public function googleDriveDisconnect(): RedirectResponse
    {
        $this->settings->disconnectGoogleDrive();
        $this->forgetIntegrationCaches();

        return to_route('settings.integrations')->with('success', 'ยกเลิกการเชื่อมต่อ Google Drive เรียบร้อยแล้ว');
    }

    public function googleDriveInspectFolders(Request $request): JsonResponse
    {
        $createMissing = $request->boolean('create_missing', false);
        $treeInfo = app(GoogleDriveService::class)->inspectFolderTree($createMissing);

        return response()->json($treeInfo);
    }

    private function forgetIntegrationCaches(): void
    {
        Cache::forget('integrations.wordpress.health');
        Cache::forget('integrations.facebook.health');
        Cache::forget('integrations.google_drive.health');
        Cache::forget('integrations.google_drive.health_check');
        Cache::forget('wordpress.categories');
    }
}
