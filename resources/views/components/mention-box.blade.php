{{--
    Wraps a comment textarea with an @mention picker.

    Typing "@" opens a list of team members under the box, narrowed by
    whatever follows. Picking one puts their full name in the text -- that
    plain "@Full Name" is what App\Support\Mentions resolves on save, so the
    picker is a convenience, never a requirement. The list sits under the
    textarea rather than at the caret: the box is only a few lines tall, so
    "just below" is always near enough, and it never gets clipped.

    Usage: <x-mention-box :members="$mentionable"><flux:textarea .../></x-mention-box>
    where $members is [{id, name, avatar}, ...].
--}}
@props(['members'])

<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data="{
        members: @js($members),
        open: false,
        query: null,
        start: -1,
        index: 0,
        skip: false,

        get matches() {
            if (this.query === null) return [];
            const q = this.query.toLowerCase();
            return this.members.filter(m => m.name.toLowerCase().includes(q)).slice(0, 6);
        },

        textarea() { return $el.querySelector('textarea'); },

        // The '@' that owns the caret: the nearest one behind it that starts
        // a word, with no line break between. Names have spaces, so a space
        // does not end the query -- running out of matches does.
        locate() {
            const el = this.textarea();
            const upto = el.value.slice(0, el.selectionStart);
            const at = upto.lastIndexOf('@');

            if (at === -1 || upto.slice(at).includes('\n') || (at > 0 && /[\p{L}\p{N}]/u.test(upto[at - 1]))) {
                return this.close();
            }

            this.start = at;
            this.query = upto.slice(at + 1);
            this.index = 0;
            this.open = this.matches.length > 0;
        },

        onInput() {
            if (this.skip) { this.skip = false; return; }
            this.locate();
        },

        onKeydown(e) {
            if (!this.open) return;

            if (e.key === 'ArrowDown') { e.preventDefault(); this.index = (this.index + 1) % this.matches.length; }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.index = (this.index - 1 + this.matches.length) % this.matches.length; }
            else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); this.pick(this.matches[this.index]); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(); }
        },

        pick(member) {
            if (!member) return;
            const el = this.textarea();
            const before = el.value.slice(0, this.start);
            const after = el.value.slice(el.selectionStart);
            const inserted = '@' + member.name + ' ';

            el.value = before + inserted + after;
            el.selectionStart = el.selectionEnd = before.length + inserted.length;

            // Tell wire:model, but not ourselves -- the name just inserted
            // would otherwise reopen the list on its own text.
            this.skip = true;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            this.close();
            el.focus();
        },

        close() { this.open = false; this.query = null; this.start = -1; },
     }"
     x-on:input="onInput()"
     x-on:keydown="onKeydown($event)"
     x-on:focusout="if (!$el.contains($event.relatedTarget)) close()">

    {{ $slot }}

    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
         class="absolute left-0 top-full z-30 mt-1 w-64 max-w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
         role="listbox">
        <template x-for="(member, i) in matches" :key="member.id">
            <button type="button" role="option" :aria-selected="i === index"
                    x-on:mousedown.prevent
                    x-on:click="pick(member)"
                    x-on:mousemove="index = i"
                    :class="i === index ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                    class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-sm text-zinc-800 dark:text-zinc-100">
                <img :src="member.avatar" :alt="member.name" class="size-5 shrink-0 rounded-full object-cover bg-zinc-200 dark:bg-zinc-700">
                <span x-text="member.name" class="truncate"></span>
            </button>
        </template>
    </div>
</div>
