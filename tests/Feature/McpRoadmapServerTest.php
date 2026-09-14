<?php

use App\Mcp\Servers\RoadmapServer;
use App\Mcp\Tools\GetEpic;
use App\Mcp\Tools\ListEpics;
use App\Mcp\Tools\ListSquads;
use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\EpicPause;
use App\Models\EpicQuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use App\Support\Quarter;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Mcp\Server\Testing\TestResponse;

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
        ->assertJsonPath('result.tools.2.name', 'get-epic');
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

it('revokes tokens from the console', function () {
    $this->user->createToken('claude-code', ['mcp:read']);
    $this->user->createToken('claude-code', ['mcp:read']);
    $this->user->createToken('other', ['mcp:read']);

    $this->artisan('mcp:token', ['email' => $this->user->email, '--revoke' => true])
        ->expectsOutputToContain('Revoked 2 token(s)')
        ->assertSuccessful();

    expect($this->user->tokens()->pluck('name')->all())->toBe(['other']);
});
