{{-- The Expressions tab's rows, rendered with the page and again for each live search. --}}
@forelse($expressions as $expression)
    <tr class="js-base-row cursor-pointer hover:bg-white/10">
        <td class="px-6 py-2 text-sm text-white">
            {{ $expression->form }}
            @include('base._row-cards', ['title' => $expression->form, 'cards' => $expression->cards])
        </td>
        <td class="px-6 py-2 text-sm text-gray-300">{{ $expression->translation }}</td>
        <td class="px-6 py-2 text-sm text-gray-400">{{ $expression->cards->count() }}</td>
    </tr>
@empty
    <tr>
        <td colspan="3" class="px-6 py-6 text-center text-sm text-gray-400">
            @if($search !== '' || $translation !== '')
                No expressions match.
            @else
                No fixed expressions yet — they are picked out of the terms you capture.
            @endif
        </td>
    </tr>
@endforelse
