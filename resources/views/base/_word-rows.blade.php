{{-- The Words tab's rows, rendered with the page and again for each live search. --}}
@forelse($baseWords as $baseWord)
    <tr @class(['js-base-row cursor-pointer hover:bg-white/10', 'js-selected' => $selectedId === $baseWord->id])>
        {{-- The display form, not the bare lemma: a Swedish noun's article and a
             Swedish verb's dictionary suffix are part of what the learner is
             expected to learn. --}}
        <td class="px-6 py-2 text-sm text-white">
            {{ $baseWord->displayForm() }}
            @include('base._row-cards', ['title' => $baseWord->displayForm(), 'cards' => $baseWord->cards])
        </td>
        <td class="px-6 py-2 text-sm text-gray-400">{{ $baseWord->part_of_speech }}</td>
        <td class="px-6 py-2 text-sm text-gray-300">{{ $baseWord->translation }}</td>
        {{-- Coverage: a word used across many phrases is owned by no single
             card's review, which is half of why the base exists. --}}
        <td class="px-6 py-2 text-sm text-gray-400">{{ $baseWord->cards->count() }}</td>
    </tr>
@empty
    <tr>
        <td colspan="4" class="px-6 py-6 text-center text-sm text-gray-400">
            @if($search !== '' || $translation !== '' || $partOfSpeech !== '')
                No words match.
            @else
                No words yet — approve a proposal in staging and its words land here.
            @endif
        </td>
    </tr>
@endforelse
