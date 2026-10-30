<?php

namespace Tests\Feature;

use App\Models\FacebookDiagnosticSnapshot;
use App\Models\IntegrationSetting;
use App\Models\Post;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\FacebookDiagnosticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        IntegrationSetting::query()->create([
            'channel' => 'facebook',
            'settings' => ['page_id' => '10001', 'version' => 'v26.0'],
            'encrypted_credentials' => ['access_token' => 'EAABsecretDiagnosticsToken12345'],
            'status' => 'connected',
        ]);
    }

    protected function createFacebookTarget(string $externalId = '10001_12345', string $status = 'published'): PublishTarget
    {
        $post = Post::create([
            'title' => 'Test Post '.uniqid(),
            'slug' => 'test-post-'.uniqid(),
            'wp_content' => '<p>Article body</p>',
            'fb_caption' => 'Facebook caption',
            'fb_comment' => null,
            'status' => 'published',
            'author_id' => $this->user->id,
            'published_at' => Carbon::now(),
        ]);

        return PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => $status,
            'external_id' => $externalId,
            'external_url' => "https://facebook.com/{$externalId}",
            'error_message' => null,
            'retry_count' => 0,
        ]);
    }

    public function test_service_fetches_post_metadata_and_insights_and_creates_snapshot(): void
    {
        $target = $this->createFacebookTarget('10001_9999');

        Http::fake([
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'read_insights', 'status' => 'granted'],
                    ['permission' => 'pages_show_list', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/10001_9999/insights?metric=post_media_view*' => Http::response([
                'data' => [
                    [
                        'name' => 'post_media_view',
                        'period' => 'lifetime',
                        'values' => [['value' => 1250]],
                        'title' => 'Post Media Views',
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/10001_9999/insights?metric=post_total_media_view_unique*' => Http::response([
                'data' => [
                    [
                        'name' => 'post_total_media_view_unique',
                        'period' => 'lifetime',
                        'values' => [['value' => 980]],
                        'title' => 'Post Total Media Views Unique',
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/10001_9999*' => Http::response([
                'id' => '10001_9999',
                'created_time' => '2026-10-07T02:00:00+0000',
                'message' => 'Hello test post',
                'status_type' => 'added_photos',
                'shares' => ['count' => 12],
                'reactions' => ['summary' => ['total_count' => 35]],
                'comments' => ['summary' => ['total_count' => 8]],
                'permalink_url' => 'https://www.facebook.com/10001/posts/9999',
                'attachments' => [
                    'data' => [
                        [
                            'type' => 'photo',
                            'media_type' => 'photo',
                            'title' => 'Photo title',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $snapshot = $service->collectForTarget($target);

        $this->assertInstanceOf(FacebookDiagnosticSnapshot::class, $snapshot);
        $this->assertSame($target->id, $snapshot->publish_target_id);
        $this->assertSame('10001_9999', $snapshot->external_id);
        $this->assertSame('success', $snapshot->status);
        $this->assertNull($snapshot->error);

        // Normalized metrics assertions: v26 safe keys (views, unique_viewers)
        $metrics = $snapshot->normalized_metrics;
        $this->assertSame(1250, $metrics['views']);
        $this->assertSame(980, $metrics['unique_viewers']);
        $this->assertNull($metrics['engaged_users']);
        $this->assertNull($metrics['clicks']);
        $this->assertSame(12, $metrics['shares']);
        $this->assertSame(8, $metrics['comments_count']);
        $this->assertSame(35, $metrics['reactions_count']);
        $this->assertSame('added_photos', $metrics['status_type']);
        $this->assertSame('photo', $metrics['attachment_type']);
        $this->assertTrue($metrics['has_attachments']);

        // Check relationship from PublishTarget
        $this->assertCount(1, $target->facebookDiagnosticSnapshots);
    }

    public function test_service_isolates_metric_failures_so_one_failing_metric_does_not_break_payload(): void
    {
        $target = $this->createFacebookTarget('10001_isolated_test');

        Http::fake([
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'read_insights', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            // post_media_view succeeds
            'https://graph.facebook.com/v26.0/10001_isolated_test/insights?metric=post_media_view*' => Http::response([
                'data' => [
                    [
                        'name' => 'post_media_view',
                        'period' => 'lifetime',
                        'values' => [['value' => 500]],
                    ],
                ],
            ], 200),
            // post_total_media_view_unique fails with deprecated/unsupported metric error
            'https://graph.facebook.com/v26.0/10001_isolated_test/insights?metric=post_total_media_view_unique*' => Http::response([
                'error' => [
                    'message' => 'Metric post_total_media_view_unique is not available for this post type with token EAABsecretDiagnosticsToken12345',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400),
            'https://graph.facebook.com/v26.0/10001_isolated_test*' => Http::response([
                'id' => '10001_isolated_test',
                'message' => 'Post with isolated metrics',
            ], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $snapshot = $service->collectForTarget($target);

        // One metric failed while others succeeded -> overall status is partial
        $this->assertSame('partial', $snapshot->status);
        $this->assertNotNull($snapshot->error);
        $this->assertStringContainsString('post_total_media_view_unique', $snapshot->error);
        $this->assertStringNotContainsString('EAABsecretDiagnosticsToken12345', $snapshot->error);

        // Successful metric is preserved in payload and normalized metrics
        $this->assertNotNull($snapshot->insights_payload);
        $this->assertSame(500, $snapshot->normalized_metrics['views']);
        $this->assertNull($snapshot->normalized_metrics['unique_viewers']);

        // Raw per-metric responses and errors are preserved
        $metricsPayload = $snapshot->insights_payload['metrics'];
        $this->assertNotNull($metricsPayload['post_media_view']['data']);
        $this->assertNull($metricsPayload['post_media_view']['error']);
        $this->assertNull($metricsPayload['post_total_media_view_unique']['data']);
        $this->assertNotNull($metricsPayload['post_total_media_view_unique']['error']);
        $this->assertSame(400, $metricsPayload['post_total_media_view_unique']['status_code']);
    }

    public function test_service_handles_permission_error_gracefully_and_redacts_tokens(): void
    {
        $target = $this->createFacebookTarget('10001_8888');

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/10001_8888/insights*' => Http::response([
                'error' => [
                    'message' => 'Unsupported get request. Object with ID 10001_8888 does not support insights with token EAABsecretDiagnosticsToken12345',
                    'type' => 'GraphMethodException',
                    'code' => 100,
                ],
            ], 400),
            'https://graph.facebook.com/v26.0/10001_8888*' => Http::response([
                'id' => '10001_8888',
                'message' => 'Post without insights',
                'status_type' => 'mobile_status_update',
            ], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $snapshot = $service->collectForTarget($target);

        $this->assertSame('partial', $snapshot->status);
        $this->assertNotNull($snapshot->error);
        $this->assertStringContainsString('Insights error', $snapshot->error);
        $this->assertStringNotContainsString('EAABsecretDiagnosticsToken12345', $snapshot->error);
        $this->assertNotNull($snapshot->post_metadata);
        $this->assertNull($snapshot->insights_payload['data']);
    }

    public function test_service_handles_all_calls_failing(): void
    {
        $target = $this->createFacebookTarget('10001_7777');

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/10001_7777*' => Http::response([
                'error' => [
                    'message' => 'Post not found or token has expired',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 404),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $snapshot = $service->collectForTarget($target);

        $this->assertSame('failed', $snapshot->status);
        $this->assertNotNull($snapshot->error);
        $this->assertNull($snapshot->post_metadata);
        $this->assertNull($snapshot->insights_payload['data']);
        $this->assertFalse(empty($snapshot->insights_payload['errors']));
    }

    public function test_artisan_command_collects_diagnostics_and_respects_limit(): void
    {
        $target1 = $this->createFacebookTarget('10001_001');
        $target2 = $this->createFacebookTarget('10001_002');
        $target3 = $this->createFacebookTarget('10001_003');

        // Unrelated target (not facebook or not published)
        $post = Post::create([
            'title' => 'Draft',
            'slug' => 'draft',
            'wp_content' => 'x',
            'status' => 'draft',
            'author_id' => $this->user->id,
        ]);
        PublishTarget::create([
            'post_id' => $post->id,
            'channel' => 'facebook',
            'status' => 'pending',
            'external_id' => '10001_pending',
        ]);

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/*/insights*' => Http::response(['data' => []], 200),
            'https://graph.facebook.com/v26.0/*' => Http::response([
                'id' => 'mock_id',
                'message' => 'test',
            ], 200),
        ]);

        // Run with limit=2
        $exitCode = Artisan::call('facebook:collect-diagnostics', ['--limit' => 2]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Found 2 Facebook target(s) to inspect', $output);
        $this->assertStringContainsString('Total Examined', $output);

        // Assert 2 snapshots created (since limit=2)
        $this->assertSame(2, FacebookDiagnosticSnapshot::count());
    }

    public function test_tokens_are_passed_via_authorization_header_not_in_query_string(): void
    {
        $target = $this->createFacebookTarget('10001_header_test');

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/*' => Http::response(['id' => 'mock_id'], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $service->collectForTarget($target);

        Http::assertSent(function (Request $request) {
            $this->assertStringNotContainsString('access_token=', $request->url());
            $this->assertTrue($request->hasHeader('Authorization'));

            return true;
        });
    }

    public function test_multiple_snapshots_can_be_saved_for_same_target_over_time(): void
    {
        $target = $this->createFacebookTarget('10001_multiple_time_test');

        Http::fake([
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/*' => Http::response(['id' => '10001_multiple_time_test'], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);

        // First snapshot at T0
        $snapshot1 = $service->collectForTarget($target);

        // Second snapshot at T1
        $snapshot2 = $service->collectForTarget($target);

        $this->assertNotSame($snapshot1->id, $snapshot2->id);
        $this->assertSame(2, $target->facebookDiagnosticSnapshots()->count());
    }

    public function test_service_skips_insights_when_read_insights_permission_is_missing_and_stores_metadata(): void
    {
        $target = $this->createFacebookTarget('10001_no_insights_perm');

        Http::fake([
            // /me/permissions readable but does not include read_insights
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'pages_show_list', 'status' => 'granted'],
                    ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                    ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/10001_no_insights_perm*' => Http::response([
                'id' => '10001_no_insights_perm',
                'message' => 'Post without read_insights permission',
                'status_type' => 'status',
                'shares' => ['count' => 5],
            ], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $snapshot = $service->collectForTarget($target);

        // Snapshot is created with status=partial
        $this->assertInstanceOf(FacebookDiagnosticSnapshot::class, $snapshot);
        $this->assertSame('partial', $snapshot->status);
        $this->assertNotNull($snapshot->error);
        $this->assertStringContainsString('read_insights', $snapshot->error);

        // Metadata is still fetched and stored
        $this->assertNotNull($snapshot->post_metadata);
        $this->assertSame('10001_no_insights_perm', $snapshot->post_metadata['id']);
        $this->assertSame(5, $snapshot->normalized_metrics['shares']);

        // Insights payload contains skipped indicators
        $this->assertNotNull($snapshot->insights_payload);
        $this->assertTrue($snapshot->insights_payload['skipped']);

        // Insights endpoints were NEVER called
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/insights');
        });
    }

    public function test_permission_check_runs_only_once_per_service_instance(): void
    {
        $target1 = $this->createFacebookTarget('10001_p1');
        $target2 = $this->createFacebookTarget('10001_p2');

        Http::fake([
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'data' => [
                    ['permission' => 'read_insights', 'status' => 'granted'],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            'https://graph.facebook.com/v26.0/*/insights*' => Http::response(['data' => []], 200),
            'https://graph.facebook.com/v26.0/*' => Http::response(['id' => 'mock_id'], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $service->collectForTarget($target1);
        $service->collectForTarget($target2);

        // me/permissions must only be requested once across multiple collect calls on same instance
        $permissionRequests = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'me/permissions'),
        );
        $this->assertCount(1, $permissionRequests);
    }

    public function test_falls_back_to_trying_insights_when_me_permissions_cannot_be_inspected(): void
    {
        $target = $this->createFacebookTarget('10001_page_token_fallback');

        Http::fake([
            // /me/permissions errors out (e.g. page token or API error)
            'https://graph.facebook.com/v26.0/me/permissions*' => Http::response([
                'error' => [
                    'message' => 'Unknown path components: /permissions',
                    'type' => 'OAuthException',
                    'code' => 2500,
                ],
            ], 400),
            'https://graph.facebook.com/v26.0/me*' => Http::response(['id' => '10001'], 200),
            // Insights should still be attempted normally
            'https://graph.facebook.com/v26.0/10001_page_token_fallback/insights?metric=post_media_view*' => Http::response([
                'data' => [
                    [
                        'name' => 'post_media_view',
                        'period' => 'lifetime',
                        'values' => [['value' => 300]],
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v26.0/10001_page_token_fallback/insights?metric=post_total_media_view_unique*' => Http::response([
                'data' => [],
            ], 200),
            'https://graph.facebook.com/v26.0/10001_page_token_fallback*' => Http::response([
                'id' => '10001_page_token_fallback',
                'message' => 'Fallback test',
            ], 200),
        ]);

        $service = app(FacebookDiagnosticsService::class);
        $snapshot = $service->collectForTarget($target);

        $this->assertSame('partial', $snapshot->status);
        $this->assertStringContainsString('post_total_media_view_unique: no data returned', (string) $snapshot->error);
        $this->assertSame(300, $snapshot->normalized_metrics['views']);

        // Verify that insights requests were indeed attempted
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/insights');
        });
    }
}
