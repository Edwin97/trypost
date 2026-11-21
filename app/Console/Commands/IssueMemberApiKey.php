<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issues an API key for a member who needs approval in a workspace, limited to
 * config('trypost.member_api_routes'): an agent can upload media and create posts
 * that wait for an admin's approval, and read their metrics. Run by an admin on
 * the server; the key is printed once.
 */
class IssueMemberApiKey extends Command
{
    protected $signature = 'trypost:member-key
        {email : The member\'s email}
        {workspace : The workspace id or its exact name}
        {--name=Agent : A name for the key}';

    protected $description = 'Issue a limited API key for a workspace member who needs approval';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $value = (string) $this->argument('workspace');
        $workspace = Str::isUuid($value)
            ? Workspace::query()->find($value)
            : Workspace::query()->where('name', $value)->where('account_id', $user->account_id)->first();

        if (! $workspace) {
            $this->error('No such workspace in this member\'s account.');

            return self::FAILURE;
        }

        if (! $user->requiresApprovalIn($workspace)) {
            $this->error('This user doesn\'t need approval in that workspace. Member keys are only for members whose posts wait for approval.');

            return self::FAILURE;
        }

        $result = $user->createToken((string) $this->option('name'), ['member:posts']);

        AccessToken::query()->findOrFail($result->token->id)
            ->forceFill(['workspace_id' => $workspace->id, 'expires_at' => null])
            ->saveQuietly();

        $this->line($result->accessToken);

        return self::SUCCESS;
    }
}
