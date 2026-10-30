<?php

namespace App\Services;

use App\Models\PostMedia;
use App\Support\SecretSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GoogleDriveService
{
    protected IntegrationSettingsService $settingsService;

    protected string $clientId;

    protected string $clientSecret;

    protected string $refreshToken;

    protected string $rootFolderId;

    public function __construct(?IntegrationSettingsService $settings = null)
    {
        $this->settingsService = $settings ?? app(IntegrationSettingsService::class);

        $googleSettings = $this->settingsService->getSettings('google_drive');
        $credentials = $this->settingsService->getCredentials('google_drive');

        $this->clientId = (string) ($googleSettings['client_id'] ?? '');
        $this->clientSecret = (string) ($credentials['client_secret'] ?? '');
        $this->refreshToken = (string) ($credentials['refresh_token'] ?? '');
        $this->rootFolderId = (string) ($googleSettings['root_folder_id'] ?? '');
    }

    /**
     * Build Google OAuth 2.0 Authorization URL.
     */
    public function buildAuthUrl(string $redirectUri, string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params);
    }

    /**
     * Exchange authorization code for refresh_token and access_token.
     *
     * @return array{success: bool, email: ?string, error: ?string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        try {
            $response = Http::asForm()
                ->connectTimeout(10)
                ->timeout(20)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                ]);

            if ($response->failed()) {
                $errorMsg = (string) ($response->json('error_description') ?? $response->json('error') ?? $response->body());

                return [
                    'success' => false,
                    'email' => null,
                    'error' => 'Google OAuth Token Error: '.$errorMsg,
                ];
            }

            $data = $response->json();
            $accessToken = (string) ($data['access_token'] ?? '');
            $refreshToken = (string) ($data['refresh_token'] ?? '');

            if ($refreshToken === '') {
                // If Google didn't return a new refresh token (e.g. user previously approved and prompt!=consent was cached),
                // check if we already have one stored
                $existingCreds = $this->settingsService->getCredentials('google_drive');
                $refreshToken = (string) ($existingCreds['refresh_token'] ?? '');
            }

            if ($refreshToken === '') {
                return [
                    'success' => false,
                    'email' => null,
                    'error' => 'ไม่ได้รับ Refresh Token จาก Google กรุณาลองเชื่อมต่อใหม่อีกครั้ง',
                ];
            }

            // Fetch user email to display in Settings
            $email = null;
            if ($accessToken !== '') {
                try {
                    $userResponse = Http::withToken($accessToken)
                        ->connectTimeout(5)
                        ->timeout(10)
                        ->get('https://www.googleapis.com/oauth2/v3/userinfo');

                    if ($userResponse->successful()) {
                        $email = (string) ($userResponse->json('email') ?? '');
                    }
                } catch (Throwable) {
                    // Ignore email lookup failure
                }
            }

            $this->settingsService->saveGoogleOAuthTokens($refreshToken, $accessToken, $email);

            return [
                'success' => true,
                'email' => $email,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'email' => null,
                'error' => 'เชื่อมต่อ Google OAuth ไม่สำเร็จ: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Refresh Google access token using stored refresh_token.
     */
    public function refreshAccessToken(): ?string
    {
        $credentials = $this->settingsService->getCredentials('google_drive');
        $refreshToken = (string) ($credentials['refresh_token'] ?? $this->refreshToken);

        if ($this->clientId === '' || $this->clientSecret === '' || $refreshToken === '') {
            return null;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(10)
                ->timeout(20)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);

            if ($response->failed()) {
                Log::error('Google Drive Token Refresh Error: '.SecretSanitizer::sanitize($response->body()));

                return null;
            }

            $newAccessToken = (string) ($response->json('access_token') ?? '');
            if ($newAccessToken !== '') {
                $expiresIn = (int) ($response->json('expires_in') ?? 3600);
                Cache::put('google_drive.access_token', $newAccessToken, now()->addSeconds(max(60, $expiresIn - 120)));

                // Also update database record
                $this->settingsService->saveCredentials('google_drive', ['access_token' => $newAccessToken]);

                return $newAccessToken;
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Google Drive Token Refresh Exception: '.SecretSanitizer::sanitize($e->getMessage()));

            return null;
        }
    }

    /**
     * Get valid access token, auto-refreshing if expired.
     */
    public function validAccessToken(): ?string
    {
        $cachedToken = Cache::get('google_drive.access_token');
        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        return $this->refreshAccessToken();
    }

    /**
     * Search for a folder with a given name under a specific parent folder,
     * applying Thai normalized matching.
     */
    public function findChildFolder(string $parentId, string $folderName): ?array
    {
        $token = $this->validAccessToken();
        if (! $token) {
            return null;
        }

        $escapedParent = addslashes($parentId);

        $response = Http::withToken($token)
            ->connectTimeout(5)
            ->timeout(15)
            ->get('https://www.googleapis.com/drive/v3/files', [
                'q' => "'{$escapedParent}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
                'fields' => 'files(id, name, mimeType, parents)',
                'pageSize' => 100,
            ]);

        if ($response->failed()) {
            return null;
        }

        $files = (array) ($response->json('files') ?? []);
        $targetNormalized = GoogleDriveFilenameBuilder::normalizeThaiFolderName($folderName);

        // 1. Exact match first
        foreach ($files as $file) {
            if (trim((string) ($file['name'] ?? '')) === trim($folderName)) {
                return $file;
            }
        }

        // 2. Thai normalized match (handling maiyamok and whitespace variants)
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            if (GoogleDriveFilenameBuilder::normalizeThaiFolderName($name) === $targetNormalized) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Create a folder under a parent folder.
     */
    public function createFolder(string $parentId, string $folderName): ?array
    {
        $token = $this->validAccessToken();
        if (! $token) {
            return null;
        }

        $response = Http::withToken($token)
            ->connectTimeout(5)
            ->timeout(15)
            ->post('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true', [
                'name' => trim($folderName),
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$parentId],
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Google Drive Create Folder Error: '.SecretSanitizer::sanitize($response->body()));

        return null;
    }

    /**
     * Resolve target announcement folder: {Root} / {Category} / {Stage}.
     * Automatically creates missing subfolders if needed.
     *
     * @return array{folder_id: string, folder_name: string, folder_path: string}
     */
    public function resolveStageFolder(string $employeeCategory, string $announcementStage): array
    {
        $rootId = $this->rootFolderId;
        if ($rootId === '') {
            throw new \RuntimeException('ยังไม่ได้ระบุโฟลเดอร์หลัก Google Drive (Root Folder ID)');
        }

        $cleanCategory = trim($employeeCategory) ?: 'ทั่วไป';
        $cleanStage = trim($announcementStage) ?: 'เอกสารประกาศ';

        // 1. Resolve Category folder
        $categoryFolder = $this->findChildFolder($rootId, $cleanCategory);
        if (! $categoryFolder) {
            $categoryFolder = $this->createFolder($rootId, $cleanCategory);
            if (! $categoryFolder) {
                throw new \RuntimeException("ไม่สามารถสร้างหรือค้นหาโฟลเดอร์ประเภทพนักงาน [{$cleanCategory}] ใน Google Drive ได้");
            }
        }
        $categoryId = (string) $categoryFolder['id'];

        // 2. Resolve Stage folder under Category folder
        $stageFolder = $this->findChildFolder($categoryId, $cleanStage);
        if (! $stageFolder) {
            $stageFolder = $this->createFolder($categoryId, $cleanStage);
            if (! $stageFolder) {
                throw new \RuntimeException("ไม่สามารถสร้างหรือค้นหาโฟลเดอร์ขั้นตอนประกาศ [{$cleanStage}] ภายใต้ [{$cleanCategory}] ได้");
            }
        }
        $stageId = (string) $stageFolder['id'];

        return [
            'folder_id' => $stageId,
            'folder_name' => $cleanStage,
            'folder_path' => "{$cleanCategory} / {$cleanStage}",
        ];
    }

    /**
     * Inspect folder hierarchy under root folder.
     *
     * @return array<string, mixed>
     */
    public function inspectFolderTree(bool $createMissing = false): array
    {
        $rootId = $this->rootFolderId;
        if ($rootId === '') {
            return [
                'configured' => false,
                'error' => 'ยังไม่ได้ระบุ Root Folder ID',
                'tree' => [],
            ];
        }

        $settings = $this->settingsService->getSettings('google_drive');
        $categories = (array) ($settings['employee_categories'] ?? []);
        $stages = (array) ($settings['announcement_stages'] ?? []);

        $tree = [];
        $totalFolders = 0;
        $foundFolders = 0;

        foreach ($categories as $category) {
            $catFolder = $this->findChildFolder($rootId, $category);
            if (! $catFolder && $createMissing) {
                $catFolder = $this->createFolder($rootId, $category);
            }

            $catExists = $catFolder !== null;
            $catId = $catFolder ? (string) $catFolder['id'] : null;
            $totalFolders++;
            if ($catExists) {
                $foundFolders++;
            }

            $stageList = [];
            foreach ($stages as $stage) {
                $stageFolder = null;
                if ($catId) {
                    $stageFolder = $this->findChildFolder($catId, $stage);
                    if (! $stageFolder && $createMissing) {
                        $stageFolder = $this->createFolder($catId, $stage);
                    }
                }

                $stageExists = $stageFolder !== null;
                $totalFolders++;
                if ($stageExists) {
                    $foundFolders++;
                }

                $stageList[] = [
                    'name' => $stage,
                    'exists' => $stageExists,
                    'id' => $stageFolder ? (string) $stageFolder['id'] : null,
                ];
            }

            $tree[] = [
                'category' => $category,
                'exists' => $catExists,
                'id' => $catId,
                'stages' => $stageList,
            ];
        }

        return [
            'configured' => true,
            'root_folder_id' => $rootId,
            'total_expected' => $totalFolders,
            'total_found' => $foundFolders,
            'tree' => $tree,
        ];
    }

    /**
     * Upload and sync PDF PostMedia to Google Drive.
     * Idempotent: returns existing synced record if already uploaded.
     *
     * @param  array{
     *     employee_category?: ?string,
     *     announcement_stage?: ?string,
     *     document_date?: ?string,
     *     position_title?: ?string,
     *     custom_filename?: ?string,
     *     force?: bool
     * }  $options
     */
    public function syncPdfMedia(PostMedia $media, array $options = []): PostMedia
    {
        $lockKey = "google_drive.sync_media.{$media->id}";
        $lock = Cache::lock($lockKey, 120);

        return $lock->block(90, function () use ($media, $options): PostMedia {
            $media->refresh();

            $force = (bool) ($options['force'] ?? false);
            if (! $force && $media->drive_sync_status === 'synced' && $media->drive_file_id && $media->drive_web_view_link) {
                return $media;
            }

            $category = trim((string) ($options['employee_category'] ?? $media->drive_employee_category ?? 'ทั่วไป'));
            $stage = trim((string) ($options['announcement_stage'] ?? $media->drive_announcement_stage ?? 'รับสมัคร'));
            $date = trim((string) ($options['document_date'] ?? $media->drive_document_date?->format('Y-m-d') ?? date('Y-m-d')));
            $position = trim((string) ($options['position_title'] ?? $media->drive_position_title ?? ''));
            $customFilename = trim((string) ($options['custom_filename'] ?? ''));

            $targetFilename = GoogleDriveFilenameBuilder::build($date, $stage, $category, $position, $customFilename);

            // Locate local file
            if (! $media->file_path || ! Storage::disk('public')->exists($media->file_path)) {
                $media->update([
                    'drive_sync_status' => 'failed',
                    'drive_error_message' => 'ไม่พบไฟล์ PDF ในระบบจัดเก็บในเครื่อง (File not found)',
                ]);
                throw new \RuntimeException('ไม่พบไฟล์ PDF ในระบบจัดเก็บ');
            }

            $localPath = Storage::disk('public')->path($media->file_path);
            $fileSize = filesize($localPath);
            $fileContent = file_get_contents($localPath);

            $token = $this->validAccessToken();
            if (! $token) {
                $media->update([
                    'drive_sync_status' => 'failed',
                    'drive_error_message' => 'ไม่สามารถขอ Access Token สำหรับเชื่อมต่อ Google Drive ได้ กรุณาเชื่อมต่อบัญชีใหม่',
                ]);
                throw new \RuntimeException('Google Drive authentication failed.');
            }

            // Resolve target folder: {Root} / {Category} / {Stage}
            $folderInfo = $this->resolveStageFolder($category, $stage);
            $targetFolderId = $folderInfo['folder_id'];
            $targetFolderPath = $folderInfo['folder_path'];

            $media->update([
                'drive_sync_status' => 'uploading',
                'drive_file_name' => $targetFilename,
                'drive_employee_category' => $category,
                'drive_announcement_stage' => $stage,
                'drive_document_date' => $date,
                'drive_position_title' => $position !== '' ? $position : null,
                'drive_folder_id' => $targetFolderId,
                'drive_folder_path' => $targetFolderPath,
                'drive_error_message' => null,
            ]);

            // Resumable Upload Step 1: Initialize session
            $initResponse = Http::withToken($token)
                ->connectTimeout(10)
                ->timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json; charset=UTF-8',
                    'X-Upload-Content-Type' => 'application/pdf',
                    'X-Upload-Content-Length' => (string) $fileSize,
                ])
                ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', [
                    'name' => $targetFilename,
                    'parents' => [$targetFolderId],
                    'mimeType' => 'application/pdf',
                ]);

            if ($initResponse->status() !== 200 && $initResponse->status() !== 201) {
                $err = 'Google Drive upload initiation failed: '.SecretSanitizer::sanitize($initResponse->body());
                $media->update([
                    'drive_sync_status' => 'failed',
                    'drive_error_message' => $err,
                ]);
                throw new \RuntimeException($err);
            }

            $uploadLocation = (string) $initResponse->header('Location');
            if ($uploadLocation === '') {
                $media->update([
                    'drive_sync_status' => 'failed',
                    'drive_error_message' => 'Google Drive did not return resumable upload Location header.',
                ]);
                throw new \RuntimeException('Upload session missing Location header');
            }

            // Resumable Upload Step 2: Upload binary data
            $uploadResponse = Http::connectTimeout(15)
                ->timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/pdf',
                    'Content-Length' => (string) $fileSize,
                ])
                ->withBody($fileContent, 'application/pdf')
                ->put($uploadLocation);

            if ($uploadResponse->failed()) {
                $err = 'Google Drive file transmission failed: '.SecretSanitizer::sanitize($uploadResponse->body());
                $media->update([
                    'drive_sync_status' => 'failed',
                    'drive_error_message' => $err,
                ]);
                throw new \RuntimeException($err);
            }

            $fileData = $uploadResponse->json();
            $driveFileId = (string) ($fileData['id'] ?? '');

            if ($driveFileId === '') {
                $media->update([
                    'drive_sync_status' => 'failed',
                    'drive_error_message' => 'Upload succeeded but no file ID was returned by Google Drive.',
                ]);
                throw new \RuntimeException('No Drive File ID returned');
            }

            // Public sharing permission: Anyone with link can view (role: reader, type: anyone)
            $permissionStatus = 'public_anyone';
            try {
                $permResponse = Http::withToken($token)
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->post("https://www.googleapis.com/drive/v3/files/{$driveFileId}/permissions?supportsAllDrives=true", [
                        'role' => 'reader',
                        'type' => 'anyone',
                    ]);

                if ($permResponse->failed()) {
                    $permissionStatus = 'restricted';
                    Log::warning('Google Drive public permission could not be set (domain restriction may apply): '.SecretSanitizer::sanitize($permResponse->body()));
                }
            } catch (Throwable $e) {
                $permissionStatus = 'restricted';
                Log::warning('Google Drive permission exception: '.SecretSanitizer::sanitize($e->getMessage()));
            }

            $webViewLink = "https://drive.google.com/file/d/{$driveFileId}/view?usp=sharing";

            $media->update([
                'drive_file_id' => $driveFileId,
                'drive_file_name' => $targetFilename,
                'drive_web_view_link' => $webViewLink,
                'drive_sync_status' => 'synced',
                'drive_permission_status' => $permissionStatus,
                'drive_synced_at' => now(),
                'drive_error_message' => null,
            ]);

            return $media->fresh();
        });
    }

    /**
     * Upload a PDF file directly to Google Drive.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function uploadPdf(PostMedia $media, array $metadata = []): array
    {
        try {
            $syncedMedia = $this->syncPdfMedia($media, array_merge($metadata, ['force' => true]));

            return [
                'success' => $syncedMedia->drive_sync_status === 'synced',
                'file_id' => $syncedMedia->drive_file_id,
                'file_name' => $syncedMedia->drive_file_name,
                'web_view_link' => $syncedMedia->drive_web_view_link,
                'folder_id' => $syncedMedia->drive_folder_id,
                'employee_category' => $syncedMedia->drive_employee_category,
                'announcement_stage' => $syncedMedia->drive_announcement_stage,
                'document_date' => $syncedMedia->drive_document_date?->format('Y-m-d'),
                'position_title' => $syncedMedia->drive_position_title,
                'error' => $syncedMedia->drive_error_message,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
