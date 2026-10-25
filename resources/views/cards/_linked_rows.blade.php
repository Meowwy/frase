{{-- Below lg a row stacks: Term over translation, the unlink X (always shown, no hover on
     touch) in a right column. Mirrored by the row the link script in cards.show builds. --}}
@forelse($linkedCards as $linked)
    <tr class="group hover:bg-white/10 js-linked-row max-lg:grid max-lg:grid-cols-[1fr_auto] max-lg:items-center max-lg:px-4 max-lg:py-2" data-linked-id="{{ $linked->id }}">
        <td class="px-4 py-2 whitespace-nowrap text-white max-lg:col-start-1 max-lg:p-0 max-lg:whitespace-normal">
            <a href="/cards/{{ $linked->id }}" class="hover:underline">{{ $linked->term }}</a>
        </td>
        <td class="px-4 py-2 text-gray-300 max-lg:col-start-1 max-lg:p-0">{{ $linked->translation }}</td>
        <td class="w-8 px-4 py-2 text-right max-lg:col-start-2 max-lg:row-span-2 max-lg:row-start-1 max-lg:w-auto max-lg:p-0">
            <button type="button" class="js-unlink text-gray-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition-opacity max-lg:flex max-lg:h-11 max-lg:w-11 max-lg:items-center max-lg:justify-center max-lg:opacity-100"
                    aria-label="Unlink term">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </td>
    </tr>
@empty
    <tr class="js-linked-empty max-lg:block">
        <td colspan="3" class="px-4 py-3 text-center text-gray-400 max-lg:block">No linked cards yet.</td>
    </tr>
@endforelse
