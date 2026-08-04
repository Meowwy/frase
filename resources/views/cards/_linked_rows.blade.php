@forelse($linkedCards as $linked)
    <tr class="group hover:bg-white/10 js-linked-row" data-linked-id="{{ $linked->id }}">
        <td class="px-4 py-2 whitespace-nowrap font-medium text-white">
            <a href="/cards/{{ $linked->id }}" class="hover:underline">{{ $linked->phrase }}</a>
        </td>
        <td class="px-4 py-2 text-gray-300">{{ $linked->translation }}</td>
        <td class="w-8 px-4 py-2 text-right">
            <button type="button" class="js-unlink text-gray-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition-opacity"
                    aria-label="Unlink term">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </td>
    </tr>
@empty
    <tr class="js-linked-empty">
        <td colspan="3" class="px-4 py-3 text-center text-gray-400">No linked cards yet.</td>
    </tr>
@endforelse
