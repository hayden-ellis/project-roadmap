<?php

namespace App\Models;

use App\Notifications\EpicStatusChanged;
use App\Support\AtlassianLink;
use App\Support\Quarter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class Epic extends Model
{
    /** @use HasFactory<\Database\Factories\EpicFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'category_id',
        'status_id',
        'board_order',
        'title',
        'description',
        'priority',
        'importance',
        'urgency',
        'matrix_order',
        'start_date',
        'end_date',
        'is_recurring',
        'jira_epic_url',
        'jpd_idea_url',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'is_recurring' => 'boolean',
            'board_order' => 'integer',
            'matrix_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A new epic lands in the quadrant its priority implies and joins the
        // end of it, until someone drags it somewhere more honest.
        static::creating(function (Epic $epic) {
            $priority = $epic->priority ?? 'medium';

            $epic->importance ??= in_array($priority, ['critical', 'high'], true) ? 'high' : 'low';
            $epic->urgency ??= $priority === 'critical' ? 'urgent' : 'not_urgent';

            if (! $epic->matrix_order) {
                $epic->matrix_order = ((int) static::where('team_id', $epic->team_id)
                    ->where('importance', $epic->importance)
                    ->where('urgency', $epic->urgency)
                    ->max('matrix_order')) + 1;
            }
        });

        // Every path that files an epic somewhere else -- a board drag, the
        // edit page, a pause or complete action -- lands here, so the people
        // in its conversation hear about the move exactly once.
        static::updated(function (Epic $epic) {
            if (! $epic->wasChanged('status_id')) {
                return;
            }

            $actor = Auth::user();

            $recipients = $epic->participants()
                ->reject(fn ($user) => $user->id === $actor?->id);

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new EpicStatusChanged(
                $epic,
                Status::find($epic->getOriginal('status_id'))?->name,
                Status::find($epic->status_id)?->name,
                $actor?->name ?? 'Someone',
            ));
        });
    }

    /**
     * Users in this epic's conversation. Commenting is how somebody opts
     * into hearing about an epic -- there is no separate watcher list.
     */
    public function participants()
    {
        return User::whereIn('id', $this->comments()->select('user_id'))->get();
    }

    /** Board order within a column. */
    public function scopeOnBoard($query)
    {
        return $query->orderBy('board_order')->orderBy('id');
    }

    /** Matrix order within a quadrant. */
    public function scopeInMatrix($query)
    {
        return $query->orderBy('matrix_order')->orderBy('id');
    }

    /** The quadrant key the matrix page groups and drags by. */
    public function quadrant(): string
    {
        return "{$this->importance}/{$this->urgency}";
    }

    /** Issue key of the linked Jira epic, when one can be read off the URL. */
    public function jiraEpicKey(): ?string
    {
        return AtlassianLink::issueKey($this->jira_epic_url);
    }

    /** Issue key of the linked Product Discovery idea, likewise. */
    public function jpdIdeaKey(): ?string
    {
        return AtlassianLink::issueKey($this->jpd_idea_url);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function quarterPlans(): HasMany
    {
        return $this->hasMany(EpicQuarterPlan::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(EpicPause::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EpicComment::class);
    }

    /** The squads this epic is planned into, across all quarters. */
    public function squads()
    {
        return $this->hasManyThrough(
            Squad::class,
            EpicQuarterPlan::class,
            'epic_id',
            'id',
            'id',
            'squad_id',
        )->distinct();
    }

    /**
     * Everything not sitting in a column the team marked as finished.
     *
     * An epic with no status counts as unfinished -- it has not been filed
     * anywhere, so it is still somebody's problem.
     */
    public function scopeUnfinished($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNull('status_id')
            ->orWhereHas('status', fn ($s) => $s->where('is_complete', false)));
    }

    public function isComplete(): bool
    {
        return (bool) $this->status?->is_complete;
    }

    public function scopeForQuarter($query, Quarter $quarter)
    {
        return $query->whereHas('quarterPlans', fn ($q) => $q
            ->where('year', $quarter->year)
            ->where('quarter', $quarter->quarter));
    }

    /**
     * The currently open pause, if the epic has one recorded.
     *
     * Only counts while the column still says the work is stopped. A record
     * left open after the card moved on is history, not the present, so it
     * is not surfaced as a live pause.
     */
    public function openPause(): ?EpicPause
    {
        if (! $this->status?->requires_reason) {
            return null;
        }

        return $this->pauses()->open()->latest('paused_at')->first();
    }
}
