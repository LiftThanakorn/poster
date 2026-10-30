<?php

namespace App\Services;

use App\Models\FacebookDiagnosticSnapshot;
use App\Models\PublishTarget;
use App\Support\SecretSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class FacebookDiagnosticsService
{
    protected string $pageId;

    protected string $accessToken;

    protected string $graphVersion;

    protected ?string $resolvedPageAccessToken = null;

    /**
     * Cached permission preflight result:
     * - true: read_insights is granted
     * - false: read_insights is known to be missing
     * - null: permissions check could not be inspected (e.g. Page token or error) - fallback to normal requests
     */
    protected ?bool $hasReadInsightsPermission = null;

    protected bool $permissionsChecked = false;

    public function __construct(?IntegrationSettingsService $settings = null)
    {
        $facebook = ($settings ?? app(IntegrationSettingsService::class))->facebook();

        $this->pageId = (string) ($facebook['page_id'] ?? '');
        $this->accessToken = (string) ($facebook['access_token'] ?? '');
        $this->graphVersion = (string) ($facebook['version'] ?? config('services.facebook.version', 'v26.0'));
    }

    protected function graphUrl(string $path): string
    {
        return "https://graph.facebook.com/{$this->graphVersion}/".ltrim($path, '/');
    }

    /**
     * Resolve the Page Access Token defensively from either the token directly or me/accounts.
     */
    public function pageAccessToken(): string
    {
        if ($this->resolvedPageAccessToken) {
            return $this->resolvedPageAccessToken;
        }

        if ($this->accessToken === '') {
            throw new \RuntimeException('Facebook Access Token is not configured.');
        }

        $meResponse = Http::withToken($this->accessToken)
            ->connectTimeout(5)
            ->timeout(10)
            ->get($this->graphUrl('me'), [
                'fields' => 'id',
            ]);

        if ($meResponse->successful() && (string) $meResponse->json('id') === $this->pageId) {
            return $this->resolvedPageAccessToken = $this->accessToken;
        }

        $accountsResponse = Http::withToken($this->accessToken)
            ->connectTimeout(5)
            ->timeout(10)
            ->get($this->graphUrl('me/accounts'), [
                'fields' => 'id,access_token,tasks',
                'limit' => 100,
            ]);

        if (! $accountsResponse->successful()) {
            $errorMessage = $accountsResponse->json('error.message') ?? 'Unable to load managed Facebook Pages.';
            throw new \RuntimeException(SecretSanitizer::sanitize((string) $errorMessage));
        }

        /** @var array<int, array{id?: string, access_token?: string}> $data */
        $data = $accountsResponse->json('data', []);
        $page = collect($data)->firstWhere('id', $this->pageId);

        if (! $page || empty($page['access_token'])) {
            throw new \RuntimeException('The configured Facebook Page is not available to this access token.');
        }

        return $this->resolvedPageAccessToken = (string) $page['access_token'];
    }

    /**
     * Check permissions on the configured token defensively.
     * Returns:
     * - true: permissions readable and 'read_insights' is granted.
     * - false: permissions readable and 'read_insights' is missing/not granted.
     * - null: permissions endpoint could not be inspected (e.g. Page token, error, or empty token) -> fallback to trying normally.
     */
    public function checkReadInsightsPermission(): ?bool
    {
        if ($this->permissionsChecked) {
            return $this->hasReadInsightsPermission;
        }

        $this->permissionsChecked = true;

        if ($this->accessToken === '') {
            return $this->hasReadInsightsPermission = null;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->connectTimeout(5)
                ->timeout(10)
                ->get($this->graphUrl('me/permissions'));

            if (! $response->successful()) {
                // If /me/permissions cannot be inspected (e.g. Page token, code 100/invalid endpoint, etc.), fallback to trying normally.
                return $this->hasReadInsightsPermission = null;
            }

            /** @var array<int, array{permission?: string, status?: string}> $data */
            $data = $response->json('data');
            if (! is_array($data)) {
                return $this->hasReadInsightsPermission = null;
            }

            $insightsPerm = collect($data)->firstWhere('permission', 'read_insights');
            if ($insightsPerm && ($insightsPerm['status'] ?? '') === 'granted') {
                return $this->hasReadInsightsPermission = true;
            }

            return $this->hasReadInsightsPermission = false;
        } catch (\Throwable) {
            return $this->hasReadInsightsPermission = null;
        }
    }

    /**
     * Query post metadata for the given Facebook external post ID.
     *
     * @return array{data: ?array<string, mixed>, error: ?string, status_code: ?int}
     */
    public function fetchPostMetadata(string $externalId): array
    {
        try {
            $token = $this->pageAccessToken();
            $fields = implode(',', [
                'id',
                'created_time',
                'message',
                'status_type',
                'shares',
                'permalink_url',
                'attachments{media_type,unshimmed_url,target,type,title,url,subattachments}',
                'reactions.summary(total_count).limit(0)',
                'comments.summary(total_count).limit(0)',
            ]);

            $response = Http::withToken($token)
                ->connectTimeout(5)
                ->timeout(15)
                ->get($this->graphUrl($externalId), [
                    'fields' => $fields,
                ]);

            if ($response->successful()) {
                /** @var array<string, mixed> $json */
                $json = $response->json() ?? [];

                return [
                    'data' => SecretSanitizer::sanitizeArray($json),
                    'error' => null,
                    'status_code' => $response->status(),
                ];
            }

            $errorMessage = $response->json('error.message') ?? $response->body();

            return [
                'data' => null,
                'error' => SecretSanitizer::sanitize((string) $errorMessage),
                'status_code' => $response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'data' => null,
                'error' => SecretSanitizer::sanitize($e->getMessage()),
                'status_code' => null,
            ];
        }
    }

    /**
     * Supported Graph API v26+ candidate insight metrics.
     *
     * @return list<string>
     */
    protected function insightMetricCandidates(): array
    {
        return [
            'post_media_view',
            'post_total_media_view_unique',
        ];
    }

    /**
     * Query post insights defensively and isolate metric failures.
     *
     * @return array{
     *     data: ?array<string, mixed>,
     *     error: ?string,
     *     status_code: ?int,
     *     metrics: array<string, array{data: ?mixed, error: ?string, status_code: ?int}>,
     *     has_success: bool
     * }
     */
    public function fetchPostInsights(string $externalId): array
    {
        if ($this->checkReadInsightsPermission() === false) {
            $missingPermError = 'Missing required permission: read_insights';

            return [
                'data' => [
                    'data' => null,
                    'metrics' => [],
                    'errors' => [$missingPermError],
                    'skipped' => true,
                ],
                'error' => $missingPermError,
                'status_code' => 403,
                'metrics' => [],
                'has_success' => false,
            ];
        }

        try {
            $token = $this->pageAccessToken();
        } catch (\Throwable $e) {
            $sanitizedError = SecretSanitizer::sanitize($e->getMessage());

            return [
                'data' => null,
                'error' => $sanitizedError,
                'status_code' => null,
                'metrics' => [],
                'has_success' => false,
            ];
        }

        $candidates = $this->insightMetricCandidates();
        $metricResults = [];
        $mergedData = [];
        $errors = [];
        $hasSuccess = false;

        foreach ($candidates as $metricName) {
            try {
                $response = Http::withToken($token)
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->get($this->graphUrl("{$externalId}/insights"), [
                        'metric' => $metricName,
                        'period' => 'lifetime',
                    ]);

                if ($response->successful()) {
                    $json = $response->json() ?? [];
                    $dataItems = $json['data'] ?? [];

                    $metricResults[$metricName] = [
                        'data' => SecretSanitizer::sanitizeArray($json),
                        'error' => null,
                        'status_code' => $response->status(),
                    ];

                    if (! empty($dataItems) && is_array($dataItems)) {
                        foreach ($dataItems as $item) {
                            $mergedData[] = $item;
                        }

                        $hasSuccess = true;
                    } else {
                        $errors[] = "{$metricName}: no data returned";
                    }
                } else {
                    $err = $response->json('error.message') ?? $response->body();
                    $sanitizedError = SecretSanitizer::sanitize((string) $err);

                    $metricResults[$metricName] = [
                        'data' => null,
                        'error' => $sanitizedError,
                        'status_code' => $response->status(),
                    ];

                    $errors[] = "{$metricName}: {$sanitizedError}";
                }
            } catch (\Throwable $e) {
                $sanitizedError = SecretSanitizer::sanitize($e->getMessage());

                $metricResults[$metricName] = [
                    'data' => null,
                    'error' => $sanitizedError,
                    'status_code' => null,
                ];

                $errors[] = "{$metricName}: {$sanitizedError}";
            }
        }

        $payload = [
            'data' => ! empty($mergedData) ? SecretSanitizer::sanitizeArray($mergedData) : null,
            'metrics' => $metricResults,
            'errors' => $errors,
        ];

        return [
            'data' => $payload,
            'error' => ! empty($errors) ? implode(' | ', $errors) : null,
            'status_code' => $hasSuccess ? 200 : (! empty($errors) ? 400 : null),
            'metrics' => $metricResults,
            'has_success' => $hasSuccess,
        ];
    }

    /**
     * Defensive normalization of metadata and insights into standard comparable metrics.
     *
     * @param  ?array<string, mixed>  $metadata
     * @param  ?array<string, mixed>  $insights
     * @return array<string, mixed>
     */
    public function normalizeMetrics(?array $metadata, ?array $insights): array
    {
        $normalized = [
            'views' => null,
            'unique_viewers' => null,
            'engaged_users' => null,
            'clicks' => null,
            'shares' => null,
            'comments_count' => null,
            'reactions_breakdown' => null,
            'reactions_count' => null,
            'status_type' => $metadata['status_type'] ?? null,
            'has_attachments' => ! empty($metadata['attachments']['data'] ?? []),
            'attachment_type' => $metadata['attachments']['data'][0]['type'] ?? null,
        ];

        if (is_array($metadata)) {
            if (isset($metadata['shares']['count'])) {
                $normalized['shares'] = (int) $metadata['shares']['count'];
            }

            if (isset($metadata['reactions']['summary']['total_count'])) {
                $normalized['reactions_count'] = (int) $metadata['reactions']['summary']['total_count'];
            }

            if (isset($metadata['comments']['summary']['total_count'])) {
                $normalized['comments_count'] = (int) $metadata['comments']['summary']['total_count'];
            }
        }

        $insightsData = [];
        if (is_array($insights)) {
            if (isset($insights['data']) && is_array($insights['data'])) {
                $insightsData = $insights['data'];
            } elseif (isset($insights[0]) && is_array($insights[0])) {
                $insightsData = $insights;
            }
        }

        if (! empty($insightsData)) {
            /** @var array<int, array{name?: string, values?: array<int, array{value?: mixed}>}> $insightsData */
            foreach ($insightsData as $metricItem) {
                if (! is_array($metricItem)) {
                    continue;
                }

                $name = $metricItem['name'] ?? '';
                $latestValue = $metricItem['values'][0]['value'] ?? null;

                match ($name) {
                    'post_media_view' => $normalized['views'] = is_numeric($latestValue) ? (int) $latestValue : $latestValue,
                    'post_total_media_view_unique' => $normalized['unique_viewers'] = is_numeric($latestValue) ? (int) $latestValue : $latestValue,
                    default => null,
                };
            }
        }

        return $normalized;
    }

    /**
     * Collect diagnostics for a specific target, saving a snapshot record.
     */
    public function collectForTarget(PublishTarget $target): FacebookDiagnosticSnapshot
    {
        $externalId = (string) $target->external_id;

        if ($externalId === '') {
            return FacebookDiagnosticSnapshot::create([
                'publish_target_id' => $target->id,
                'external_id' => '',
                'collected_at' => Carbon::now(),
                'status' => 'failed',
                'post_metadata' => null,
                'insights_payload' => null,
                'normalized_metrics' => null,
                'error' => 'PublishTarget has no external_id.',
            ]);
        }

        $metaResult = $this->fetchPostMetadata($externalId);
        $insightsResult = $this->fetchPostInsights($externalId);

        $hasMeta = ! empty($metaResult['data']);
        $hasInsights = ! empty($insightsResult['has_success']);

        $errors = array_values(array_filter([
            $metaResult['error'] ? 'Metadata error: '.$metaResult['error'] : null,
            $insightsResult['error'] ? 'Insights error: '.$insightsResult['error'] : null,
        ]));

        $status = 'success';
        if (! $hasMeta && ! $hasInsights) {
            $status = 'failed';
        } elseif (! $hasMeta || ! $hasInsights || ! empty($errors)) {
            $status = 'partial';
        }

        $normalized = $this->normalizeMetrics($metaResult['data'], $insightsResult['data']);

        return FacebookDiagnosticSnapshot::create([
            'publish_target_id' => $target->id,
            'external_id' => $externalId,
            'collected_at' => Carbon::now(),
            'status' => $status,
            'post_metadata' => $metaResult['data'],
            'insights_payload' => $insightsResult['data'],
            'normalized_metrics' => $normalized,
            'error' => ! empty($errors) ? implode(' | ', $errors) : null,
        ]);
    }
}
