<?php

use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Picks up any session()->flash('status'/'error', ...) set by any
     * other Livewire component — including the many modal-based ones that
     * never redirect (Egg Purchases, Company Purchases, Personal Ledger,
     * etc.), where the flash was previously invisible: the outer layout
     * only re-renders on a real page load, never on another component's
     * own AJAX update, so a flash set mid-session sat unread until some
     * unrelated future page happened to load it out of context.
     *
     * session()->pull() (read + immediately forget) rather than plain
     * flash reading — this fires from an independent request (this
     * component's own mount/poll), not the request that set the flash, so
     * "survives one more request" semantics would let it wrongly reappear
     * again next poll if not removed here and now.
     */
    public function mount(): void
    {
        $this->checkForFlash();
    }

    public function poll(): void
    {
        $this->checkForFlash();
    }

    private function checkForFlash(): void
    {
        if (session()->has('status')) {
            $this->dispatch('toast', message: session()->pull('status'), type: 'success');
        }

        if (session()->has('error')) {
            $this->dispatch('toast', message: session()->pull('error'), type: 'error');
        }
    }
}; ?>

<div
    x-data="{
        toasts: [],
        navigating: false,
        add(message, type) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, type });
            setTimeout(() => this.remove(id), 5000);
        },
        remove(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
    }"
    x-init="
        // Every redirect() in this app uses navigate: true (SPA-style),
        // so an in-flight poll can otherwise pull() a flash meant for the
        // destination page while still on the old one mid-transition,
        // leaving nothing for that page's own mount() to find. Pausing
        // the poll for the duration of the transition lets mount() on
        // the landed page be the one that reads it, every time.
        window.addEventListener('livewire:navigating', () => { navigating = true });
        window.addEventListener('livewire:navigated', () => { navigating = false });
        setInterval(() => { if (! navigating) { $wire.poll() } }, 1500);
    "
    x-on:toast.window="add($event.detail.message, $event.detail.type)"
    class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-stretch gap-2 p-4 sm:inset-x-auto sm:right-0 sm:items-end print:hidden"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg p-4 text-white shadow-xl ring-1 ring-black/10"
            :class="toast.type === 'error' ? 'bg-red-600' : 'bg-green-600'"
        >
            <svg x-show="toast.type !== 'error'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5 shrink-0">
                <circle cx="12" cy="12" r="9" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12.5l2.5 2.5 5-5" />
            </svg>
            <svg x-show="toast.type === 'error'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5 shrink-0">
                <circle cx="12" cy="12" r="9" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5M12 16h.01" />
            </svg>
            <p class="flex-1 text-sm font-semibold" x-text="toast.message"></p>
            <button type="button" x-on:click="remove(toast.id)" class="shrink-0 text-white/80 hover:text-white">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6" />
                </svg>
            </button>
        </div>
    </template>
</div>
