<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\TikTokPublisher;
use App\Support\PostPlatformMetaRules;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    $this->socialAccount = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => 'tiktok123',
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDays(1),
    ]);

    $this->post = Post::factory()->forAccount($this->socialAccount)->tiktok()->create([
        'user_id' => $user->id,
        'content' => 'Why is poop brown?',
        'content_type' => ContentType::TikTokVideo,
        'meta' => ['post_mode' => 'inbox'],
        'media' => [[
            'id' => 'video-1',
            'path' => 'media/2026-11/short.mp4',
            'url' => 'https://example.com/media/2026-11/short.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'short.mp4',
        ]],
    ]);
    $this->post->setRelation('socialAccount', $this->socialAccount);

    $this->api = config('trypost.platforms.tiktok.api');
});

test('an inbox post uploads the video alone as a draft and completes when it reaches the inbox', function () {
    Http::fake([
        $this->api.'/post/publish/inbox/video/init/' => Http::response(['data' => ['publish_id' => 'pub_inbox']]),
        $this->api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX']]),
    ]);

    $result = (new TikTokPublisher)->publish($this->post);

    expect($result['id'])->toBe('pub_inbox')
        ->and($result['url'])->toBe('https://www.tiktok.com/@tiktoker');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/post/publish/inbox/video/init/')
        && $request['source_info'] === ['source' => 'PULL_FROM_URL', 'video_url' => 'https://example.com/media/2026-11/short.mp4']
        && ! isset($request['post_info']));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/post/publish/video/init/'));
});

test('an inbox post needs no privacy level, a direct one still does', function () {
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, ['post_mode' => 'inbox']))->toBeNull()
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, ['post_mode' => 'direct']))->not->toBeNull()
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, []))->not->toBeNull();
});

test('a post without a post mode is still a direct post', function () {
    $this->post->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);

    Http::fake([
        $this->api.'/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub_direct']]),
        $this->api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE']]),
    ]);

    (new TikTokPublisher)->publish($this->post->fresh());

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/post/publish/video/init/')
        && data_get($request->data(), 'post_info.privacy_level') === 'SELF_ONLY');
});
