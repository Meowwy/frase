{{-- The list's rows — base words and fixed expressions alike — rendered with the page and again
     for each live search. --}}
@forelse($entries as ['key' => $key, 'entry' => $entry, 'partOfSpeech' => $entryPartOfSpeech])
    <tr @class(['js-base-row cursor-pointer hover:bg-white/10', 'js-selected' => $selectedKey === $key])>
        {{-- The display form, not the bare lemma: a Swedish noun's article and a
             Swedish verb's dictionary suffix are part of what the learner is
             expected to learn. --}}
        <td class="px-6 py-2 text-sm text-white">
            {{ $entry->displayForm() }}
            @include('base._row-cards', ['title' => $entry->displayForm(), 'cards' => $entry->cards])
        </td>
        <td class="px-6 py-2 text-sm text-gray-400">{{ $entryPartOfSpeech }}</td>
        <td class="px-6 py-2 text-sm text-gray-300">{{ $entry->translation }}</td>
        {{-- Coverage: an entry used across many phrases is owned by no single
             card's review, which is half of why the base exists. --}}
        <td class="px-6 py-2 text-sm text-gray-400">{{ $entry->cards->count() }}</td>
    </tr>
@empty
    <tr>
        <td colspan="4" class="px-6 py-6 text-center text-sm text-gray-400">
            @if($search !== '' || $translation !== '' || $partOfSpeech !== '')
                Nothing matches.
            @else
                Nothing yet — approve a proposal in staging and its words and expressions land here.
            @endif
        </td>
    </tr>
@endforelse
