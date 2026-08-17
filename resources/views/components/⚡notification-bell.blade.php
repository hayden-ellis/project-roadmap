<?php

use App\Models\Epic;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The bell. One instance lives in the sidebar and one in the mobile
 * header; the variant only changes the trigger, the panel is shared.
 *
 * Notifications are read the moment they are opened, not the moment the
 * panel is -- glancing at the list costs nothing.
 */
new class extends Component
{
    /** 'sidebar' or 'header'; picks the trigger the dropdown hangs off. */
    public string $variant = 'sidebar';

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
    <flux:dropdown position="bottom" align="{{ $variant === 'header' ? 'end' : 'start' }}" class="{{ $variant === 'sidebar' ? 'w-full' : '' }}">

        @if($variant === 'header')
        <button type="button" class="relative p-2 rounded-lg text-zinc-500 dark:text-zinc-400 hover:bg-zinc-800/5 dark:hover:bg-white/10 transition-colors">
            <flux:icon icon="bell" class="size-5" />
            @if($unreadCount > 0)
            <span class="absolute top-0.5 right-0.5 min-w-4 h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-semibold leading-4 text-center tabular-nums">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
            @endif
        </button>
        @else
        {{-- Styled after the teams dropdown so it survives the collapsed sidebar. --}}
        <button type="button" class="w-full group flex items-center gap-2 px-2 py-1.5 rounded-lg text-zinc-500 dark:text-zinc-400 hover:bg-zinc-800/5 dark:hover:bg-white/10 hover:text-zinc-800 dark:hover:text-white transition-colors in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:px-0">
            <span class="relative shrink-0">
                <flux:icon icon="bell" variant="outline" class="size-5" />
                @if($unreadCount > 0)
                <span class="absolute -top-1 -right-1 size-2 rounded-full bg-red-500 in-data-flux-sidebar-collapsed-desktop:block hidden"></span>
                @endif
            </span>
            <span class="flex-1 text-left text-sm font-medium in-data-flux-sidebar-collapsed-desktop:hidden">Notifications</span>
            @if($unreadCount > 0)
            <span class="shrink-0 min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-semibold leading-5 text-center tabular-nums in-data-flux-sidebar-collapsed-desktop:hidden">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
            @endif
        </button>
        @endif

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
