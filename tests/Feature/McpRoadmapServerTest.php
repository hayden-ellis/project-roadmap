<?php

use App\Mcp\Servers\RoadmapServer;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\EditComment;
use App\Mcp\Tools\GetEpic;
use App\Mcp\Tools\ListEpics;
use App\Mcp\Tools\ListSquads;
use App\Mcp\Tools\SetEpicStatus;
use App\Mcp\Tools\UpdateEpic;
use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\EpicPause;
use App\Models\EpicQuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use App\Notifications\EpicCommented;
use App\Notifications\EpicStatusChanged;
use App\Support\Quarter;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Tool responses are JSON encoded as a single text block; this decodes it so
 * the tests can assert on structure and not just substrings.
 */
function mcpPayload(TestResponse $response): array
{
    $texts = (fn () => $this->content())->call($response);

    return json_decode($texts[0], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A user holding a token with the given abilities, the way auth:sanctum
 * would present them to a tool.
 */
function withToken(User $user, array $abilities): User
{
    return Sanctum::actingAs($user, $abilities);
}

/**
 * The MCP server is a second front door to the same data, so the tenancy
 * fence matters as much as the payload shape: every tool answers for the
 * token owner's current team and nothing else.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->backlog = Status::create([
        'team_id' => $this->team->id, 'name' => 'Backlog', 'color' => '#71717A', 'is_default' => true,
    ]);
    $this->inProgress = Status::create([
        'team_id' => $this->team->id, 'name' => 'In Progress', 'color' => '#2563EB',
    ]);
    $this->paused = Status::create([
        'team_id' => $this->team->id, 'name' => 'Paused', 'color' => '#F59E0B', 'requires_reason' => true,
    ]);
    $this->shipped = Status::create([
        'team_id' => $this->team->id, 'name' => 'Shipped', 'color' => '#16A34A', 'is_complete' => true,
    ]);

    $this->payments = Squad::create(['team_id' => $this->team->id, 'name' => 'Payments']);
    $this->growth = Squad::create(['team_id' => $this->team->id, 'name' => 'Growth']);

    $this->quarter = Quarter::current();

    $this->checkout = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Checkout Redesign',
        'description' => 'Rebuild the checkout flow.',
        'status_id' => $this->inProgress->id,
        'priority' => 'high',
        'jira_epic_url' => 'https://example.atlassian.net/browse/PAY-42',
        'jpd_idea_url' => 'https://example.atlassian.net/jira/polaris/projects/IDEA/ideas/view/1?selectedIssue=IDEA-7',
    ]);
    EpicQuarterPlan::create([
        'epic_id' => $this->checkout->id,
        'squad_id' => $this->payments->id,
        'year' => $this->quarter->year,
        'quarter' => $this->quarter->quarter,
        'planned_points' => 40,
        'delivered_points' => 15,
    ]);

    $this->referrals = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Referral Program',
        'status_id' => $this->paused->id,
    ]);
    EpicQuarterPlan::create([
        'epic_id' => $this->referrals->id,
        'squad_id' => $this->growth->id,
        'year' => $this->quarter->next()->year,
        'quarter' => $this->quarter->next()->quarter,
        'planned_points' => 20,
    ]);
    EpicPause::create([
        'epic_id' => $this->referrals->id,
        'paused_at' => now()->subWeek(),
        'reason' => 'Waiting on legal review',
    ]);

    $this->shippedEpic = Epic::create([
        'team_id' => $this->team->id,
        'title' => 'Old Login Page',
        'status_id' => $this->shipped->id,
    ]);

    // Another team's data must never leak through any tool.
    $this->stranger = User::factory()->withPersonalTeam()->create();
    $this->foreignEpic = Epic::create([
        'team_id' => $this->stranger->currentTeam->id,
        'title' => 'Secret Foreign Project',
    ]);
    Squad::create(['team_id' => $this->stranger->currentTeam->id, 'name' => 'Foreign Squad']);
});

it('lists squads with engineers and the status vocabulary', function () {
    Engineer::create([
        'team_id' => $this->team->id, 'squad_id' => $this->payments->id,
        'name' => 'Ada Lovelace', 'title' => 'Staff Engineer', 'default_weekly_points' => 5,
    ]);

    $response = RoadmapServer::actingAs($this->user)->tool(ListSquads::class);

    $response->assertOk()
        ->assertSee(['Payments', 'Growth', 'Ada Lovelace', 'Backlog', 'In Progress', 'Shipped'])
        ->assertDontSee('Foreign Squad');

    $payload = mcpPayload($response);

    expect($payload['squads'][0]['unfinished_epics'])->toBe(1)
        ->and(collect($payload['statuses'])->firstWhere('name', 'Shipped')['is_complete'])->toBeTrue();
});

it('lists unfinished epics with Jira and JPD keys by default', function () {
    $response = RoadmapServer::actingAs($this->user)->tool(ListEpics::class);

    $response->assertOk()
        ->assertSee(['Checkout Redesign', 'Referral Program', 'PAY-42', 'IDEA-7', 'Waiting on legal review'])
        ->assertDontSee(['Old Login Page', 'Secret Foreign Project']);

    $payload = mcpPayload($response);
    $checkout = collect($payload['epics'])->firstWhere('id', $this->checkout->id);

    expect($payload['count'])->toBe(2)
        ->and($checkout['status'])->toBe('In Progress')
        ->and($checkout['squads'])->toBe(['Payments'])
        ->and($checkout['quarter_plans'][0]['remaining_points'])->toBe(25);
});

it('includes complete epics on request', function () {
    RoadmapServer::actingAs($this->user)
        ->tool(ListEpics::class, ['include_complete' => true])
        ->assertOk()
        ->assertSee('Old Login Page');
});

it('filters epics by squad, status and quarter', function () {
    RoadmapServer::actingAs($this->user)
        ->tool(ListEpics::class, ['squad_id' => $this->growth->id])
        ->assertOk()
        ->assertSee('Referral Program')
        ->assertDontSee('Checkout Redesign');

    RoadmapServer::actingAs($this->user)
        ->tool(ListEpics::class, ['status' => 'in progress'])
        ->assertOk()
        ->assertSee('Checkout Redesign')
        ->assertDontSee('Referral Program');

    RoadmapServer::actingAs($this->user)
        ->tool(ListEpics::class, ['quarter' => $this->quarter->next()->key()])
        ->assertOk()
        ->assertSee('Referral Program')
        ->assertDontSee('Checkout Redesign');
});

it('explains unknown squads and statuses instead of returning everything', function () {
    RoadmapServer::actingAs($this->user)
        ->tool(ListEpics::class, ['status' => 'Nope'])
        ->assertHasErrors()
        ->assertSee(['Nope', 'Backlog', 'In Progress']);

    RoadmapServer::actingAs($this->user)
        ->tool(ListEpics::class, ['squad_id' => 999999])
        ->assertHasErrors()
        ->assertDontSee('Checkout Redesign');
});

it('returns the full detail of one epic', function () {
    $engineer = Engineer::create([
        'team_id' => $this->team->id, 'squad_id' => $this->payments->id,
        'name' => 'Grace Hopper', 'default_weekly_points' => 5,
    ]);
    Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $this->checkout->id, 'week_start' => '2026-09-07', 'share' => 1]);
    Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $this->checkout->id, 'week_start' => '2026-09-14', 'share' => 1]);

    $root = EpicComment::create(['epic_id' => $this->checkout->id, 'user_id' => $this->user->id, 'body' => 'Kickoff done.']);
    EpicComment::create(['epic_id' => $this->checkout->id, 'user_id' => $this->user->id, 'parent_id' => $root->id, 'body' => 'Designs approved.']);

    $response = RoadmapServer::actingAs($this->user)->tool(GetEpic::class, ['id' => $this->checkout->id]);

    $response->assertOk()->assertSee(['Rebuild the checkout flow.', 'Grace Hopper', 'Kickoff done.', 'Designs approved.']);

    $payload = mcpPayload($response);

    expect($payload['engineers'][0]['weeks_allocated'])->toBe(2)
        ->and($payload['engineers'][0]['first_week'])->toBe('2026-09-07')
        ->and($payload['comments'][0]['replies'][0]['body'])->toBe('Designs approved.')
        ->and($payload['jira_epic_key'])->toBe('PAY-42');
});

it('refuses to show another team\'s epic', function () {
    RoadmapServer::actingAs($this->user)
        ->tool(GetEpic::class, ['id' => $this->foreignEpic->id])
        ->assertHasErrors()
        ->assertDontSee('Secret Foreign Project');
});

it('rejects unauthenticated HTTP requests', function () {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();
});

it('serves the tool list over HTTP with a read token', function () {
    $token = $this->user->createToken('claude-code', ['mcp:read'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list-squads')
        ->assertJsonPath('result.tools.1.name', 'list-epics')
        ->assertJsonPath('result.tools.2.name', 'get-epic')
        ->assertJsonPath('result.tools.3.name', 'add-comment')
        ->assertJsonPath('result.tools.4.name', 'edit-comment')
        ->assertJsonPath('result.tools.5.name', 'update-epic')
        ->assertJsonPath('result.tools.6.name', 'set-epic-status');
});

it('rejects tokens without the mcp:read ability', function () {
    $token = $this->user->createToken('other', ['something-else'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertForbidden();
});

it('refuses a browser session even though sanctum would accept it', function () {
    $this->actingAs($this->user)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();
});

it('rate limits the endpoint before authentication', function () {
    $router = app('router');
    $route = $router->getRoutes()->match(Request::create('/mcp', 'POST'));
    $middleware = collect($router->gatherRouteMiddleware($route));

    $throttle = $middleware->search(fn ($m) => str_starts_with($m, ThrottleRequests::class));
    $auth = $middleware->search(fn ($m) => str_starts_with($m, Authenticate::class));

    expect($throttle)->not->toBeFalse()
        ->and($auth)->not->toBeFalse()
        ->and($throttle)->toBeLessThan($auth);

    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()
        ->assertHeader('X-RateLimit-Limit', '60');
});

it('mints an expiring token from the console', function () {
    $this->artisan('mcp:token', ['email' => $this->user->email])
        ->expectsOutputToContain('claude mcp add --transport http roadmap')
        ->assertSuccessful();

    $token = $this->user->tokens()->where('name', 'claude-code')->first();

    expect($token->abilities)->toBe(['mcp:read'])
        ->and($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->isBetween(now()->addDays(89), now()->addDays(91)))->toBeTrue();
});

it('mints a read-write token with the --write flag', function () {
    $this->artisan('mcp:token', ['email' => $this->user->email, '--write' => true])
        ->expectsOutputToContain('Read-write token')
        ->assertSuccessful();

    expect($this->user->tokens()->first()->abilities)->toBe(['mcp:read', 'mcp:write']);
});

it('revokes tokens from the console', function () {
    $this->user->createToken('claude-code', ['mcp:read']);
    $this->user->createToken('claude-code', ['mcp:read']);
    $this->user->createToken('other', ['mcp:read']);

    $this->artisan('mcp:token', ['email' => $this->user->email, '--revoke' => true])
        ->expectsOutputToContain('Revoked 2 token(s)')
        ->assertSuccessful();

    expect($this->user->tokens()->pluck('name')->all())->toBe(['other']);
});

// ---------------------------------------------------------------- writing

it('refuses every write tool on a read-only token', function () {
    withToken($this->user, ['mcp:read']);

    $calls = [
        [AddComment::class, ['epic_id' => $this->checkout->id, 'body' => 'Hi']],
        [EditComment::class, ['id' => 1, 'body' => 'Hi']],
        [UpdateEpic::class, ['id' => $this->checkout->id, 'title' => 'Renamed']],
        [SetEpicStatus::class, ['id' => $this->checkout->id, 'status' => 'Backlog']],
    ];

    foreach ($calls as [$tool, $arguments]) {
        RoadmapServer::actingAs($this->user)
            ->tool($tool, $arguments)
            ->assertHasErrors()
            ->assertSee('read-only');
    }

    expect($this->checkout->fresh()->title)->toBe('Checkout Redesign')
        ->and($this->checkout->comments()->count())->toBe(0);
});

it('posts a comment as the token owner and notifies the thread', function () {
    Notification::fake();

    $other = User::factory()->create();
    $this->team->users()->attach($other, ['role' => 'editor']);
    EpicComment::create(['epic_id' => $this->checkout->id, 'user_id' => $other->id, 'body' => 'Earlier note.']);

    withToken($this->user, ['mcp:read', 'mcp:write']);

    $response = RoadmapServer::actingAs($this->user)
        ->tool(AddComment::class, ['epic_id' => $this->checkout->id, 'body' => 'Designs are signed off.']);

    $response->assertOk()->assertSee('Designs are signed off.');

    $payload = mcpPayload($response);
    $comment = EpicComment::find($payload['id']);

    expect($comment->user_id)->toBe($this->user->id)
        ->and($comment->epic_id)->toBe($this->checkout->id)
        ->and($comment->parent_id)->toBeNull()
        ->and($payload['author'])->toBe($this->user->name);

    Notification::assertSentTo($other, EpicCommented::class);
    Notification::assertNotSentTo($this->user, EpicCommented::class);
});

it('replies under a root comment', function () {
    $root = EpicComment::create(['epic_id' => $this->checkout->id, 'user_id' => $this->user->id, 'body' => 'Root']);
    $reply = EpicComment::create(['epic_id' => $this->checkout->id, 'user_id' => $this->user->id, 'parent_id' => $root->id, 'body' => 'First reply']);

    withToken($this->user, ['mcp:read', 'mcp:write']);

    // Replying to a reply re-roots onto the thread's root, as the app does.
    $response = RoadmapServer::actingAs($this->user)
        ->tool(AddComment::class, ['epic_id' => $this->checkout->id, 'body' => 'Second reply', 'parent_id' => $reply->id]);

    $response->assertOk();

    expect(EpicComment::find(mcpPayload($response)['id'])->parent_id)->toBe($root->id);

    RoadmapServer::actingAs($this->user)
        ->tool(AddComment::class, ['epic_id' => $this->checkout->id, 'body' => 'Orphan', 'parent_id' => 999999])
        ->assertHasErrors();
});

it('refuses to comment on another team\'s epic', function () {
    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)
        ->tool(AddComment::class, ['epic_id' => $this->foreignEpic->id, 'body' => 'Hello?'])
        ->assertHasErrors()
        ->assertDontSee('Secret Foreign Project');

    expect($this->foreignEpic->comments()->count())->toBe(0);
});

it('edits only the token owner\'s own comments', function () {
    $other = User::factory()->create();
    $this->team->users()->attach($other, ['role' => 'editor']);

    $mine = EpicComment::create(['epic_id' => $this->checkout->id, 'user_id' => $this->user->id, 'body' => 'Draft']);
    $theirs = EpicComment::create(['epic_id' => $this->checkout->id, 'user_id' => $other->id, 'body' => 'Not yours']);
    $foreign = EpicComment::create(['epic_id' => $this->foreignEpic->id, 'user_id' => $this->stranger->id, 'body' => 'Elsewhere']);

    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)
        ->tool(EditComment::class, ['id' => $mine->id, 'body' => 'Final'])
        ->assertOk()
        ->assertSee('Final');

    RoadmapServer::actingAs($this->user)
        ->tool(EditComment::class, ['id' => $theirs->id, 'body' => 'Hijacked'])
        ->assertHasErrors()
        ->assertSee('someone else');

    RoadmapServer::actingAs($this->user)
        ->tool(EditComment::class, ['id' => $foreign->id, 'body' => 'Hijacked'])
        ->assertHasErrors()
        ->assertDontSee('Elsewhere');

    expect($mine->fresh()->body)->toBe('Final')
        ->and($theirs->fresh()->body)->toBe('Not yours')
        ->and($foreign->fresh()->body)->toBe('Elsewhere');
});

it('updates the epic fields it is given and leaves the rest alone', function () {
    withToken($this->user, ['mcp:read', 'mcp:write']);

    $response = RoadmapServer::actingAs($this->user)->tool(UpdateEpic::class, [
        'id' => $this->checkout->id,
        'title' => 'Checkout v2',
        'jpd_idea_url' => '',
        'priority' => 'critical',
    ]);

    $response->assertOk();

    $payload = mcpPayload($response);
    $epic = $this->checkout->fresh();

    expect($payload['changed'])->toBe(['title', 'priority', 'jpd_idea_url'])
        ->and($epic->title)->toBe('Checkout v2')
        ->and($epic->priority)->toBe('critical')
        ->and($epic->jpd_idea_url)->toBeNull()
        ->and($epic->description)->toBe('Rebuild the checkout flow.')
        ->and($epic->jira_epic_url)->toBe('https://example.atlassian.net/browse/PAY-42')
        ->and($payload['epic']['jira_epic_key'])->toBe('PAY-42');
});

it('rejects bad epic updates without touching the epic', function () {
    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)
        ->tool(UpdateEpic::class, ['id' => $this->checkout->id, 'jira_epic_url' => 'ftp://nope'])
        ->assertHasErrors()
        ->assertSee('https://');

    RoadmapServer::actingAs($this->user)
        ->tool(UpdateEpic::class, ['id' => $this->checkout->id, 'title' => '   '])
        ->assertHasErrors();

    RoadmapServer::actingAs($this->user)
        ->tool(UpdateEpic::class, ['id' => $this->checkout->id])
        ->assertHasErrors()
        ->assertSee('Nothing to change');

    RoadmapServer::actingAs($this->user)
        ->tool(UpdateEpic::class, ['id' => $this->foreignEpic->id, 'title' => 'Mine now'])
        ->assertHasErrors();

    expect($this->checkout->fresh()->title)->toBe('Checkout Redesign')
        ->and($this->checkout->fresh()->jira_epic_url)->toBe('https://example.atlassian.net/browse/PAY-42')
        ->and($this->foreignEpic->fresh()->title)->toBe('Secret Foreign Project');
});

it('moves an epic between statuses by name and closes its pause', function () {
    Notification::fake();

    $other = User::factory()->create();
    $this->team->users()->attach($other, ['role' => 'editor']);
    EpicComment::create(['epic_id' => $this->referrals->id, 'user_id' => $other->id, 'body' => 'Watching this.']);

    withToken($this->user, ['mcp:read', 'mcp:write']);

    $response = RoadmapServer::actingAs($this->user)
        ->tool(SetEpicStatus::class, ['id' => $this->referrals->id, 'status' => 'in progress']);

    $response->assertOk();

    $payload = mcpPayload($response);

    expect($payload['changed'])->toBeTrue()
        ->and($payload['epic']['status'])->toBe('In Progress')
        ->and($payload['epic']['paused_reason'])->toBeNull()
        ->and($this->referrals->fresh()->status_id)->toBe($this->inProgress->id)
        ->and($this->referrals->pauses()->open()->count())->toBe(0);

    Notification::assertSentTo($other, EpicStatusChanged::class);
});

it('requires a reason to land in a pause column and records the pause', function () {
    $engineer = Engineer::create([
        'team_id' => $this->team->id, 'squad_id' => $this->payments->id,
        'name' => 'Grace Hopper', 'default_weekly_points' => 5,
    ]);
    $thisWeek = \App\Services\CapacityService::for($this->team)->currentWeek();
    Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $this->checkout->id, 'week_start' => $thisWeek->subWeek(), 'share' => 1]);
    Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $this->checkout->id, 'week_start' => $thisWeek, 'share' => 1]);
    Allocation::create(['engineer_id' => $engineer->id, 'epic_id' => $this->checkout->id, 'week_start' => $thisWeek->addWeek(), 'share' => 1]);

    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)
        ->tool(SetEpicStatus::class, ['id' => $this->checkout->id, 'status' => 'Paused'])
        ->assertHasErrors()
        ->assertSee('reason');

    expect($this->checkout->fresh()->status_id)->toBe($this->inProgress->id);

    $response = RoadmapServer::actingAs($this->user)->tool(SetEpicStatus::class, [
        'id' => $this->checkout->id,
        'status' => 'paused',
        'reason' => 'Traded for the scheduler',
        'superseded_by_epic_id' => $this->referrals->id,
    ]);

    $response->assertOk();

    $epic = $this->checkout->fresh();
    $pause = $epic->pauses()->open()->first();

    expect($epic->status_id)->toBe($this->paused->id)
        ->and($pause->reason)->toBe('Traded for the scheduler')
        ->and($pause->superseded_by_epic_id)->toBe($this->referrals->id)
        ->and($pause->paused_at->toDateString())->toBe($thisWeek->toDateString())
        ->and($epic->allocations()->pluck('week_start')->map->toDateString()->all())->toBe([$thisWeek->subWeek()->toDateString()])
        ->and(mcpPayload($response)['epic']['paused_reason'])->toBe('Traded for the scheduler');

    // Saying it again does not stack a second pause record.
    RoadmapServer::actingAs($this->user)
        ->tool(SetEpicStatus::class, ['id' => $this->checkout->id, 'status' => 'Paused', 'reason' => 'Again'])
        ->assertOk();

    expect($epic->pauses()->count())->toBe(1);
});

it('explains unknown statuses and foreign epics when moving', function () {
    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)
        ->tool(SetEpicStatus::class, ['id' => $this->checkout->id, 'status' => 'Nope'])
        ->assertHasErrors()
        ->assertSee(['Nope', 'Backlog', 'In Progress']);

    RoadmapServer::actingAs($this->user)
        ->tool(SetEpicStatus::class, ['id' => $this->checkout->id, 'status' => 'Paused', 'reason' => 'x', 'superseded_by_epic_id' => $this->foreignEpic->id])
        ->assertHasErrors();

    RoadmapServer::actingAs($this->user)
        ->tool(SetEpicStatus::class, ['id' => $this->foreignEpic->id, 'status' => 'Backlog'])
        ->assertHasErrors()
        ->assertDontSee('Secret Foreign Project');

    expect($this->checkout->fresh()->status_id)->toBe($this->inProgress->id)
        ->and($this->foreignEpic->fresh()->status_id)->toBeNull();
});

it('writes history for an MCP update as the token owner, marked as MCP', function () {
    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)->tool(UpdateEpic::class, [
        'id' => $this->checkout->id,
        'title' => 'Checkout v2',
        'priority' => 'critical',
    ])->assertOk();

    $row = $this->checkout->activities()->first();

    expect($row->event)->toBe('updated')
        ->and($row->source)->toBe('mcp')
        ->and($row->user_id)->toBe($this->user->id)
        ->and(array_keys($row->diff))->toBe(['title', 'priority']);
});

it('writes a status move from MCP with the column names', function () {
    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)
        ->tool(SetEpicStatus::class, ['id' => $this->referrals->id, 'status' => 'in progress'])
        ->assertOk();

    $row = $this->referrals->activities()->first();

    expect($row->source)->toBe('mcp')
        ->and($row->lines()[0]['text'])->toBe('moved it from Paused to In Progress');
});

it('includes recent history in the epic detail', function () {
    withToken($this->user, ['mcp:read', 'mcp:write']);

    RoadmapServer::actingAs($this->user)
        ->tool(UpdateEpic::class, ['id' => $this->checkout->id, 'title' => 'Checkout v2'])
        ->assertOk();

    $payload = mcpPayload(RoadmapServer::actingAs($this->user)->tool(GetEpic::class, ['id' => $this->checkout->id]));

    expect($payload['history'][0])->toMatchArray([
        'actor' => $this->user->name,
        'source' => 'mcp',
        'event' => 'updated',
        'summary' => 'renamed it from "Checkout Redesign" to "Checkout v2"',
    ])->and($payload['history'][0]['changes']['title']['to'])->toBe('Checkout v2')
        ->and(end($payload['history'])['event'])->toBe('created');
});
