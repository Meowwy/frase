{{-- One row's side-panel content (see base._panel): its title, the bin that deletes it (or, in a
     checked entry's panel, the X that unchecks it), the Terms of its cards and its synonyms. --}}
<template class="js-row-cards">
    <div class="mb-2 flex items-start justify-between gap-2">
        <div>
            <p class="font-bold text-white">{{ $title }}</p>
            <p class="text-white/50">{{ $subtitle }}</p>
        </div>
        <button type="button" aria-label="Delete" data-key="{{ $key }}" data-title="{{ $title }}"
                class="js-base-delete shrink-0 text-white/40 transition hover:text-red-500 max-lg:-m-2.5 max-lg:p-2.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
        </button>
        <button type="button" aria-label="Uncheck" data-key="{{ $key }}"
                class="js-base-uncheck hidden shrink-0 text-white/40 transition hover:text-white max-lg:-m-2.5 max-lg:p-2.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>
    @forelse($cards as $card)
        <a href="/cards/{{ $card->id }}" class="block py-0.5 text-gray-300 hover:text-white max-lg:py-2 max-lg:text-base">{{ $card->term }}</a>
    @empty
        <p class="text-white/40">Not part of any card yet.</p>
    @endforelse

    @if($synonyms->isNotEmpty())
        <p class="mt-4 mb-2 text-xs uppercase tracking-wider text-white/40">Synonyms</p>
        <div class="flex flex-wrap gap-2">
            @foreach($synonyms as $synonym)
                <span class="group inline-flex items-center gap-1 rounded-lg border border-white/10 bg-white/5 px-3 py-1">
                    {{ $synonym->displayForm() }}
                    <span class="text-xs text-white/40">{{ $synonym->translation }}</span>
                    <button type="button" aria-label="Unlink synonym" data-first="{{ $key }}" data-second="{{ $synonym->entryKey() }}"
                            class="js-synonym-unlink text-gray-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition-opacity max-lg:-my-2 max-lg:-mr-2 max-lg:p-2 max-lg:opacity-100">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </span>
            @endforeach
        </div>
    @endif
</template>
