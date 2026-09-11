<?php

use App\Models\Epic;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The bell in the header.
 *
 * Notifications are read the moment they are opened, not the moment the
 * panel is -- glancing at the list costs nothing.
 */
new class extends Component
{
    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    /** Mark one read and go where it points. */
    public function open(string $notificationId): mixed
    {
        $notification = Auth::user()->notifications()->findOr($notificationId, fn () => abort(404));

        $notification->markAsRead();

        $epicId = $notification->data['epic_id'] ?? null;

        // The epic may be gone by the time the bell is answered; the list
        // is the honest fallback.
        $url = $epicId && Epic::whereKey($epicId)->exists()
            ? url("/epics/{$epicId}/edit")
            : url('/epics');

        return $this->redirect($url, navigate: true);
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'notifications' => $user->notifications()->latest()->limit(15)->get(),
            'unreadCount' => $user->unreadNotifications()->count(),
            // Moves name their destination; the pill borrows the column's colour.
            'statusColors' => $user->currentTeam?->statuses()->pluck('color', 'name') ?? collect(),
        ];
    }
};
?>

@php
    // Two letters from a name, for the avatar. The notification only keeps
    // the actor's name, so that is all there is to draw from.
    $initials = fn (string $name) => Str::of($name)->squish()->explode(' ')
        ->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');

    // Tight, so the column stays narrow: 12m, 3h, 5d, then the date.
    $when = fn ($at) => $at->gt(now()->subDays(6))
        ? $at->shortAbsoluteDiffForHumans()
        : $at->format('j M');

    $day = fn ($at) => $at->isToday() ? 'Today' : ($at->isYesterday() ? 'Yesterday' : 'Earlier');
@endphp

<div wire:poll.60s>
    <flux:dropdown position="bottom" align="end">
        {{-- The icon lives in the slot rather than the `icon` prop so the
             count can anchor to the bell itself. --}}
        <flux:button
            variant="subtle"
            square
            class="relative"
            :aria-label="$unreadCount === 0 ? __('Notifications') : trans_choice('{1}:count unread notification|[2,*]:count unread notifications', $unreadCount, ['count' => $unreadCount])"
            data-test="notification-bell"
        >
            <span class="relative inline-flex">
                <flux:icon.bell variant="micro" class="size-5" />

                @if($unreadCount > 0)
                {{-- Sits on the bell's top-right corner rather than over its
                     body: smaller than the glyph and offset by roughly its
                     own radius, so one digit kisses the corner and a wider
                     count grows rightward into empty space. --}}
                <span aria-hidden="true"
                    class="absolute -right-1.5 -top-1 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-accent px-1 text-[9px] font-semibold leading-none text-white ring-2 ring-zinc-50 tabular-nums dark:ring-zinc-900"
                    data-test="notification-count"
                >
                    {{ $unreadCount > 99 ? '99+' : $unreadCount }}
                </span>
                @endif
            </span>
        </flux:button>

        {{-- A popover rather than a menu: these rows are not menu items, and
             the menu's item styling is what made every line read as bold. --}}
        <flux:popover class="w-[360px] max-w-[calc(100vw-2rem)] p-0! overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-3.5 py-2.5 dark:border-zinc-600">
                <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    Notifications
                    @if($unreadCount > 0)
                    <span class="ml-1 text-xs font-medium tabular-nums text-zinc-400 dark:text-zinc-500">{{ $unreadCount }} new</span>
                    @endif
                </span>
                @if($unreadCount > 0)
                <button type="button" wire:click="markAllRead"
                        class="-my-1 -mx-1.5 rounded-md px-1.5 py-1 text-xs font-medium text-accent-content hover:bg-accent/10">
                    Mark all read
                </button>
                @endif
            </div>

            <div class="max-h-[70vh] overflow-y-auto pb-1.5">
                @forelse($notifications as $notification)
                @php
                    $data = $notification->data;
                    $unread = $notification->read_at === null;
                    $type = $data['type'] ?? '';
                    $mention = $type === 'epic_mentioned';
                    [$icon, $verb] = match (true) {
                        $mention => ['at-symbol', 'mentioned you on'],
                        $type === 'epic_commented' && ($data['reply_to_you'] ?? false) => ['arrow-uturn-left', 'replied to you on'],
                        $type === 'epic_commented' && ($data['is_reply'] ?? false) => ['arrow-uturn-left', 'replied on'],
                        $type === 'epic_commented' => ['chat-bubble-left', 'commented on'],
                        $type === 'epic_status_changed' => ['arrow-right', 'moved'],
                        default => ['bell', null],
                    };
                    $group = $day($notification->created_at);
                    $to = $data['to'] ?? null;
                    $toColor = $to ? ($statusColors[$to] ?? null) : null;
                @endphp

                {{-- Day headings are structure, not decoration: the list is
                     what happened since you last looked. --}}
                @if($loop->first || $day($notifications[$loop->index - 1]->created_at) !== $group)
                <div class="px-3.5 pt-2.5 pb-0.5 text-[11px] font-semibold text-zinc-400 dark:text-zinc-500">{{ $group }}</div>
                @endif

                {{-- Unread earns a hairline on the left and darker ink. Read
                     rows go quiet and lose their weight, so the list is not
                     one wall of bold. --}}
                <button type="button" wire:click="open('{{ $notification->id }}')" wire:key="notification-{{ $notification->id }}"
                        class="relative flex w-full items-start gap-2.5 px-3.5 py-2 text-left outline-none
                               hover:bg-zinc-50 focus-visible:bg-zinc-50 dark:hover:bg-zinc-600/40 dark:focus-visible:bg-zinc-600/40"
                        data-test="notification-row">
                    @if($unread)
                    <span aria-hidden="true" class="absolute top-2 bottom-2 left-0 w-0.5 rounded-r bg-accent"></span>
                    @endif

                    <span aria-hidden="true"
                          class="relative grid size-7 shrink-0 place-items-center rounded-full bg-zinc-200 text-[10px] font-semibold tracking-wide text-zinc-600 dark:bg-zinc-600 dark:text-zinc-200">
                        {{ $initials($data['actor'] ?? '?') }}
                        {{-- What kind of thing this is, pinned to the avatar. --}}
                        <span class="absolute -right-1 -bottom-0.5 grid size-[15px] place-items-center rounded-full bg-white ring-[1.5px] ring-white dark:bg-zinc-700 dark:ring-zinc-700
                                     {{ $mention ? 'text-accent-content' : 'text-zinc-500 dark:text-zinc-300' }}">
                            <flux:icon :icon="$icon" variant="micro" class="size-2.5" />
                        </span>
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block text-[13px] leading-[1.4] {{ $unread ? 'text-zinc-900 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400' }}">
                            @if($verb)
                            <span class="{{ $unread ? 'font-medium' : '' }}">{{ $data['actor'] ?? 'Someone' }}</span>
                            {{ $verb }}
                            <span class="{{ $unread ? 'font-medium' : '' }}">{{ $data['epic_title'] ?? 'an epic' }}</span>
                            @if($type === 'epic_status_changed')
                            to
                            @if($toColor)
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-1.5 py-px align-[1px] text-[11px] font-medium"
                                  style="color: {{ $toColor }}; background-color: color-mix(in srgb, {{ $toColor }} 13%, transparent)">
                                <span class="size-1.5 rounded-full" style="background-color: {{ $toColor }}"></span>{{ $to }}
                            </span>
                            @else
                            {{ $to ?? 'nowhere' }}
                            @endif
                            @endif
                            @else
                            {{ $data['message'] ?? 'Notification' }}
                            @endif
                        </span>

                        @if(($data['excerpt'] ?? '') !== '')
                        <span class="mt-0.5 line-clamp-2 block text-xs leading-[1.45] {{ $unread ? 'text-zinc-600 dark:text-zinc-300' : 'text-zinc-400 dark:text-zinc-500' }}">&ldquo;{{ $data['excerpt'] }}&rdquo;</span>
                        @endif
                    </span>

                    <span class="shrink-0 pt-0.5 text-[11px] tabular-nums text-zinc-400 dark:text-zinc-500">{{ $when($notification->created_at) }}</span>
                </button>
                @empty
                <div class="px-5 pt-8 pb-9 text-center">
                    <flux:icon.bell-slash class="mx-auto size-5 text-zinc-300 dark:text-zinc-600" />
                    <p class="mt-2 text-[13px] text-zinc-500 dark:text-zinc-400">Nothing yet.</p>
                    <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">Comment on an epic to follow its conversation.</p>
                </div>
                @endforelse
            </div>
        </flux:popover>
    </flux:dropdown>
</div>
