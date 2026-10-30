<?php

namespace App\Services\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Services\IntegrationSettingsService;
use App\Support\SecretSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WordPressPublisher implements PublisherInterface
{
    protected string $apiUrl;

    protected string $username;

    protected string $appPassword;

    public function __construct(?IntegrationSettingsService $settings = null)
    {
        $wordpress = ($settings ?? app(IntegrationSettingsService::class))->wordpress();

        $this->apiUrl = rtrim($wordpress['url'], '/');
        $this->username = $wordpress['username'];
        $this->appPassword = $wordpress['password'];
    }

    protected function uploadMediaItem($media): ?array
    {
        if (! $media || ! Storage::disk('public')->exists($media->file_path)) {
            return null;
        }

        $filePath = Storage::disk('public')->path($media->file_path);
        $fileName = $media->original_name ?: basename($filePath);

        $response = Http::withBasicAuth($this->username, $this->appPassword)
            ->connectTimeout(3)
            ->timeout(20)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            ])
            ->withBody(file_get_contents($filePath), $media->mime_type)
            ->post("{$this->apiUrl}/wp-json/wp/v2/media");

        if ($response->successful()) {
            $data = $response->json();

            return [
                'id' => $data['id'] ?? null,
                'source_url' => $data['source_url'] ?? null,
            ];
        }

        Log::error('WP Media Upload Error: '.SecretSanitizer::sanitize($response->body()));

        return null;
    }

    protected function uploadMedia($media)
    {
        $uploaded = $this->uploadMediaItem($media);

        return $uploaded['id'] ?? null;
    }

    public function formatWordPressContent(Post $post): string
    {
        $content = (string) ($post->wp_content ?? '');
        $hasButtons = ! empty($post->action_buttons) && is_array($post->action_buttons);
        $hasPdf = ! empty($post->pdf_media_id);

        $pdfMedia = $hasPdf ? ($post->pdfMedia ?: PostMedia::find($post->pdf_media_id)) : null;
        $driveUrl = (string) ($pdfMedia?->drive_web_view_link ?? '');

        // Resolve WordPress-uploaded media URL for the embedded iframe
        $wpViewerUrl = '';
        if ($pdfMedia) {
            $pdfUpload = $this->uploadMediaItem($pdfMedia);
            $wpViewerUrl = $pdfUpload['source_url'] ?? asset('storage/'.$pdfMedia->file_path);
        }

        $primaryDownloadUrl = $driveUrl !== '' ? $driveUrl : $wpViewerUrl;

        $content = str_replace(
            ['{drive_url}', '{{drive_url}}', '{pdf_url}', '{{pdf_url}}'],
            [$driveUrl !== '' ? $driveUrl : $wpViewerUrl, $driveUrl !== '' ? $driveUrl : $wpViewerUrl, $primaryDownloadUrl, $primaryDownloadUrl],
            $content
        );

        if (! $hasButtons && ! $hasPdf) {
            return $content;
        }

        $extraHtml = '';

        if ($hasButtons) {
            $buttonsHtml = '';
            foreach ($post->action_buttons as $btn) {
                $label = trim((string) ($btn['label'] ?? ''));
                $url = trim((string) ($btn['url'] ?? ''));
                if ($label === '' && $url === '') {
                    continue;
                }
                $resolvedUrl = str_replace(
                    ['{drive_url}', '{{drive_url}}', '{pdf_url}', '{{pdf_url}}'],
                    [$driveUrl !== '' ? $driveUrl : $wpViewerUrl, $driveUrl !== '' ? $driveUrl : $wpViewerUrl, $primaryDownloadUrl, $primaryDownloadUrl],
                    $url
                );
                $escapedLabel = htmlspecialchars($label !== '' ? $label : 'คลิกที่นี่', ENT_QUOTES, 'UTF-8');
                $escapedUrl = htmlspecialchars($resolvedUrl !== '' ? $resolvedUrl : '#', ENT_QUOTES, 'UTF-8');

                $buttonsHtml .= <<<HTML
<a href="{$escapedUrl}" target="_blank" rel="noopener noreferrer" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 22px; font-family: inherit; font-size: 15px; font-weight: 600; color: #ffffff; background-color: #000080; border-radius: 6px; text-decoration: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s ease-in-out;">
    <svg style="width: 16px; height: 16px; fill: currentColor; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M19 19H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>
    <span>{$escapedLabel}</span>
</a>
HTML;
            }

            if ($buttonsHtml !== '') {
                $extraHtml .= <<<HTML
<div class="poster-action-buttons-wrap" style="margin: 28px 0; padding: 16px 20px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
    <div style="font-weight: 600; font-size: 14px; color: #334155; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
        <svg style="width: 16px; height: 16px; fill: #000080;" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
        ลิงก์และเอกสารสำคัญ
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
        {$buttonsHtml}
    </div>
</div>
HTML;
            }
        }

        if ($hasPdf && $pdfMedia) {
            $escapedViewerUrl = htmlspecialchars($wpViewerUrl, ENT_QUOTES, 'UTF-8');
            $escapedDownloadUrl = htmlspecialchars($primaryDownloadUrl, ENT_QUOTES, 'UTF-8');
            $pdfName = htmlspecialchars($pdfMedia->original_name ?: 'เอกสารแนบ PDF', ENT_QUOTES, 'UTF-8');
            $isGoogleDriveLink = $driveUrl !== '';
            $downloadBtnLabel = $isGoogleDriveLink ? 'เปิดเอกสารบน Google Drive' : 'ดาวน์โหลดเอกสาร PDF';

            $extraHtml .= <<<HTML
<div class="poster-pdf-viewer-wrap" style="margin: 32px 0; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; background: #ffffff; box-shadow: 0 2px 6px rgba(0,0,0,0.06);">
    <div style="background: #f1f5f9; padding: 12px 18px; border-bottom: 1px solid #cbd5e1; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;">
        <div style="font-weight: 600; color: #0f172a; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <svg style="width: 20px; height: 20px; fill: #dc2626;" viewBox="0 0 24 24"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v2.5zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zm5.5 1.5H15v-2.5h-.5v2.5zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6z"/></svg>
            <span>{$pdfName}</span>
        </div>
        <a href="{$escapedDownloadUrl}" target="_blank" rel="noopener noreferrer" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 13px; font-weight: 600; color: #ffffff; background-color: #dc2626; border-radius: 4px; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
            <svg style="width: 14px; height: 14px; fill: currentColor;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg>
            <span>{$downloadBtnLabel}</span>
        </a>
    </div>
    <div style="position: relative; width: 100%; height: 680px; background: #525659;">
        <iframe src="{$escapedViewerUrl}#toolbar=1&navpanes=0" width="100%" height="100%" style="border: none; display: block;" title="PDF Viewer">
            <p style="padding: 20px; text-align: center; color: #ffffff;">
                เบราว์เซอร์ไม่รองรับการแสดงตัวอย่าง PDF ในหน้าเว็บ <a href="{$escapedDownloadUrl}" target="_blank" style="color: #60a5fa; text-decoration: underline;">คลิกที่นี่เพื่อเปิดหรือดาวน์โหลดเอกสาร</a>
            </p>
        </iframe>
    </div>
</div>
HTML;
        }

        return trim($content."\n\n".$extraHtml);
    }

    public function publish(Post $post, PublishTarget $target): array
    {
        try {
            $payload = [
                'title' => $post->title,
                'content' => $this->formatWordPressContent($post),
                'status' => 'publish',
            ];

            if ($post->wp_category_id) {
                $payload['categories'] = [$post->wp_category_id];
            }

            if ($post->featured_image_id) {
                $media = PostMedia::find($post->featured_image_id);
                $wpMediaId = $this->uploadMedia($media);
                if ($wpMediaId) {
                    $payload['featured_media'] = $wpMediaId;
                }
            }

            $response = Http::withBasicAuth($this->username, $this->appPassword)
                ->connectTimeout(3)
                ->timeout(15)
                ->post("{$this->apiUrl}/wp-json/wp/v2/posts", $payload);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'external_id' => (string) $data['id'],
                    'external_url' => $data['link'],
                    'error' => null,
                ];
            }

            Log::error('WordPress Publish Error: '.SecretSanitizer::sanitize($response->body()));

            return [
                'success' => false,
                'external_id' => null,
                'external_url' => null,
                'error' => SecretSanitizer::sanitize($response->body()),
                'http_status' => $response->status(),
                'error_code' => (string) ($response->json('code') ?? ''),
            ];

        } catch (\Exception $e) {
            Log::error('WordPress Publish Exception: '.SecretSanitizer::sanitize($e->getMessage()));

            return [
                'success' => false,
                'external_id' => null,
                'external_url' => null,
                'error' => SecretSanitizer::sanitize($e->getMessage()),
            ];
        }
    }

    public function update(Post $post, PublishTarget $target): array
    {
        if (! $target->external_id) {
            return ['success' => false, 'error' => 'No External ID provided for update'];
        }

        try {
            $payload = [
                'title' => $post->title,
                'content' => $this->formatWordPressContent($post),
            ];

            if ($post->wp_category_id) {
                $payload['categories'] = [$post->wp_category_id];
            }

            if ($post->featured_image_id) {
                $media = PostMedia::find($post->featured_image_id);
                $wpMediaId = $this->uploadMedia($media);
                if ($wpMediaId) {
                    $payload['featured_media'] = $wpMediaId;
                }
            }

            $response = Http::withBasicAuth($this->username, $this->appPassword)
                ->connectTimeout(3)
                ->timeout(15)
                ->post("{$this->apiUrl}/wp-json/wp/v2/posts/{$target->external_id}", $payload);

            if ($response->successful()) {
                return ['success' => true, 'external_id' => $target->external_id, 'external_url' => $target->external_url, 'error' => null];
            }

            return [
                'success' => false,
                'error' => SecretSanitizer::sanitize($response->body()),
                'http_status' => $response->status(),
                'error_code' => (string) ($response->json('code') ?? ''),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => SecretSanitizer::sanitize($e->getMessage())];
        }
    }

    public function delete(PublishTarget $target): bool
    {
        if (! $target->external_id) {
            return false;
        }

        $endpoint = "{$this->apiUrl}/wp-json/wp/v2/posts/{$target->external_id}";

        try {
            $response = Http::withBasicAuth($this->username, $this->appPassword)
                ->withHeaders(['X-HTTP-Method-Override' => 'DELETE'])
                ->connectTimeout(10)
                ->timeout(30)
                ->delete("{$endpoint}?force=true");

            if ($response->successful() || in_array($response->status(), [404, 410], true)) {
                Log::info('WordPress post deleted', ['external_id' => $target->external_id]);

                return true;
            }

            // Fallback to POST with _method=DELETE for hosts/WAFs that block the HTTP DELETE verb
            $fallbackResponse = Http::withBasicAuth($this->username, $this->appPassword)
                ->withHeaders(['X-HTTP-Method-Override' => 'DELETE'])
                ->connectTimeout(10)
                ->timeout(30)
                ->post("{$endpoint}?force=true&_method=DELETE", [
                    'force' => true,
                    '_method' => 'DELETE',
                ]);

            if ($fallbackResponse->successful() || in_array($fallbackResponse->status(), [404, 410], true)) {
                Log::info('WordPress post deleted via POST method override', ['external_id' => $target->external_id]);

                return true;
            }

            Log::error('WordPress Delete Error: '.SecretSanitizer::sanitize($fallbackResponse->body()));

            return false;
        } catch (\Throwable $e) {
            Log::error('WordPress Delete Exception: '.SecretSanitizer::sanitize($e->getMessage()));

            return false;
        }
    }

    public function healthCheck(): bool
    {
        if ($this->apiUrl === '' || $this->username === '' || $this->appPassword === '') {
            return false;
        }

        try {
            $response = Http::withBasicAuth($this->username, $this->appPassword)
                ->connectTimeout(2)
                ->timeout(5)
                ->get("{$this->apiUrl}/wp-json/wp/v2/users/me");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
