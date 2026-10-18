{{-- One row's side-panel content (see base._panel): its title, the bin that deletes it, and the
     Terms of its cards. --}}
<template class="js-row-cards">
    <div class="mb-2 flex items-start justify-between gap-2">
        <p class="font-bold text-white">{{ $title }}</p>
        <button type="button" aria-label="Delete" data-key="{{ $key }}" data-title="{{ $title }}"
                class="js-base-delete shrink-0 text-white/40 transition hover:text-red-500">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
        </button>
    </div>
    @forelse($cards as $card)
        <a href="/cards/{{ $card->id }}" class="block py-0.5 text-gray-300 hover:text-white">{{ $card->term }}</a>
    @empty
        <p class="text-white/40">Not part of any card yet.</p>
    @endforelse
</template>
