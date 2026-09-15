<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeMcpToken extends Command
{
    protected $signature = 'mcp:token
                            {email : The user whose team the token will read}
                            {--name=claude-code : A label for the token}
                            {--write : Also allow the token to post comments and edit epics}
                            {--revoke : Revoke every token with this label instead of minting one}';

    protected $description = 'Mint (or revoke) a bearer token for the Project Roadmap MCP server';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user with email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $count = $user->tokens()->where('name', $this->option('name'))->delete();

            $this->info("Revoked {$count} token(s) named [{$this->option('name')}] for {$user->email}.");

            return self::SUCCESS;
        }

        if (! $user->currentTeam) {
            $this->error("{$user->email} has no current team, so the token would see nothing.");

            return self::FAILURE;
        }

        $abilities = $this->option('write') ? ['mcp:read', 'mcp:write'] : ['mcp:read'];

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken($this->option('name'), $abilities, $expiresAt);

        $this->info(sprintf(
            '%s token for %s (team: %s). Expires %s. It is shown once; copy it now and clear it from any console log.',
            $this->option('write') ? 'Read-write' : 'Read-only',
            $user->email,
            $user->currentTeam->name,
            $expiresAt->toDateString(),
        ));
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line('Connect Claude Code with:');
        $this->line(sprintf(
            '  claude mcp add --transport http roadmap %s --header "Authorization: Bearer %s"',
            url('/mcp'),
            $token->plainTextToken,
        ));
        $this->newLine();
        $this->line("Revoke later with: php artisan mcp:token {$user->email} --revoke");

        return self::SUCCESS;
    }
}
