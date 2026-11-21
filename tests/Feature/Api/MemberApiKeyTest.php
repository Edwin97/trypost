<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $owner = createApiTestToken();
    $this->workspace = $owner['workspace'];
    $this->ownerUser = $owner['user'];
    $this->member = workspaceMember($this->workspace, 'approval');
    $this->account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

function issueMemberKey(User $member, $workspace): string
{
    Artisan::call('trypost:member-key', ['email' => $member->email, 'workspace' => $workspace->id]);

    return trim(Artisan::output());
}

test('a member key reaches its routes and its posts wait for approval', function () {
    $token = issueMemberKey($this->member, $this->workspace);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson(route('api.social-accounts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->account->id);

    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson(route('api.posts.store'), [
            'social_account_id' => $this->account->id,
            'content_type' => 'linkedin_post',
            'content' => 'Why is poop brown?',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'meta' => ['external_ref' => 'stool-log/04/linkedin'],
        ])
        ->assertSuccessful();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->sole()->status)->toBe(Status::PendingApproval);
});

test('a member key can never approve, manage keys or reach other routes', function () {
    $token = issueMemberKey($this->member, $this->workspace);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->member->id,
        'status' => Status::PendingApproval,
    ]);

    foreach ([
        ['post', route('api.posts.approve', $post)],
        ['get', route('api.api-keys.index')],
        ['post', route('api.api-keys.store')],
        ['delete', route('api.posts.destroy', $post)],
        ['get', route('api.labels.index')],
    ] as [$method, $url]) {
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->json($method, $url)
            ->assertForbidden()
            ->assertJson(['message' => 'Insufficient workspace permissions.']);
    }

    expect($post->fresh()->status)->toBe(Status::PendingApproval);
});

test('a member key stops working once its member publishes directly', function () {
    $token = issueMemberKey($this->member, $this->workspace);
    $this->workspace->members()->updateExistingPivot($this->member->id, membershipPivot('member'));

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson(route('api.social-accounts.index'))
        ->assertForbidden();
});

test('a plain key of a member who needs approval is still refused', function () {
    $token = passportToken($this->member, $this->workspace);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson(route('api.social-accounts.index'))
        ->assertForbidden();
});

test('member keys are only issued to members who need approval', function () {
    $direct = workspaceMember($this->workspace, 'member');

    expect(Artisan::call('trypost:member-key', ['email' => $direct->email, 'workspace' => $this->workspace->id]))->toBe(1)
        ->and(Artisan::call('trypost:member-key', ['email' => $this->ownerUser->email, 'workspace' => $this->workspace->id]))->toBe(1)
        ->and(Artisan::call('trypost:member-key', ['email' => 'nobody@example.test', 'workspace' => $this->workspace->id]))->toBe(1);
});
