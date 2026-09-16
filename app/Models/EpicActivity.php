<?php

namespace App\Models;

use App\Support\AtlassianLink;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in an epic's history.
 *
 * Comments hold what people said; this holds what they did -- a rename, a
 * move between columns, a new link. Each row is one save, so an MCP update
 * that changed three fields at once reads as one entry with three lines.
 */
class EpicActivity extends Model
{
    /** @use HasFactory<\Database\Factories\EpicActivityFactory> */
    use HasFactory;

    /** How many rows a history tab shows before offering the rest. */
    public const RECENT = 50;

    /** The epic columns history follows. Drag order is deliberately not one of them. */
    public const TRACKED = [
        'title',
        'description',
        'priority',
        'category_id',
        'status_id',
        'start_date',
        'end_date',
        'is_recurring',
        'jira_epic_url',
        'jpd_idea_url',
    ];

    protected $fillable = [
        'epic_id',
        'user_id',
        'actor_name',
        'event',
        'source',
        'diff',
    ];

    protected function casts(): array
    {
        return [
            'diff' => 'array',
        ];
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The live account if it is still around, else the name it had. */
    public function actorName(): string
    {
        return $this->user?->name ?? $this->actor_name ?? 'System';
    }

    public function isSystem(): bool
    {
        return $this->user_id === null && $this->actor_name === null;
    }

    /**
     * The entry as sentence fragments, one per changed field, so the wording
     * lives here and the flyout, the epic page and the MCP payload all read
     * the same.
     *
     * @return list<array{text: string, from: ?string, to: ?string, long: bool}>
     */
    public function lines(): array
    {
        if ($this->event === 'created') {
            return $this->createdLines();
        }

        return collect($this->diff ?? [])
            ->map(fn (array $change, string $field) => $this->line($field, $change))
            ->values()
            ->all();
    }

    /**
     * @return list<array{text: string, from: ?string, to: ?string, long: bool}>
     */
    private function createdLines(): array
    {
        $changes = $this->diff ?? [];

        $where = collect([
            isset($changes['status_id']) ? 'in '.$this->label('status_id', $changes['status_id'], 'to') : null,
            isset($changes['category_id']) ? 'under '.$this->label('category_id', $changes['category_id'], 'to') : null,
            isset($changes['priority']) ? 'at '.ucfirst($changes['priority']['to']).' priority' : null,
        ])->filter();

        $lines = [self::fragment('created this epic')];

        if ($where->isNotEmpty()) {
            $lines[] = self::fragment($where->implode(', '));
        }

        return $lines;
    }

    /**
     * @param  array{from: mixed, to: mixed, from_label?: ?string, to_label?: ?string}  $change
     * @return array{text: string, from: ?string, to: ?string, long: bool}
     */
    private function line(string $field, array $change): array
    {
        $from = $change['from'] ?? null;
        $to = $change['to'] ?? null;

        return match ($field) {
            'title' => self::fragment("renamed it from \"{$from}\" to \"{$to}\"", $from, $to),

            'description' => match (true) {
                $from === null => self::fragment('added a description', null, $to, long: true),
                $to === null => self::fragment('cleared the description', $from, null, long: true),
                default => self::fragment('updated the description', $from, $to, long: true),
            },

            'priority' => self::fragment(
                'changed priority from '.ucfirst((string) $from).' to '.ucfirst((string) $to),
                ucfirst((string) $from),
                ucfirst((string) $to),
            ),

            'status_id' => match (true) {
                $from === null => self::fragment('filed it in '.$this->label($field, $change, 'to')),
                $to === null => self::fragment('took it out of '.$this->label($field, $change, 'from')),
                default => self::fragment(
                    'moved it from '.$this->label($field, $change, 'from').' to '.$this->label($field, $change, 'to'),
                    $this->label($field, $change, 'from'),
                    $this->label($field, $change, 'to'),
                ),
            },

            'category_id' => match (true) {
                $from === null => self::fragment('set category to '.$this->label($field, $change, 'to')),
                $to === null => self::fragment('removed the category'),
                default => self::fragment(
                    'changed category from '.$this->label($field, $change, 'from').' to '.$this->label($field, $change, 'to'),
                    $this->label($field, $change, 'from'),
                    $this->label($field, $change, 'to'),
                ),
            },

            'start_date', 'end_date' => $this->dateLine($field === 'start_date' ? 'start date' : 'end date', $from, $to),

            'is_recurring' => self::fragment($to ? 'marked it recurring' : 'marked it one-off'),

            'jira_epic_url', 'jpd_idea_url' => $this->linkLine($field === 'jira_epic_url' ? 'Jira epic' : 'JPD idea', $from, $to),

            default => self::fragment("changed {$field}", self::stringify($from), self::stringify($to)),
        };
    }

    /**
     * @return array{text: string, from: ?string, to: ?string, long: bool}
     */
    private function dateLine(string $noun, mixed $from, mixed $to): array
    {
        $fromText = $from ? Carbon::parse($from)->format('j M Y') : null;
        $toText = $to ? Carbon::parse($to)->format('j M Y') : null;

        return match (true) {
            $fromText === null => self::fragment("set the {$noun} to {$toText}", null, $toText),
            $toText === null => self::fragment("cleared the {$noun}", $fromText, null),
            default => self::fragment("moved the {$noun} from {$fromText} to {$toText}", $fromText, $toText),
        };
    }

    /**
     * @return array{text: string, from: ?string, to: ?string, long: bool}
     */
    private function linkLine(string $noun, mixed $from, mixed $to): array
    {
        $fromKey = self::linkLabel($from);
        $toKey = self::linkLabel($to);

        return match (true) {
            $from === null => self::fragment("linked {$noun} {$toKey}", null, $toKey),
            $to === null => self::fragment("unlinked the {$noun}", $fromKey, null),
            default => self::fragment("changed the {$noun} link to {$toKey}", $fromKey, $toKey),
        };
    }

    /** The issue key when the URL has one, otherwise the host, otherwise the URL. */
    private static function linkLabel(mixed $url): ?string
    {
        if (! $url) {
            return null;
        }

        return AtlassianLink::issueKey($url) ?? parse_url($url, PHP_URL_HOST) ?? $url;
    }

    /**
     * The name a status or category had at the time, falling back to the id
     * for rows written before a label was known.
     *
     * @param  array{from: mixed, to: mixed, from_label?: ?string, to_label?: ?string}  $change
     */
    private function label(string $field, array $change, string $side): string
    {
        $label = $change["{$side}_label"] ?? null;

        if ($label !== null && $label !== '') {
            return $label;
        }

        $value = $change[$side] ?? null;

        return $value === null ? 'none' : ($field === 'status_id' ? 'status' : 'category')." #{$value}";
    }

    private static function stringify(mixed $value): ?string
    {
        return $value === null ? null : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
    }

    /**
     * @return array{text: string, from: ?string, to: ?string, long: bool}
     */
    private static function fragment(string $text, ?string $from = null, ?string $to = null, bool $long = false): array
    {
        return ['text' => $text, 'from' => $from, 'to' => $to, 'long' => $long];
    }
}
