<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Metrics\YouTubePublicationMetricsCollector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function youtubeShortPublication(): AnalyticsPublication
{
    $account = SocialAccount::factory()->youtube()->create();

    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'short-1',
        'content_type' => PublicationContentType::Short,
    ]);
}

function fakeYouTubeWithRetention(array $retentionResponse, int $retentionStatus = 200): void
{
    $analyticsApi = config('trypost.platforms.youtube.analytics_api');
    $dataApi = config('trypost.platforms.youtube.data_api');

    Http::fake(function (Request $request) use ($analyticsApi, $dataApi, $retentionResponse, $retentionStatus) {
        if (str_starts_with($request->url(), "{$analyticsApi}/reports")) {
            if (($request['dimensions'] ?? null) === 'elapsedVideoTimeRatio') {
                return Http::response($retentionResponse, $retentionStatus);
            }

            return Http::response([
                'columnHeaders' => [['name' => 'views'], ['name' => 'averageViewPercentage']],
                'rows' => [[1010, 31.4]],
            ]);
        }

        if (str_starts_with($request->url(), "{$dataApi}/videos")) {
            return Http::response(['items' => [[
                'id' => 'short-1',
                'statistics' => ['viewCount' => '1144', 'likeCount' => '26', 'commentCount' => '0'],
            ]]]);
        }

        return Http::response([], 404);
    });
}

test('youtube reads the audience retention curve of a short into provider metadata', function () {
    fakeYouTubeWithRetention([
        'columnHeaders' => [
            ['name' => 'elapsedVideoTimeRatio', 'columnType' => 'DIMENSION'],
            ['name' => 'audienceWatchRatio', 'columnType' => 'METRIC'],
            ['name' => 'relativeRetentionPerformance', 'columnType' => 'METRIC'],
        ],
        'rows' => [[0.02, 0.81, 0.44], [0.01, 1.02, 0.5], [0.03, '0.7', 0.41]],
    ]);

    $observation = app(YouTubePublicationMetricsCollector::class)
        ->collect(youtubeShortPublication(), CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect($observation->providerMetadata)->toBe(['retention' => [
        'as_of' => '2026-09-23',
        'points' => [
            ['at' => 0.01, 'watch_ratio' => 1.02, 'relative_performance' => 0.5],
            ['at' => 0.02, 'watch_ratio' => 0.81, 'relative_performance' => 0.44],
            ['at' => 0.03, 'watch_ratio' => 0.7, 'relative_performance' => 0.41],
        ],
    ]]);

    Http::assertSent(fn (Request $request): bool => ($request['dimensions'] ?? null) === 'elapsedVideoTimeRatio'
        && $request['metrics'] === 'audienceWatchRatio,relativeRetentionPerformance'
        && $request['filters'] === 'video==short-1');
});

test('a youtube video without a processed retention report keeps its counts and no curve', function () {
    fakeYouTubeWithRetention(['columnHeaders' => [
        ['name' => 'elapsedVideoTimeRatio'], ['name' => 'audienceWatchRatio'],
    ], 'rows' => []]);

    $observation = app(YouTubePublicationMetricsCollector::class)
        ->collect(youtubeShortPublication(), CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect($observation->providerMetadata)->toBeNull()
        ->and(collect($observation->metrics)->pluck('value', 'key.value')->get('views'))->toBe(1144);
});

test('a refused youtube retention report never blocks the counts', function () {
    fakeYouTubeWithRetention(['error' => ['code' => 403, 'message' => 'Forbidden']], 403);

    $observation = app(YouTubePublicationMetricsCollector::class)
        ->collect(youtubeShortPublication(), CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect($observation->providerMetadata)->toBeNull()
        ->and(collect($observation->metrics)->pluck('value', 'key.value')->get('views'))->toBe(1144);
});

test('the retention curve parser skips bad rows and needs its two columns', function () {
    $date = CarbonImmutable::parse('2026-09-23', 'UTC');

    expect(YouTubePublicationMetricsCollector::retentionCurve([['name' => 'views']], [[1]], $date))->toBeNull()
        ->and(YouTubePublicationMetricsCollector::retentionCurve(
            [['name' => 'elapsedVideoTimeRatio'], ['name' => 'audienceWatchRatio']],
            [[0.5, 0.4], ['x', 0.3], [0.6]],
            $date,
        ))->toBe(['as_of' => '2026-09-23', 'points' => [['at' => 0.5, 'watch_ratio' => 0.4, 'relative_performance' => null]]]);
});
