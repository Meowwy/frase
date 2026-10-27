{{-- Below lg each row stacks into a list item: the checkbox fills a left gutter (the whole
     gutter is its label, a full-height tap target), Term over translation, then the date, and
     the 3-dot menu in a right gutter. With no hover on touch, both controls are
     always shown there. --}}
@forelse($cards as $card)
    <tr class="group hover:bg-white/10 cursor-pointer js-card-row max-lg:relative max-lg:flex max-lg:flex-wrap max-lg:items-baseline max-lg:gap-x-2 max-lg:py-3 max-lg:pl-12 max-lg:pr-12"
        data-card-id="{{ $card->id }}" data-card-term="{{ $card->term }}">
        <td class="w-10 px-4 py-2 js-card-check max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:w-12 max-lg:p-0">
            <label class="max-lg:absolute max-lg:inset-0 max-lg:flex max-lg:cursor-pointer max-lg:items-center max-lg:justify-center">
                <input type="checkbox" value="{{ $card->id }}"
                       class="js-card-checkbox h-4 w-4 rounded border-white/30 bg-transparent align-middle cursor-pointer opacity-0 group-hover:opacity-100 checked:opacity-100 transition-opacity max-lg:h-5 max-lg:w-5 max-lg:opacity-100">
            </label>
        </td>
        {{-- No nowrap: an expression frame ("can you hand me the ...") would stretch the column. --}}
        <td class="px-6 py-2 text-sm text-white max-lg:basis-full max-lg:p-0 max-lg:text-base max-lg:font-medium">{{ $card->term }}</td>
        <td class="px-6 py-2 text-sm text-gray-300 max-lg:basis-full max-lg:p-0">{{ $card->translation }}</td>
        {{-- In the learner's timezone, so a card saved late at night shows the day they saved it. --}}
        <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300 max-lg:mt-1 max-lg:p-0 max-lg:text-xs max-lg:text-white/40">{{ $card->created_at->timezone(Auth::user()->timezone ?? config('app.timezone'))->format('j M Y') }}</td>
        <td class="w-10 px-4 py-2 text-right relative js-card-actions max-lg:absolute max-lg:right-0 max-lg:top-1/2 max-lg:-translate-y-1/2 max-lg:p-0">
            <button type="button"
                    class="js-card-menu-btn text-gray-400 hover:text-white opacity-0 group-hover:opacity-100 transition-opacity max-lg:flex max-lg:h-11 max-lg:w-11 max-lg:items-center max-lg:justify-center max-lg:opacity-100"
                    aria-label="Row options">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 6a2 2 0 110-4 2 2 0 010 4zm0 8a2 2 0 110-4 2 2 0 010 4zm0 8a2 2 0 110-4 2 2 0 010 4z"></path>
                </svg>
            </button>
            <div class="js-card-menu hidden absolute right-4 top-10 z-20 w-32 rounded-lg border border-white/10 bg-[#111] py-1 text-left shadow-xl max-lg:right-2 max-lg:top-11">
                <button type="button" class="js-card-delete block w-full px-4 py-2 text-sm text-red-500 hover:bg-white/10 max-lg:py-3 max-lg:text-base">
                    Delete
                </button>
            </div>
        </td>
    </tr>
@empty
    <tr class="max-lg:block">
        <td colspan="5" class="px-6 py-6 text-center text-sm text-gray-400 max-lg:block">No terms found.</td>
    </tr>
@endforelse
