<?php

use App\Models\Epic;
use Illuminate\Support\Facades\Auth;
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
        ];
    }
};
?>

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

        <flux:menu class="w-[340px] max-w-[90vw]">
            <div class="flex items-center justify-between px-3 py-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Notifications</span>
                @if($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                    Mark all read
                </button>
                @endif
            </div>

            <flux:menu.separator />

            @forelse($notifications as $notification)
            @php $data = $notification->data; @endphp
            <flux:menu.item wire:click="open('{{ $notification->id }}')" wire:key="notification-{{ $notification->id }}">
                <div class="flex items-start gap-2.5 py-0.5 w-full">
                    <span class="mt-1.5 size-2 rounded-full shrink-0 {{ $notification->read_at ? 'bg-transparent' : 'bg-indigo-500' }}"></span>

                    <div class="flex-1 min-w-0">
                        <p class="text-sm leading-snug {{ $notification->read_at ? 'text-zinc-500 dark:text-zinc-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                            @if(($data['type'] ?? '') === 'epic_commented')
                            <span class="font-semibold">{{ $data['actor'] }}</span>
                            {{ ($data['is_reply'] ?? false) ? 'replied to a thread on' : 'commented on' }}
                            <span class="font-semibold">{{ $data['epic_title'] }}</span>
                            @elseif(($data['type'] ?? '') === 'epic_mentioned')
                            <span class="font-semibold">{{ $data['actor'] }}</span>
                            mentioned you on
                            <span class="font-semibold">{{ $data['epic_title'] }}</span>
                            @elseif(($data['type'] ?? '') === 'epic_status_changed')
                            <span class="font-semibold">{{ $data['actor'] }}</span>
                            moved <span class="font-semibold">{{ $data['epic_title'] }}</span>
                            @if($data['from'] ?? null) from {{ $data['from'] }} @endif
                            to {{ $data['to'] ?? 'nowhere' }}
                            @else
                            {{ $data['message'] ?? 'Notification' }}
                            @endif
                        </p>

                        @if(($data['excerpt'] ?? '') !== '')
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 truncate">&ldquo;{{ $data['excerpt'] }}&rdquo;</p>
                        @endif

                        <p class="mt-0.5 text-[11px] text-zinc-400 dark:text-zinc-500">{{ $notification->created_at->diffForHumans() }}</p>
                    </div>
                </div>
            </flux:menu.item>
            @empty
            <div class="px-3 py-8 text-center">
                <flux:icon icon="bell-slash" class="mx-auto size-5 text-zinc-300 dark:text-zinc-600" />
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Nothing yet.</p>
                <p class="text-xs text-zinc-400 dark:text-zinc-500">Comment on an epic to follow its conversation.</p>
            </div>
            @endforelse
        </flux:menu>
    </flux:dropdown>
</div>
