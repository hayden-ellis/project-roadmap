<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\EpicPayload;
use App\Mcp\Support\RequiresWriteAbility;
use App\Mcp\Support\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateEpic extends Tool
{
    use RequiresWriteAbility, ResolvesTeam;

    protected string $description = <<<'MARKDOWN'
        Change an epic's title, description, priority, or its Jira / Jira
        Product Discovery links. Only the fields you pass are touched; pass an
        empty string to clear a description or link. To move an epic between
        statuses use set-epic-status instead. Needs a token with write access.
    MARKDOWN;

    public function handle(Request $request): Response
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'priority' => ['sometimes', 'in:low,medium,high,critical'],
            'jira_epic_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'jpd_idea_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ], [
            'title.max' => 'The title may be at most 255 characters.',
        ]);

        $epic = $team->epics()->find($validated['id']);

        if (! $epic || ! $request->user()->can('update', $epic)) {
            return Response::error("Epic [{$validated['id']}] was not found on this team.");
        }

        $changes = [];

        if (array_key_exists('title', $validated)) {
            $title = trim($validated['title']);

            if ($title === '') {
                return Response::error('The title cannot be empty.');
            }

            $changes['title'] = $title;
        }

        if (array_key_exists('description', $validated)) {
            $changes['description'] = trim((string) $validated['description']) ?: null;
        }

        if (array_key_exists('priority', $validated)) {
            $changes['priority'] = $validated['priority'];
        }

        foreach (['jira_epic_url' => 'Jira', 'jpd_idea_url' => 'Product Discovery'] as $field => $label) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $url = trim((string) $validated[$field]);

            if ($url !== '' && ! str_starts_with($url, 'https://')) {
                return Response::error("Pass the full https:// link from {$label} for {$field}.");
            }

            $changes[$field] = $url ?: null;
        }

        if ($changes === []) {
            return Response::error('Nothing to change. Pass at least one of title, description, priority, jira_epic_url or jpd_idea_url.');
        }

        $epic->update($changes);

        $epic->load(['status', 'category', 'quarterPlans.squad', 'pauses']);

        return Response::json([
            'changed' => array_keys($changes),
            'epic' => EpicPayload::summary($epic) + ['description' => $epic->description],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->required()
                ->description('The epic id, as returned by list-epics.'),
            'title' => $schema->string()
                ->max(255)
                ->description('A new title.'),
            'description' => $schema->string()
                ->description('A new description. Empty string clears it.'),
            'priority' => $schema->string()
                ->enum(['low', 'medium', 'high', 'critical'])
                ->description('A new priority.'),
            'jira_epic_url' => $schema->string()
                ->description('The full https:// link to the Jira epic. Empty string clears it.'),
            'jpd_idea_url' => $schema->string()
                ->description('The full https:// link to the Jira Product Discovery idea. Empty string clears it.'),
        ];
    }
}
