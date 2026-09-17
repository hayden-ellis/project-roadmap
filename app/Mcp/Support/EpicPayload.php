<?php

namespace App\Mcp\Support;

use App\Models\Epic;
use App\Models\EpicActivity;
use App\Models\EpicComment;
use App\Models\EpicPause;
use App\Models\EpicQuarterPlan;

/**
 * Shapes an epic for the MCP client. The summary is what a list call returns
 * per row; the detail adds everything a status update could draw on.
 */
class EpicPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Epic $epic): array
    {
        $openPause = $epic->openPause();

        return [
            'id' => $epic->id,
            'title' => $epic->title,
            'status' => $epic->status?->name,
            'is_complete' => $epic->isComplete(),
            'category' => $epic->category?->name,
            'priority' => $epic->priority,
            'start_date' => $epic->start_date?->toDateString(),
            'end_date' => $epic->end_date?->toDateString(),
            'is_recurring' => $epic->is_recurring,
            'jira_epic_key' => $epic->jiraEpicKey(),
            'jira_epic_url' => $epic->jira_epic_url,
            'jpd_idea_key' => $epic->jpdIdeaKey(),
            'jpd_idea_url' => $epic->jpd_idea_url,
            'release_percent' => $epic->release_percent,
            'squads' => $epic->quarterPlans
                ->map(fn (EpicQuarterPlan $plan) => $plan->squad?->name)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'quarter_plans' => $epic->quarterPlans
                ->map(fn (EpicQuarterPlan $plan) => static::quarterPlan($plan))
                ->values()
                ->all(),
            'paused_reason' => $openPause?->reason,
            'paused_at' => $openPause?->paused_at?->toDateString(),
            'updated_at' => $epic->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Epic $epic): array
    {
        $engineers = $epic->allocations
            ->groupBy('engineer_id')
            ->map(function ($allocations) {
                $engineer = $allocations->first()->engineer;
                $weeks = $allocations->pluck('week_start')->sort();

                return [
                    'id' => $engineer?->id,
                    'name' => $engineer?->name,
                    'squad' => $engineer?->squad?->name,
                    'weeks_allocated' => $allocations->count(),
                    'first_week' => $weeks->first()?->toDateString(),
                    'last_week' => $weeks->last()?->toDateString(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return static::summary($epic) + [
            'description' => $epic->description,
            'importance' => $epic->importance,
            'urgency' => $epic->urgency,
            'engineers' => $engineers,
            'pauses' => $epic->pauses
                ->sortByDesc('paused_at')
                ->map(fn (EpicPause $pause) => [
                    'paused_at' => $pause->paused_at?->toDateString(),
                    'resumed_at' => $pause->resumed_at?->toDateString(),
                    'reason' => $pause->reason,
                    'superseded_by_epic_id' => $pause->superseded_by_epic_id,
                ])
                ->values()
                ->all(),
            'comments' => $epic->comments
                ->whereNull('parent_id')
                ->sortBy('created_at')
                ->map(fn (EpicComment $comment) => static::comment($comment))
                ->values()
                ->all(),
            // The last few things that happened to it, newest first: field
            // and status changes with who made them and from where.
            'history' => $epic->activities
                ->take(20)
                ->map(fn (EpicActivity $activity) => static::activity($activity))
                ->values()
                ->all(),
            'created_at' => $epic->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function activity(EpicActivity $activity): array
    {
        return [
            'at' => $activity->created_at?->toIso8601String(),
            'actor' => $activity->actorName(),
            'source' => $activity->source,
            'event' => $activity->event,
            'summary' => collect($activity->lines())->pluck('text')->implode('; '),
            'changes' => $activity->diff,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function quarterPlan(EpicQuarterPlan $plan): array
    {
        return [
            'squad_id' => $plan->squad_id,
            'squad' => $plan->squad?->name,
            'quarter' => $plan->toQuarter()->key(),
            'planned_points' => $plan->planned_points,
            'delivered_points' => $plan->delivered_points,
            'remaining_points' => $plan->remainingPoints(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function comment(EpicComment $comment): array
    {
        return [
            'id' => $comment->id,
            'author' => $comment->user?->name,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
            'replies' => $comment->replies
                ->map(fn (EpicComment $reply) => [
                    'id' => $reply->id,
                    'author' => $reply->user?->name,
                    'body' => $reply->body,
                    'created_at' => $reply->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
