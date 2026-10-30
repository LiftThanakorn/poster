<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Support\SecretSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IntegrationSettingsService
{
    /**
     * Cache TTL in minutes for integration health checks.
     */
    public const HEALTH_CACHE_TTL_MINUTES = 5;

    /**
     * Canonicalize provider name to channel name.
     */
    protected function canonicalChannel(string $provider): string
    {
        $normalized = strtolower(trim($provider));

        return match ($normalized) {
            'wp', 'wordpress' => 'wordpress',
            'fb', 'facebook' => 'facebook',
            'google_drive', 'googledrive', 'drive', 'gdrive' => 'google_drive',
            default => $normalized,
        };
    }

    /**
     * Retrieve decrypted credentials for provider, falling back to config.
     *
     * @return array<string, mixed>
     */
    public function getCredentials(string $provider): array
    {
        $channel = $this->canonicalChannel($provider);
        $record = IntegrationSetting::query()->where('channel', $channel)->first();
        $credentials = $record?->encrypted_credentials ?? [];

        if ($channel === 'wordpress') {
            return array_merge([
                'password' => (string) ($credentials['password'] ?? config('services.wordpress.password', '')),
            ], $credentials);
        }

        if ($channel === 'facebook') {
            return array_merge([
                'access_token' => (string) ($credentials['access_token'] ?? config('services.facebook.access_token', '')),
            ], $credentials);
        }

        if ($channel === 'google_drive') {
            return array_merge([
                'client_secret' => (string) ($credentials['client_secret'] ?? config('services.google_drive.client_secret', '')),
                'refresh_token' => (string) ($credentials['refresh_token'] ?? config('services.google_drive.refresh_token', '')),
                'access_token' => (string) ($credentials['access_token'] ?? ''),
            ], $credentials);
        }

        return $credentials;
    }

    /**
     * Retrieve settings (non-secret configuration) for provider.
     *
     * @return array<string, mixed>
     */
    public function getSettings(string $provider): array
    {
        $channel = $this->canonicalChannel($provider);
        $record = IntegrationSetting::query()->where('channel', $channel)->first();
        $settings = $record?->settings ?? [];

        if ($channel === 'wordpress') {
            return [
                'url' => (string) ($settings['url'] ?? config('services.wordpress.url', '')),
                'username' => (string) ($settings['username'] ?? config('services.wordpress.username', '')),
            ];
        }

        if ($channel === 'facebook') {
            return [
                'page_id' => (string) ($settings['page_id'] ?? config('services.facebook.page_id', '')),
                'version' => (string) ($settings['version'] ?? config('services.facebook.version', 'v26.0')),
            ];
        }

        if ($channel === 'google_drive') {
            return [
                'client_id' => (string) ($settings['client_id'] ?? config('services.google_drive.client_id', '')),
                'root_folder_id' => (string) ($settings['root_folder_id'] ?? config('services.google_drive.root_folder_id', '')),
                'root_folder_name' => (string) ($settings['root_folder_name'] ?? 'ไดรฟ์หลักสำหรับเก็บประกาศ'),
                'connected_email' => (string) ($settings['connected_email'] ?? ''),
                'employee_categories' => (array) ($settings['employee_categories'] ?? [
                    'รายวัน',
                    'พนักงานประจำตามสัญญา',
                    'พนักงานมหาวิทยาลัย',
                    'พนักงานอื่นๆ',
                ]),
                'announcement_stages' => (array) ($settings['announcement_stages'] ?? [
                    'รับสมัคร',
                    'ประกาศรายชื่อ',
                    'ผลการคัดเลือก',
                ]),
                'default_category' => (string) ($settings['default_category'] ?? 'รายวัน'),
                'default_stage' => (string) ($settings['default_stage'] ?? 'รับสมัคร'),
                'default_comment_template' => (string) ($settings['default_comment_template'] ?? $settings['default_fb_comment_template'] ?? "📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}"),
                'default_fb_comment_template' => (string) ($settings['default_fb_comment_template'] ?? 'รายละเอียดประกาศฉบับเต็ม: {drive_url}'),
            ];
        }

        return $settings;
    }

    /**
     * Save provider credentials and optional settings.
     * Preserves existing secrets when credentials are null or empty string.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $settings
     */
    public function saveCredentials(string $provider, array $credentials, ?array $settings = null): void
    {
        $channel = $this->canonicalChannel($provider);
        $record = IntegrationSetting::query()->firstOrNew(['channel' => $channel]);

        $currentCredentials = $record->encrypted_credentials ?? [];
        foreach ($credentials as $key => $value) {
            if ($value !== null && $value !== '') {
                $currentCredentials[$key] = (string) $value;
            }
        }

        $currentSettings = $record->settings ?? [];
        if ($settings !== null) {
            foreach ($settings as $key => $value) {
                if ($value !== null) {
                    $currentSettings[$key] = $value;
                }
            }
        }

        if ($channel === 'wordpress' && isset($currentSettings['url'])) {
            $currentSettings['url'] = rtrim((string) $currentSettings['url'], '/');
        }

        $record->fill([
            'settings' => $currentSettings,
            'encrypted_credentials' => $currentCredentials,
            'status' => 'unchecked',
            'last_checked_at' => null,
            'last_error' => null,
        ])->save();

        $this->forgetHealthCache($channel);
    }

    /**
     * Check Facebook health, inspecting token expiration and required permissions.
     * Results are cached for 5 minutes unless $forceFresh is true.
     *
     * @return array{status: 'healthy'|'unhealthy', page_id: ?string, page_name: ?string, expires_at: ?string, permissions: array<string>, error: ?string}
     */
    public function checkFacebookHealth(bool $forceFresh = false): array
    {
        $cacheKey = 'integrations.facebook.health_check';

        if (! $forceFresh && Cache::has($cacheKey)) {
            /** @var array{status: 'healthy'|'unhealthy', page_id: ?string, page_name: ?string, expires_at: ?string, permissions: array<string>, error: ?string} */
            return Cache::get($cacheKey);
        }

        $settings = $this->getSettings('facebook');
        $credentials = $this->getCredentials('facebook');

        $pageId = (string) ($settings['page_id'] ?? '');
        $accessToken = (string) ($credentials['access_token'] ?? '');
        $version = (string) ($settings['version'] ?? 'v26.0');

        if ($pageId === '' || $accessToken === '') {
            $result = [
                'status' => 'unhealthy',
                'page_id' => $pageId ?: null,
                'page_name' => null,
                'expires_at' => null,
                'permissions' => [],
                'error' => 'Facebook page ID or access token is not configured.',
            ];
            $this->markHealth('facebook', false, $result['error']);

            return $result;
        }

        try {
            $baseUrl = "https://graph.facebook.com/{$version}";

            // 1. Probe Page connectivity using Bearer Token
            $pageResponse = Http::withToken($accessToken)
                ->connectTimeout(5)
                ->timeout(10)
                ->get("{$baseUrl}/{$pageId}", [
                    'fields' => 'id,name',
                ]);

            if ($pageResponse->failed()) {
                $errorData = $pageResponse->json('error', []);
                $code = $errorData['code'] ?? null;
                $message = $errorData['message'] ?? 'Unable to connect to Facebook Page.';

                $errorMsg = match (true) {
                    $code === 190 => 'เซสชัน Facebook หมดอายุหรือไม่ถูกต้อง กรุณาเชื่อมต่อบัญชีใหม่ (Code 190)',
                    in_array($code, [200, 283], true) => 'สิทธิ์ Facebook ไม่เพียงพอบนหน้านี้ (Code '.$code.')',
                    in_array($code, [4, 17, 32], true) || $pageResponse->status() === 429 => "Facebook API จำกัดการเรียกใช้งานชั่วคราว (Too many requests): {$message}",
                    default => "Facebook API Error: {$message}",
                };

                $result = [
                    'status' => 'unhealthy',
                    'page_id' => $pageId,
                    'page_name' => null,
                    'expires_at' => null,
                    'permissions' => [],
                    'error' => $errorMsg,
                ];

                Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                Cache::put('integrations.facebook.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                $this->markHealth('facebook', false, $result['error']);

                return $result;
            }

            $returnedPageId = (string) $pageResponse->json('id');
            $pageName = (string) $pageResponse->json('name');

            if ($returnedPageId !== $pageId) {
                $result = [
                    'status' => 'unhealthy',
                    'page_id' => $returnedPageId ?: null,
                    'page_name' => $pageName ?: null,
                    'expires_at' => null,
                    'permissions' => [],
                    'error' => "Facebook Page ID ไม่ตรงกับที่กำหนดไว้ (ได้รับ: {$returnedPageId}, กำหนดไว้: {$pageId})",
                ];

                Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                Cache::put('integrations.facebook.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                $this->markHealth('facebook', false, $result['error']);

                return $result;
            }

            $allPermissions = [];

            // 2. Inspect Permissions (/me/permissions)
            $permissionsResponse = Http::withToken($accessToken)
                ->connectTimeout(5)
                ->timeout(10)
                ->get("{$baseUrl}/me/permissions");

            if ($permissionsResponse->successful() && is_array($permissionsResponse->json('data'))) {
                $granted = collect($permissionsResponse->json('data'))
                    ->where('status', 'granted')
                    ->pluck('permission')
                    ->map(fn ($p) => (string) $p)
                    ->values()
                    ->all();

                $allPermissions = array_values(array_unique(array_merge($allPermissions, $granted)));

                $required = ['pages_manage_posts', 'pages_read_engagement'];
                $missing = array_diff($required, $granted);

                if (! empty($missing)) {
                    $result = [
                        'status' => 'unhealthy',
                        'page_id' => $pageId,
                        'page_name' => $pageName,
                        'expires_at' => null,
                        'permissions' => $allPermissions,
                        'error' => 'สิทธิ์ Facebook ไม่เพียงพอ ขาดสิทธิ์: '.implode(', ', $missing),
                    ];

                    Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                    Cache::put('integrations.facebook.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                    $this->markHealth('facebook', false, $result['error']);

                    return $result;
                }
            } elseif ($permissionsResponse->failed()) {
                $permCode = $permissionsResponse->json('error.code');
                if ($permCode == 200 || $permCode == 283) {
                    $result = [
                        'status' => 'unhealthy',
                        'page_id' => $pageId,
                        'page_name' => $pageName,
                        'expires_at' => null,
                        'permissions' => $allPermissions,
                        'error' => 'สิทธิ์ Facebook ไม่เพียงพอ (Code '.$permCode.')',
                    ];

                    Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                    Cache::put('integrations.facebook.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                    $this->markHealth('facebook', false, $result['error']);

                    return $result;
                }
            }

            // 3. Inspect Expiration (/debug_token)
            $expiresAt = null;
            $debugResponse = Http::withToken($accessToken)
                ->connectTimeout(5)
                ->timeout(10)
                ->get("{$baseUrl}/debug_token", [
                    'input_token' => $accessToken,
                ]);

            if ($debugResponse->successful()) {
                $tokenExpiresAt = $debugResponse->json('data.expires_at');
                if (is_numeric($tokenExpiresAt) && (int) $tokenExpiresAt > 0) {
                    $expiryCarbon = Carbon::createFromTimestamp((int) $tokenExpiresAt);
                    $expiresAt = $expiryCarbon->toIso8601String();

                    if ($expiryCarbon->isPast()) {
                        $result = [
                            'status' => 'unhealthy',
                            'page_id' => $pageId,
                            'page_name' => $pageName,
                            'expires_at' => $expiresAt,
                            'permissions' => $allPermissions,
                            'error' => 'เซสชัน Facebook หมดอายุแล้ว กรุณาเชื่อมต่อบัญชีใหม่',
                        ];

                        Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                        Cache::put('integrations.facebook.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                        $this->markHealth('facebook', false, $result['error']);

                        return $result;
                    }
                }
            }

            $result = [
                'status' => 'healthy',
                'page_id' => $pageId,
                'page_name' => $pageName,
                'expires_at' => $expiresAt,
                'permissions' => $allPermissions,
                'error' => null,
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            Cache::put('integrations.facebook.health', true, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            $this->markHealth('facebook', true, null);

            return $result;
        } catch (\Throwable $e) {
            $result = [
                'status' => 'unhealthy',
                'page_id' => $pageId,
                'page_name' => null,
                'expires_at' => null,
                'permissions' => [],
                'error' => 'Facebook Health Check Failed: '.$e->getMessage(),
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            Cache::put('integrations.facebook.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            $this->markHealth('facebook', false, $result['error']);

            return $result;
        }
    }

    /**
     * Check WordPress health, probing REST API endpoint /wp-json/wp/v2/users/me and publish_posts capability.
     * Results are cached for 5 minutes unless $forceFresh is true.
     *
     * @return array{status: 'healthy'|'unhealthy', user: ?string, can_publish: bool, roles: array<string>, error: ?string}
     */
    public function checkWordPressHealth(bool $forceFresh = false): array
    {
        $cacheKey = 'integrations.wordpress.health_check';

        if (! $forceFresh && Cache::has($cacheKey)) {
            /** @var array{status: 'healthy'|'unhealthy', user: ?string, can_publish: bool, roles: array<string>, error: ?string} */
            return Cache::get($cacheKey);
        }

        $settings = $this->getSettings('wordpress');
        $credentials = $this->getCredentials('wordpress');

        $url = rtrim((string) ($settings['url'] ?? ''), '/');
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        if ($url === '' || $username === '' || $password === '') {
            $result = [
                'status' => 'unhealthy',
                'user' => null,
                'can_publish' => false,
                'roles' => [],
                'error' => 'WordPress credentials are not configured.',
            ];
            $this->markHealth('wordpress', false, $result['error']);

            return $result;
        }

        try {
            $response = Http::withBasicAuth($username, $password)
                ->connectTimeout(3)
                ->timeout(5)
                ->get("{$url}/wp-json/wp/v2/users/me");

            if ($response->status() === 401) {
                $result = [
                    'status' => 'unhealthy',
                    'user' => null,
                    'can_publish' => false,
                    'roles' => [],
                    'error' => 'ไม่สามารถเข้าสู่ระบบ WordPress ได้ กรุณาตรวจสอบ Username และ Application Password (HTTP 401)',
                ];

                Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                Cache::put('integrations.wordpress.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                $this->markHealth('wordpress', false, $result['error']);

                return $result;
            }

            if (! $response->successful()) {
                $result = [
                    'status' => 'unhealthy',
                    'user' => null,
                    'can_publish' => false,
                    'roles' => [],
                    'error' => 'WordPress REST API Error: HTTP '.$response->status(),
                ];

                Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                Cache::put('integrations.wordpress.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                $this->markHealth('wordpress', false, $result['error']);

                return $result;
            }

            $user = (string) ($response->json('name') ?? $response->json('username') ?? $username);
            $roles = (array) $response->json('roles', []);

            // Determine if user has publish capability
            $canPublish = true;
            if ($response->json('capabilities') !== null) {
                $canPublish = (bool) $response->json('capabilities.publish_posts', false);
            } elseif (! empty($roles)) {
                $canPublish = ! empty(array_intersect($roles, ['administrator', 'editor', 'author']));
            }

            if (! $canPublish) {
                $result = [
                    'status' => 'unhealthy',
                    'user' => $user,
                    'can_publish' => false,
                    'roles' => $roles,
                    'error' => 'บัญชีผู้ใช้ WordPress ไม่มีสิทธิ์สร้างบทความ (ต้องการสิทธิ์ publish_posts)',
                ];

                Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                Cache::put('integrations.wordpress.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
                $this->markHealth('wordpress', false, $result['error']);

                return $result;
            }

            $result = [
                'status' => 'healthy',
                'user' => $user,
                'can_publish' => true,
                'roles' => $roles,
                'error' => null,
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            Cache::put('integrations.wordpress.health', true, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            $this->markHealth('wordpress', true, null);

            return $result;
        } catch (\Throwable $e) {
            $result = [
                'status' => 'unhealthy',
                'user' => null,
                'can_publish' => false,
                'roles' => [],
                'error' => 'ไม่สามารถเชื่อมต่อไปยังเว็บไซต์ WordPress ได้: '.$e->getMessage(),
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            Cache::put('integrations.wordpress.health', false, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            $this->markHealth('wordpress', false, $result['error']);

            return $result;
        }
    }

    /**
     * Clear cached health checks for a specific channel or all channels.
     */
    public function forgetHealthCache(?string $provider = null): void
    {
        $channels = $provider ? [$this->canonicalChannel($provider)] : ['wordpress', 'facebook', 'google_drive'];

        foreach ($channels as $channel) {
            Cache::forget("integrations.{$channel}.health_check");
            Cache::forget("integrations.{$channel}.health");
        }
    }

    // -------------------------------------------------------------------------
    // Backward Compatibility Layer (Required by Controllers & Publishers)
    // -------------------------------------------------------------------------

    /**
     * @return array{url:string, username:string, password:string, status:string, last_checked_at:?Carbon}
     */
    public function wordpress(): array
    {
        $settings = $this->getSettings('wordpress');
        $credentials = $this->getCredentials('wordpress');
        $record = IntegrationSetting::query()->where('channel', 'wordpress')->first();

        return [
            'url' => (string) ($settings['url'] ?? ''),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($credentials['password'] ?? ''),
            'status' => (string) ($record?->status ?? 'unchecked'),
            'last_checked_at' => $record?->last_checked_at,
        ];
    }

    /**
     * @return array{page_id:string, access_token:string, version:string, status:string, last_checked_at:?Carbon}
     */
    public function facebook(): array
    {
        $settings = $this->getSettings('facebook');
        $credentials = $this->getCredentials('facebook');
        $record = IntegrationSetting::query()->where('channel', 'facebook')->first();

        return [
            'page_id' => (string) ($settings['page_id'] ?? ''),
            'access_token' => (string) ($credentials['access_token'] ?? ''),
            'version' => (string) ($settings['version'] ?? config('services.facebook.version', 'v26.0')),
            'status' => (string) ($record?->status ?? 'unchecked'),
            'last_checked_at' => $record?->last_checked_at,
        ];
    }

    public function updateWordPress(string $url, string $username, ?string $password = null): IntegrationSetting
    {
        $credentials = [];
        if ($password !== null && $password !== '') {
            $credentials['password'] = $password;
        }

        $this->saveCredentials('wordpress', $credentials, [
            'url' => $url,
            'username' => $username,
        ]);

        return IntegrationSetting::query()->where('channel', 'wordpress')->firstOrFail();
    }

    public function updateFacebook(string $pageId, ?string $accessToken = null): IntegrationSetting
    {
        $credentials = [];
        if ($accessToken !== null && $accessToken !== '') {
            $credentials['access_token'] = $accessToken;
        }

        $this->saveCredentials('facebook', $credentials, [
            'page_id' => $pageId,
            'version' => config('services.facebook.version', 'v26.0'),
        ]);

        return IntegrationSetting::query()->where('channel', 'facebook')->firstOrFail();
    }

    public function googleDrive(): array
    {
        $settings = $this->getSettings('google_drive');
        $credentials = $this->getCredentials('google_drive');
        $record = IntegrationSetting::query()->where('channel', 'google_drive')->first();

        $clientId = (string) ($settings['client_id'] ?? '');
        $clientSecretConfigured = ! empty($credentials['client_secret']);
        $hasRefreshToken = ! empty($credentials['refresh_token']);
        $rootFolderId = (string) ($settings['root_folder_id'] ?? '');

        $isConfigured = $clientId !== '' && $clientSecretConfigured && $hasRefreshToken && $rootFolderId !== '';

        return [
            'client_id' => $clientId,
            'client_secret' => '',
            'client_secret_configured' => $clientSecretConfigured,
            'has_refresh_token' => $hasRefreshToken,
            'refresh_token_configured' => $hasRefreshToken,
            'root_folder_id' => $rootFolderId,
            'root_folder_url' => $rootFolderId ? "https://drive.google.com/drive/folders/{$rootFolderId}" : null,
            'root_folder_name' => (string) ($settings['root_folder_name'] ?? 'ไดรฟ์หลักสำหรับเก็บประกาศ'),
            'connected_email' => (string) ($settings['connected_email'] ?? ''),
            'default_category' => (string) ($settings['default_category'] ?? GoogleDriveFilenameBuilder::CATEGORY_DAILY),
            'default_stage' => (string) ($settings['default_stage'] ?? GoogleDriveFilenameBuilder::STAGE_ANNOUNCEMENT),
            'employee_categories' => (array) ($settings['employee_categories'] ?? GoogleDriveFilenameBuilder::categories()),
            'announcement_stages' => (array) ($settings['announcement_stages'] ?? GoogleDriveFilenameBuilder::stages()),
            'default_fb_comment_template' => (string) ($settings['default_fb_comment_template'] ?? "📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}"),
            'default_comment_template' => (string) ($settings['default_comment_template'] ?? $settings['default_fb_comment_template'] ?? "📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}"),
            'status' => (string) ($record?->status ?? ($isConfigured ? 'unchecked' : 'disconnected')),
            'configured' => $isConfigured,
            'last_checked_at' => $record?->last_checked_at,
            'last_error' => $record?->last_error,
        ];
    }

    public function updateGoogleDrive(
        string $clientId,
        ?string $clientSecret = null,
        ?string $rootFolderId = null,
        ?string $defaultCommentTemplate = null,
        ?array $employeeCategories = null,
        ?array $announcementStages = null,
        ?string $defaultCategory = null,
        ?string $defaultStage = null,
    ): IntegrationSetting {
        $credentials = [];
        if ($clientSecret !== null && $clientSecret !== '') {
            $credentials['client_secret'] = $clientSecret;
        }

        $cleanFolderId = $rootFolderId !== null ? GoogleDriveFilenameBuilder::extractFolderId($rootFolderId) : null;

        $settings = [
            'client_id' => trim($clientId),
        ];

        if ($cleanFolderId !== null) {
            $settings['root_folder_id'] = $cleanFolderId;
        }
        if ($defaultCommentTemplate !== null) {
            $settings['default_fb_comment_template'] = trim($defaultCommentTemplate);
            $settings['default_comment_template'] = trim($defaultCommentTemplate);
        }
        if ($employeeCategories !== null) {
            $settings['employee_categories'] = array_values(array_filter(array_map('trim', $employeeCategories)));
        }
        if ($announcementStages !== null) {
            $settings['announcement_stages'] = array_values(array_filter(array_map('trim', $announcementStages)));
        }
        if ($defaultCategory !== null) {
            $settings['default_category'] = trim($defaultCategory);
        }
        if ($defaultStage !== null) {
            $settings['default_stage'] = trim($defaultStage);
        }

        $this->saveCredentials('google_drive', $credentials, $settings);

        return IntegrationSetting::query()->where('channel', 'google_drive')->firstOrFail();
    }

    public function saveGoogleOAuthTokens(string $refreshToken, ?string $accessToken = null, ?string $connectedEmail = null): void
    {
        $credentials = [
            'refresh_token' => $refreshToken,
        ];
        if ($accessToken !== null && $accessToken !== '') {
            $credentials['access_token'] = $accessToken;
        }

        $settings = [];
        if ($connectedEmail !== null && $connectedEmail !== '') {
            $settings['connected_email'] = trim($connectedEmail);
        }

        $this->saveCredentials('google_drive', $credentials, ! empty($settings) ? $settings : null);
        $this->markHealth('google_drive', true, null);
    }

    public function disconnectGoogleDrive(): void
    {
        $record = IntegrationSetting::query()->where('channel', 'google_drive')->first();
        if ($record) {
            $creds = $record->encrypted_credentials ?? [];
            unset($creds['refresh_token'], $creds['access_token']);

            $settings = $record->settings ?? [];
            unset($settings['connected_email']);

            $record->update([
                'encrypted_credentials' => $creds,
                'settings' => $settings,
                'status' => 'disconnected',
                'last_error' => null,
            ]);
        }
        $this->forgetHealthCache('google_drive');
    }

    public function checkGoogleDriveHealth(bool $forceFresh = false): array
    {
        $cacheKey = 'integrations.google_drive.health_check';

        if (! $forceFresh && Cache::has($cacheKey)) {
            /** @var array{status: 'healthy'|'unhealthy', folder_id: ?string, folder_name: ?string, email: ?string, error: ?string} */
            return Cache::get($cacheKey);
        }

        $settings = $this->getSettings('google_drive');
        $credentials = $this->getCredentials('google_drive');

        $clientId = (string) ($settings['client_id'] ?? '');
        $clientSecret = (string) ($credentials['client_secret'] ?? '');
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');
        $rootFolderId = (string) ($settings['root_folder_id'] ?? '');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            $result = [
                'status' => 'unhealthy',
                'folder_id' => $rootFolderId ?: null,
                'folder_name' => null,
                'email' => null,
                'error' => 'ยังไม่ได้เชื่อมต่อบัญชี Google หรือไม่ได้กำหนด Client ID / Secret',
            ];
            $this->markHealth('google_drive', false, $result['error']);

            return $result;
        }

        if ($rootFolderId === '') {
            $result = [
                'status' => 'unhealthy',
                'folder_id' => null,
                'folder_name' => null,
                'email' => (string) ($settings['connected_email'] ?? ''),
                'error' => 'ยังไม่ได้ระบุโฟลเดอร์หลัก (Root Folder ID) สำหรับเก็บประกาศ',
            ];
            $this->markHealth('google_drive', false, $result['error']);

            return $result;
        }

        try {
            $driveService = app(GoogleDriveService::class);
            $token = $driveService->validAccessToken();

            if (! $token) {
                $result = [
                    'status' => 'unhealthy',
                    'folder_id' => $rootFolderId,
                    'folder_name' => null,
                    'email' => null,
                    'error' => 'ไม่สามารถขอ Access Token จาก Google ได้ กรุณาเชื่อมต่อบัญชีใหม่อีกครั้ง',
                ];
                $this->markHealth('google_drive', false, $result['error']);

                return $result;
            }

            $response = Http::withToken($token)
                ->connectTimeout(5)
                ->timeout(10)
                ->get("https://www.googleapis.com/drive/v3/files/{$rootFolderId}", [
                    'supportsAllDrives' => 'true',
                    'fields' => 'id,name,mimeType,trashed',
                ]);

            if ($response->failed()) {
                $err = $response->json('error.message', 'ไม่สามารถเข้าถึงโฟลเดอร์ใน Google Drive ได้');
                $result = [
                    'status' => 'unhealthy',
                    'folder_id' => $rootFolderId,
                    'folder_name' => null,
                    'email' => (string) ($settings['connected_email'] ?? ''),
                    'error' => "Google Drive API: {$err}",
                ];
                $this->markHealth('google_drive', false, $result['error']);
                Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));

                return $result;
            }

            $folderData = $response->json();
            $folderName = (string) ($folderData['name'] ?? 'โฟลเดอร์หลัก');

            if (($folderData['trashed'] ?? false) === true) {
                $result = [
                    'status' => 'unhealthy',
                    'folder_id' => $rootFolderId,
                    'folder_name' => $folderName,
                    'email' => (string) ($settings['connected_email'] ?? ''),
                    'error' => 'โฟลเดอร์หลักที่ระบุอยู่ในถังขยะ (Trash) กรุณากู้คืนหรือเปลี่ยนโฟลเดอร์',
                ];
                $this->markHealth('google_drive', false, $result['error']);

                return $result;
            }

            $result = [
                'status' => 'healthy',
                'folder_id' => $rootFolderId,
                'folder_name' => $folderName,
                'email' => (string) ($settings['connected_email'] ?? ''),
                'error' => null,
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(self::HEALTH_CACHE_TTL_MINUTES));
            $this->markHealth('google_drive', true, null);

            return $result;
        } catch (\Throwable $e) {
            $result = [
                'status' => 'unhealthy',
                'folder_id' => $rootFolderId,
                'folder_name' => null,
                'email' => (string) ($settings['connected_email'] ?? ''),
                'error' => 'ตรวจสอบ Google Drive ไม่สำเร็จ: '.$e->getMessage(),
            ];
            $this->markHealth('google_drive', false, $result['error']);

            return $result;
        }
    }

    public function markHealth(string $channel, bool $connected, ?string $error = null): void
    {
        IntegrationSetting::query()->updateOrCreate(
            ['channel' => $this->canonicalChannel($channel)],
            [
                'status' => $connected ? 'connected' : 'error',
                'last_checked_at' => now(),
                'last_error' => $connected ? null : ($error ? SecretSanitizer::sanitize($error) : null),
            ],
        );
    }
}
