<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricUnit;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\SyncCadence;
use App\Support\Analytics\YouTubeScopes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Lifetime counts come from the Data API `statistics` part, which is current;
 * the Analytics API lags one to three days and adds what only it reports
 * (shares, watch time, retention, playlist saves, subscribers).
 */
class YouTubePublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    private const int STATISTICS_BATCH_SIZE = 50;

    private const int STATISTICS_CACHE_HOURS = 6;

    private const array ANALYTICS_METRICS = [
        'views', 'engagedViews', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage',
        'likes', 'comments', 'shares', 'videosAddedToPlaylists', 'subscribersGained', 'subscribersLost',
    ];

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, YouTubeScopes::READ);
        $statistics = $this->statistics($account, $publication, $date);
        $report = $this->report($account, $publication, $date);
        $retention = $this->retention($account, $publication, $date);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $statistics, 'viewCount') ?? $this->count(MetricKey::Views, $report, 'views'),
            $this->count(MetricKey::Reactions, $statistics, 'likeCount') ?? $this->count(MetricKey::Reactions, $report, 'likes'),
            $this->count(MetricKey::Comments, $statistics, 'commentCount') ?? $this->count(MetricKey::Comments, $report, 'comments'),
            $this->count(MetricKey::EngagedViews, $report, 'engagedViews'),
            $this->decimal(MetricKey::WatchTimeMilliseconds, $report, 'estimatedMinutesWatched', MetricUnit::Milliseconds, 60000),
            $this->decimal(MetricKey::AverageWatchTimeMilliseconds, $report, 'averageViewDuration', MetricUnit::Milliseconds, 1000),
            $this->decimal(MetricKey::AveragePercentageViewed, $report, 'averageViewPercentage', MetricUnit::Percent),
            $this->count(MetricKey::Shares, $report, 'shares'),
            $this->count(MetricKey::Saves, $report, 'videosAddedToPlaylists'),
            $this->count(MetricKey::SubscribersGained, $report, 'subscribersGained'),
            $this->count(MetricKey::SubscribersLost, $report, 'subscribersLost'),
        ])), $retention === null ? null : ['retention' => $retention]);
    }

    /**
     * The audience retention curve: for each point of the video (elapsed share of its
     * length, 0.01 to 1.0), the share of views still watching (`watch_ratio`, can pass 1
     * where people rewatch) and how that compares with similar videos
     * (`relative_performance`, 0 to 1, 0.5 = typical). Null until YouTube has processed
     * the video, when the channel refuses the report, or when the read fails for a
     * reason other than rate limits, so a missing curve never blocks the counts.
     *
     * @see https://developers.google.com/youtube/analytics/channel_reports#audience-retention-reports
     *
     * @return array{as_of: string, points: list<array{at: float, watch_ratio: float, relative_performance: float|null}>}|null
     */
    private function retention(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): ?array
    {
        if ($account->missingScope(YouTubeScopes::ANALYTICS) !== null) {
            return null;
        }

        try {
            $response = $this->get($account,
                config('trypost.platforms.youtube.analytics_api').'/reports',
                [
                    'ids' => 'channel==MINE',
                    'startDate' => $publication->provider_published_at->toDateString(),
                    'endDate' => $date->toDateString(),
                    'dimensions' => 'elapsedVideoTimeRatio',
                    'metrics' => 'audienceWatchRatio,relativeRetentionPerformance',
                    'filters' => "video=={$publication->remote_id}",
                    'sort' => 'elapsedVideoTimeRatio',
                ],
            );
        } catch (AnalyticsCollectionException $exception) {
            if ($exception->category === 'rate_limited') {
                throw $exception;
            }

            return null;
        }

        return self::retentionCurve(
            (array) $response->json('columnHeaders', []),
            (array) $response->json('rows', []),
            $date,
        );
    }

    /**
     * @param  array<int, mixed>  $headers
     * @param  array<int, mixed>  $rows
     * @return array{as_of: string, points: list<array{at: float, watch_ratio: float, relative_performance: float|null}>}|null
     */
    public static function retentionCurve(array $headers, array $rows, CarbonImmutable $date): ?array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $name = data_get($header, 'name');

            if (is_string($name)) {
                $columns[$name] = $index;
            }
        }

        if (! isset($columns['elapsedVideoTimeRatio'], $columns['audienceWatchRatio'])) {
            return null;
        }

        $points = [];

        foreach ($rows as $row) {
            $at = data_get($row, $columns['elapsedVideoTimeRatio']);
            $watch = data_get($row, $columns['audienceWatchRatio']);

            if (! is_numeric($at) || ! is_numeric($watch)) {
                continue;
            }

            $relative = isset($columns['relativeRetentionPerformance'])
                ? data_get($row, $columns['relativeRetentionPerformance'])
                : null;

            $points[] = [
                'at' => round((float) $at, 4),
                'watch_ratio' => round((float) $watch, 4),
                'relative_performance' => is_numeric($relative) ? round((float) $relative, 4) : null,
            ];
        }

        if ($points === []) {
            return null;
        }

        usort($points, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return ['as_of' => $date->toDateString(), 'points' => $points];
    }

    /**
     * Reads `statistics` for this video together with up to 49 other videos of the channel
     * still due on the same day, so the daily pass spends one quota unit per 50 videos.
     * Only a cached statistics array is reused. A `false` left by an older batch is read
     * again, so a video is reported deleted by its own `videos.list` only.
     *
     * @return array<string, mixed>
     */
    private function statistics(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): array
    {
        $key = $this->statisticsCacheKey($account, $date, $publication->remote_id);
        $cached = Cache::get($key);

        $statistics = is_array($cached)
            ? $cached
            : $this->prefetchStatistics($account, $publication, $date);

        if (! is_array($statistics)) {
            throw AnalyticsCollectionException::deletedOnNetwork('YouTube no longer returns this video.');
        }

        return $statistics;
    }

    /**
     * @return array<string, mixed>|false this video's statistics, false when YouTube no longer returns it
     */
    private function prefetchStatistics(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): array|false
    {
        $ids = AnalyticsPublication::query()
            ->available()
            ->where('social_account_id', $account->id)
            ->where('platform', Platform::YouTube)
            ->whereKeyNot($publication->id)
            ->where(fn (Builder $query) => $query
                ->where('provider_published_at', '>=', SyncCadence::metricsWindowStart(Platform::YouTube, $date))
                ->orWhereDoesntHave('dailySnapshots'))
            ->whereDoesntHave('dailySnapshots', fn (Builder $query) => $query->where('date', $date->toDateString()))
            ->latest('provider_published_at')
            ->limit(self::STATISTICS_BATCH_SIZE - 1)
            ->pluck('remote_id')
            ->prepend($publication->remote_id)
            ->unique()
            ->values();

        $videos = collect((array) $this->get($account,
            config('trypost.platforms.youtube.data_api').'/videos',
            ['part' => 'statistics', 'id' => $ids->implode(',')],
        )->json('items', []))->filter(fn (mixed $video): bool => is_array($video))->keyBy('id');

        $statistics = $ids->mapWithKeys(fn (string $id): array => [
            $id => transform($videos->get($id), fn (array $video): array => (array) data_get($video, 'statistics', []), false),
        ]);

        foreach ($statistics->filter(fn (array|false $videoStatistics): bool => is_array($videoStatistics)) as $id => $videoStatistics) {
            Cache::put($this->statisticsCacheKey($account, $date, (string) $id), $videoStatistics, now()->addHours(self::STATISTICS_CACHE_HOURS));
        }

        return $statistics->get($publication->remote_id, false);
    }

    private function statisticsCacheKey(SocialAccount $account, CarbonImmutable $date, string $videoId): string
    {
        return "analytics:youtube-statistics:{$account->id}:{$date->toDateString()}:{$videoId}";
    }

    /**
     * The Analytics row keyed by metric name; empty until YouTube has
     * processed the video, or when the channel refuses the report.
     *
     * @return array<string, mixed>
     */
    private function report(SocialAccount $account, AnalyticsPublication $publication, CarbonImmutable $date): array
    {
        if ($account->missingScope(YouTubeScopes::ANALYTICS) !== null) {
            return [];
        }

        try {
            $response = $this->get($account,
                config('trypost.platforms.youtube.analytics_api').'/reports',
                [
                    'ids' => 'channel==MINE',
                    'startDate' => $publication->provider_published_at->toDateString(),
                    'endDate' => $date->toDateString(),
                    'metrics' => implode(',', self::ANALYTICS_METRICS),
                    'filters' => "video=={$publication->remote_id}",
                ],
            );
        } catch (AnalyticsCollectionException $exception) {
            if (! in_array($exception->category, ['permission', 'malformed'], true)) {
                throw $exception;
            }

            return [];
        }

        $row = $response->json('rows.0');

        if (! is_array($row)) {
            return [];
        }

        $values = [];

        foreach ((array) $response->json('columnHeaders', []) as $index => $header) {
            $name = data_get($header, 'name');

            if (is_string($name) && array_key_exists($index, $row)) {
                $values[$name] = $row[$index];
            }
        }

        return $values;
    }
}
