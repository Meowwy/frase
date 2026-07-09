@forelse($cards as $card)
    <tr class="group hover:bg-white/10 cursor-pointer js-card-row"
        data-card-id="{{ $card->id }}" data-card-term="{{ $card->phrase }}">
        <td class="w-10 px-4 py-2 js-card-check">
            <input type="checkbox" value="{{ $card->id }}"
                   class="js-card-checkbox h-4 w-4 rounded border-white/30 bg-transparent align-middle cursor-pointer opacity-0 group-hover:opacity-100 checked:opacity-100 transition-opacity">
        </td>
        <td class="px-6 py-2 whitespace-nowrap text-sm font-medium text-white">{{ $card->phrase }}</td>
        <td class="px-6 py-2 text-sm text-gray-300">
            <div class="max-w-xs truncate" title="{{ $card->definition }}">{{ $card->definition }}</div>
        </td>
        <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300">{{ $card->wordbox->first()?->name }}</td>
        <td class="w-10 px-4 py-2 text-right relative js-card-actions">
            <button type="button"
                    class="js-card-menu-btn text-gray-400 hover:text-white opacity-0 group-hover:opacity-100 transition-opacity"
                    aria-label="Row options">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 6a2 2 0 110-4 2 2 0 010 4zm0 8a2 2 0 110-4 2 2 0 010 4zm0 8a2 2 0 110-4 2 2 0 010 4z"></path>
                </svg>
            </button>
            <div class="js-card-menu hidden absolute right-4 top-10 z-20 w-32 rounded-lg border border-white/10 bg-[#111] py-1 text-left shadow-xl">
                <button type="button" class="js-card-delete block w-full px-4 py-2 text-sm text-red-500 hover:bg-white/10">
                    Delete
                </button>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" class="px-6 py-6 text-center text-sm text-gray-400">No terms found.</td>
    </tr>
@endforelse
