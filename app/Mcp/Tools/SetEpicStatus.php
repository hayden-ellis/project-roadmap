<?php

namespace App\Mcp\Tools;

use App\Actions\Epics\ChangeEpicStatus;
use App\Actions\Epics\PauseEpic;
use App\Mcp\Support\EpicPayload;
use App\Mcp\Support\RequiresWriteAbility;
use App\Mcp\Support\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SetEpicStatus extends Tool
{
    use RequiresWriteAbility, ResolvesTeam;

    protected string $description = <<<'MARKDOWN'
        Move an epic to another status (board column) by name. Statuses whose
        `requires_reason` flag is true in list-squads are pause columns: moving
        an epic there also needs a `reason`, records a pause, and clears the
        epic's bookings from this week onward, just like pausing in the app.
        Moving out of a pause column closes the open pause. People in the
        epic's conversation are notified of the move. Needs a token with write
        access.
    MARKDOWN;

    public function handle(Request $request, ChangeEpicStatus $changeStatus, PauseEpic $pauseEpic): Response
    {
        if ($denied = $this->writeDenied($request)) {
            return $denied;
        }

        $team = $this->team($request);

        if (! $team) {
            return Response::error('The authenticated user has no current team.');
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
            'superseded_by_epic_id' => ['nullable', 'integer'],
        ]);

        $epic = $team->epics()->find($validated['id']);

        if (! $epic || ! $request->user()->can('update', $epic)) {
            return Response::error("Epic [{$validated['id']}] was not found on this team.");
        }

        $status = $team->statuses()->whereRaw('lower(name) = ?', [mb_strtolower($validated['status'])])->first();

        if (! $status) {
            $known = $team->statuses()->ordered()->pluck('name')->implode(', ');

            return Response::error("Status [{$validated['status']}] was not found on this team. Known statuses: {$known}.");
        }

        $reason = trim((string) ($validated['reason'] ?? ''));
        $supersededById = $validated['superseded_by_epic_id'] ?? null;

        if ($status->requires_reason && $reason === '') {
            return Response::error("Status [{$status->name}] asks why the work stopped. Pass a `reason`.");
        }

        if ($supersededById !== null && ! $team->epics()->whereKey($supersededById)->exists()) {
            return Response::error("Epic [{$supersededById}] was not found on this team, so it cannot be the one that took the capacity.");
        }

        $alreadyThere = $epic->status_id === $status->id;

        DB::transaction(function () use ($epic, $status, $reason, $supersededById, $changeStatus, $pauseEpic, $alreadyThere) {
            $changeStatus->handle($epic, $status);

            // Landing in a pause column records the pause. Re-stating a pause
            // the epic is already in is a no-op rather than a second record.
            if ($status->requires_reason && ! $alreadyThere) {
                $pauseEpic->handle($epic, $reason, $supersededById, $status);
            }
        });

        $epic->refresh()->load(['status', 'category', 'quarterPlans.squad', 'pauses']);

        return Response::json([
            'changed' => ! $alreadyThere,
            'epic' => EpicPayload::summary($epic),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->required()
                ->description('The epic id, as returned by list-epics.'),
            'status' => $schema->string()
                ->required()
                ->description('The target status name (case-insensitive). Get names from list-squads.'),
            'reason' => $schema->string()
                ->max(255)
                ->description('Why the work stopped. Required when the target status has requires_reason.'),
            'superseded_by_epic_id' => $schema->integer()
                ->description('When pausing: the epic that took this one\'s capacity, if any.'),
        ];
    }
}
