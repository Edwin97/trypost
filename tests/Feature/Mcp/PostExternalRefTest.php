<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\ListPostsTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Storage::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

test('a post keeps the caller reference it was created with', function () {
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'social_account_id' => $this->socialAccount->id,
        'content_type' => 'linkedin_post',
        'content' => 'Why is poop brown?',
        'meta' => ['external_ref' => 'stool-log/04/linkedin'],
    ])->assertOk();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->sole()->meta)
        ->toMatchArray(['external_ref' => 'stool-log/04/linkedin']);
});

test('a caller reference with spaces or other signs is refused', function () {
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'social_account_id' => $this->socialAccount->id,
        'content_type' => 'linkedin_post',
        'content' => 'Hello',
        'meta' => ['external_ref' => 'stool log <04>'],
    ])->assertHasErrors();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

test('list posts filters on the caller reference', function () {
    $wanted = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'meta' => ['external_ref' => 'stool-log/23/youtube'],
    ]);
    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'meta' => ['external_ref' => 'stool-log/24/youtube'],
    ]);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    TryPostServer::actingAs($this->user)
        ->tool(ListPostsTool::class, ['external_ref' => 'stool-log/23/youtube'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('posts', 1, fn (AssertableJson $post) => $post->where('id', $wanted->id)->etc())
            ->etc());
});
