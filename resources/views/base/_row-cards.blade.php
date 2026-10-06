{{-- One row's side-panel content (see base._panel): its title and the Terms of its cards. --}}
<template class="js-row-cards">
    <p class="mb-2 font-bold text-white">{{ $title }}</p>
    @forelse($cards as $card)
        <a href="/cards/{{ $card->id }}" class="block py-0.5 text-gray-300 hover:text-white">{{ $card->term }}</a>
    @empty
        <p class="text-white/40">Not part of any card yet.</p>
    @endforelse
</template>
