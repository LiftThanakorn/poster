<?php

namespace App\Services\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishTarget;
use App\Services\IntegrationSettingsService;
use App\Support\SecretSanitizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FacebookPublisher implements PublisherInterface
{
    protected string $pageId;

    protected string $accessToken;

    protected string $graphVersion;

    protected ?string $resolvedPageAccessToken = null;

    public function __construct(?IntegrationSettingsService $settings = null)
    {
        $facebook = ($settings ?? app(IntegrationSettingsService::class))->facebook();

        $this->pageId = $facebook['page_id'];
        $this->accessToken = $facebook['access_token'];
        $this->graphVersion = $facebook['version'];
    }

    protected function graphUrl(string $path): string
    {
        return "https://graph.facebook.com/{$this->graphVersion}/".ltrim($path, '/');
    }

    protected function pageAccessToken(): string
    {
        if ($this->resolvedPageAccessToken) {
            return $this->resolvedPageAccessToken;
        }

        if ($this->accessToken === '') {
            throw new \RuntimeException('Facebook Access Token is not configured.');
        }

        $meResponse = Http::withToken($this->accessToken)
            ->connectTimeout(15)
            ->timeout(30)
            ->get($this->graphUrl('me'), [
                'fields' => 'id',
            ]);

        if ($meResponse->successful() && (string) $meResponse->json('id') === $this->pageId) {
            return $this->resolvedPageAccessToken = $this->accessToken;
        }

        $accountsResponse = Http::withToken($this->accessToken)
            ->connectTimeout(15)
            ->timeout(30)
            ->get($this->graphUrl('me/accounts'), [
                'fields' => 'id,access_token,tasks',
                'limit' => 100,
            ]);

        if (! $accountsResponse->successful()) {
            throw new \RuntimeException($accountsResponse->json('error.message') ?? 'Unable to load managed Facebook Pages.');
        }

        $page = collect($accountsResponse->json('data', []))->firstWhere('id', $this->pageId);

        if (! $page || empty($page['access_token'])) {
            throw new \RuntimeException('The configured Facebook Page is not available to this access token.');
        }

        return $this->resolvedPageAccessToken = (string) $page['access_token'];
    }

    protected function postGraph(string $endpoint, array $payload): Response
    {
        return Http::withToken($this->pageAccessToken())
            ->asForm()
            ->connectTimeout(15)
            ->timeout(45)
            ->post($endpoint, $payload);
    }

    protected function publishTextPost(?string $message): Response
    {
        $endpoint = $this->graphUrl("{$this->pageId}/feed");

        return $this->postGraph($endpoint, ['message' => $message]);
    }

    protected function postComment(string $postId, string $message): void
    {
        if ($message === '') {
            return;
        }

        $endpoint = $this->graphUrl("{$postId}/comments");

        $response = $this->postGraph($endpoint, compact('message'));

        if (! $response->successful()) {
            Log::warning('Facebook Comment Error (non-fatal): '.SecretSanitizer::sanitize($response->body()));
        }
    }

    protected function uploadPhoto(string $filePath, string $fileName): Response
    {
        $endpoint = $this->graphUrl("{$this->pageId}/photos");

        return Http::withToken($this->pageAccessToken())
            ->connectTimeout(15)
            ->timeout(60)
            ->attach('source', file_get_contents($filePath), $fileName)
            ->post($endpoint, [
                'published' => 'false',
            ]);
    }

    public function publish(Post $post, PublishTarget $target): array
    {
        try {
            $wpTarget = $post->targets()
                ->where('channel', 'wordpress')
                ->where('status', 'published')
                ->first();

            $ancestor = $post;
            for ($depth = 0; ! $wpTarget && $ancestor->replacement_of_post_id && $depth < 10; $depth++) {
                $ancestor = Post::query()->find($ancestor->replacement_of_post_id);
                if (! $ancestor) {
                    break;
                }

                $wpTarget = $ancestor->targets()
                    ->where('channel', 'wordpress')
                    ->where('status', 'published')
                    ->first();
            }

            $wpUrl = $wpTarget?->external_url ?? '';
            $caption = $post->fb_caption;
            $comment = $post->fb_comment;
            $comment2 = $post->fb_comment_2;

            $pdfUrl = '';
            $driveUrl = '';
            if ($post->pdf_media_id) {
                $pdfMedia = $post->pdfMedia ?: PostMedia::find($post->pdf_media_id);
                if ($pdfMedia) {
                    $localPdfUrl = $pdfMedia->file_path ? Storage::disk('public')->url($pdfMedia->file_path) : '';
                    $driveUrl = (string) ($pdfMedia->drive_web_view_link ?? '');
                    $pdfUrl = $driveUrl !== '' ? $driveUrl : $localPdfUrl;
                }
            }

            $appLinksText = '';
            if (! empty($post->action_buttons) && is_array($post->action_buttons)) {
                $lines = [];
                $index = 1;
                foreach ($post->action_buttons as $btn) {
                    $lbl = trim((string) ($btn['label'] ?? ''));
                    $url = trim((string) ($btn['url'] ?? ''));
                    if ($url !== '') {
                        $url = str_replace(
                            ['{drive_url}', '{{drive_url}}', '{pdf_url}', '{{pdf_url}}'],
                            [$driveUrl, $driveUrl, $pdfUrl, $pdfUrl],
                            $url
                        );
                    }
                    if ($lbl !== '' && $url !== '') {
                        $lines[] = "{$index}. {$lbl}: {$url}";
                        $index++;
                    } elseif ($url !== '') {
                        $lines[] = "{$index}. {$url}";
                        $index++;
                    }
                }
                $appLinksText = implode("\n", $lines);
            }

            $caption = $this->resolveVariables($caption, $post, $wpUrl);
            $comment = $this->resolveVariables($comment, $post, $wpUrl);
            $comment2 = $this->resolveVariables($comment2, $post, $wpUrl);

            $response = $this->publishFacebookPost($post, $caption);

            if ($response->successful()) {
                $data = $response->json();
                $facebookPostId = (string) $data['id'];

                if ($comment) {
                    $this->postComment($facebookPostId, $comment);
                }

                if ($comment2) {
                    $this->postComment($facebookPostId, $comment2);
                }

                $parts = explode('_', $facebookPostId);
                $cleanPostId = count($parts) > 1 ? $parts[1] : $facebookPostId;

                return [
                    'success' => true,
                    'external_id' => $facebookPostId,
                    'external_url' => "https://facebook.com/{$this->pageId}/posts/{$cleanPostId}",
                    'error' => null,
                ];
            }

            Log::error('Facebook Publish Error: '.SecretSanitizer::sanitize($response->body()));

            return [
                'success' => false,
                'external_id' => null,
                'external_url' => null,
                'error' => SecretSanitizer::sanitize($response->body()),
                'http_status' => $response->status(),
                'error_code' => (string) ($response->json('error.code') ?? ''),
                'error_subcode' => (string) ($response->json('error.error_subcode') ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::error('Facebook Publish Exception: '.SecretSanitizer::sanitize($e->getMessage()));

            return [
                'success' => false,
                'external_id' => null,
                'external_url' => null,
                'error' => SecretSanitizer::sanitize($e->getMessage()),
            ];
        }
    }

    protected function publishFacebookPost(Post $post, ?string $caption): Response
    {
        if (! $post->featured_image_id) {
            return $this->publishTextPost($caption);
        }

        $media = PostMedia::find($post->featured_image_id);

        if (! $media || ! Storage::disk('public')->exists($media->file_path)) {
            return $this->publishTextPost($caption);
        }

        $filePath = Storage::disk('public')->path($media->file_path);
        $photoResponse = $this->uploadPhoto($filePath, basename($filePath));

        if (! $photoResponse->successful()) {
            Log::error('Facebook Photo Upload Error: '.SecretSanitizer::sanitize($photoResponse->body()));

            return $photoResponse;
        }

        $photoId = (string) $photoResponse->json('id');
        $endpoint = $this->graphUrl("{$this->pageId}/feed");

        $attachedMedia = json_encode(
            ['media_fbid' => $photoId],
            JSON_THROW_ON_ERROR,
        );

        return $this->postGraph($endpoint, [
            'message' => $caption,
            'attached_media[0]' => $attachedMedia,
        ]);
    }

    public function update(Post $post, PublishTarget $target): array
    {
        if (! $target->external_id) {
            return [
                'success' => false,
                'error' => 'No External ID provided',
            ];
        }

        try {
            $wpTarget = $post->targets()->where('channel', 'wordpress')->first();
            $wpUrl = $wpTarget?->external_url ?? '';
            $driveUrl = '';
            $pdfUrl = '';
            if ($post->pdf_media_id) {
                $pdfMedia = $post->pdfMedia ?: PostMedia::find($post->pdf_media_id);
                if ($pdfMedia) {
                    $localPdfUrl = $pdfMedia->file_path ? Storage::disk('public')->url($pdfMedia->file_path) : '';
                    $driveUrl = (string) ($pdfMedia->drive_web_view_link ?? '');
                    $pdfUrl = $driveUrl !== '' ? $driveUrl : $localPdfUrl;
                }
            }
            $caption = str_replace(
                ['{wordpress_url}', '{{wordpress_url}}', '{drive_url}', '{{drive_url}}', '{pdf_url}', '{{pdf_url}}'],
                [$wpUrl, $wpUrl, $driveUrl, $driveUrl, $pdfUrl, $pdfUrl],
                (string) $post->fb_caption
            );

            $endpoint = $this->graphUrl($target->external_id);
            $response = $this->postGraph($endpoint, [
                'message' => $caption,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'external_id' => $target->external_id,
                    'external_url' => $target->external_url,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'error' => SecretSanitizer::sanitize($response->body()),
                'http_status' => $response->status(),
                'error_code' => (string) ($response->json('error.code') ?? ''),
                'error_subcode' => (string) ($response->json('error.error_subcode') ?? ''),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => SecretSanitizer::sanitize($e->getMessage()),
            ];
        }
    }

    public function delete(PublishTarget $target): bool
    {
        if (! $target->external_id) {
            return false;
        }

        try {
            $endpoint = $this->graphUrl($target->external_id);

            $response = Http::withToken($this->pageAccessToken())
                ->connectTimeout(15)
                ->timeout(30)
                ->delete($endpoint);

            if ($response->successful()) {
                Log::info('Facebook post deleted', ['external_id' => $target->external_id]);

                return true;
            }

            Log::error('Facebook Delete Error: '.SecretSanitizer::sanitize($response->body()));

            return false;
        } catch (\Throwable $e) {
            Log::error('Facebook Delete Exception: '.SecretSanitizer::sanitize($e->getMessage()));

            return false;
        }
    }

    public function healthCheck(): bool
    {
        if ($this->pageId === '' || $this->accessToken === '') {
            return false;
        }

        try {
            $endpoint = $this->graphUrl($this->pageId);
            $response = Http::withToken($this->pageAccessToken())
                ->connectTimeout(15)
                ->timeout(30)
                ->get($endpoint, [
                    'fields' => 'id,name',
                ]);

            return $response->successful()
                && (string) $response->json('id') === $this->pageId;
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolveVariables(?string $text, Post $post, string $wpUrl = ''): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $pdfUrl = '';
        $driveUrl = '';
        if ($post->pdf_media_id) {
            $pdfMedia = $post->pdfMedia ?: PostMedia::find($post->pdf_media_id);
            if ($pdfMedia) {
                $localPdfUrl = $pdfMedia->file_path ? Storage::disk('public')->url($pdfMedia->file_path) : '';
                $driveUrl = (string) ($pdfMedia->drive_web_view_link ?? '');
                $pdfUrl = $driveUrl !== '' ? $driveUrl : $localPdfUrl;
            }
        }

        $appLinksText = '';
        if (! empty($post->action_buttons) && is_array($post->action_buttons)) {
            $lines = [];
            $index = 1;
            foreach ($post->action_buttons as $btn) {
                $lbl = trim((string) ($btn['label'] ?? ''));
                $url = trim((string) ($btn['url'] ?? ''));
                if ($url !== '') {
                    $url = str_replace(
                        ['{drive_url}', '{{drive_url}}', '{pdf_url}', '{{pdf_url}}'],
                        [$driveUrl, $driveUrl, $pdfUrl, $pdfUrl],
                        $url
                    );
                }
                if ($lbl !== '' && $url !== '') {
                    $lines[] = "{$index}. {$lbl}: {$url}";
                    $index++;
                } elseif ($url !== '') {
                    $lines[] = "{$index}. {$url}";
                    $index++;
                }
            }
            $appLinksText = implode("\n", $lines);
        }

        $replacements = [
            '{wordpress_url}' => $wpUrl,
            '{{wordpress_url}}' => $wpUrl,
            '{drive_url}' => $driveUrl,
            '{{drive_url}}' => $driveUrl,
            '{pdf_url}' => $pdfUrl,
            '{{pdf_url}}' => $pdfUrl,
            '{application_links}' => $appLinksText,
            '{{application_links}}' => $appLinksText,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
