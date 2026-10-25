{{-- The list's rows — base words and fixed expressions alike — rendered with the page and again
     for each live search. --}}
{{-- Below lg each row stacks into a list item, like /cards: the checkbox fills a left gutter
     (always shown, no hover on touch), the display form over its part of speech and
     translation, the card count top right. --}}
@forelse($entries as ['key' => $key, 'entry' => $entry, 'partOfSpeech' => $entryPartOfSpeech, 'synonyms' => $synonyms])
    <tr data-key="{{ $key }}" @class(['group js-base-row cursor-pointer hover:bg-white/10 max-lg:relative max-lg:flex max-lg:flex-wrap max-lg:items-baseline max-lg:gap-x-2 max-lg:py-3 max-lg:pl-12 max-lg:pr-20', 'js-selected' => $selectedKey === $key])>
        {{-- Checking two entries marks them as synonyms (see base._panel). --}}
        <td class="w-10 px-4 py-2 js-base-check max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:w-12 max-lg:p-0">
            <label class="max-lg:absolute max-lg:inset-0 max-lg:flex max-lg:cursor-pointer max-lg:items-center max-lg:justify-center">
                <input type="checkbox" data-key="{{ $key }}"
                       class="js-base-checkbox h-4 w-4 rounded border-white/30 bg-transparent align-middle cursor-pointer opacity-0 group-hover:opacity-100 checked:opacity-100 transition-opacity max-lg:h-5 max-lg:w-5 max-lg:opacity-100">
            </label>
        </td>
        {{-- The display form, not the bare lemma: a Swedish noun's article and a
             Swedish verb's dictionary suffix are part of what the learner is
             expected to learn. --}}
        <td class="px-6 py-2 text-sm text-white max-lg:basis-full max-lg:p-0 max-lg:text-base max-lg:font-medium">
            {{ $entry->displayForm() }}
            @include('base._row-cards', [
                'title' => $entry->displayForm(),
                'subtitle' => $entryPartOfSpeech.' · '.$entry->translation,
                'cards' => $entry->cards,
                'key' => $key,
                'synonyms' => $synonyms,
            ])
        </td>
        <td class="px-6 py-2 text-sm text-gray-400 max-lg:p-0 max-lg:text-xs">{{ $entryPartOfSpeech }}</td>
        <td class="px-6 py-2 text-sm text-gray-300 max-lg:p-0">{{ $entry->translation }}</td>
        {{-- Coverage: an entry used across many phrases is owned by no single
             card's review, which is half of why the base exists. --}}
        <td class="px-6 py-2 text-sm text-gray-400 max-lg:absolute max-lg:right-4 max-lg:top-3 max-lg:p-0 max-lg:text-xs">{{ $entry->cards->count() }}<span class="lg:hidden"> {{ Str::plural('card', $entry->cards->count()) }}</span></td>
    </tr>
@empty
    <tr class="max-lg:block">
        <td colspan="5"class="px-6 py-6 text-center text-sm text-gray-400 max-lg:block">
            @if($search !== '' || $translation !== '' || $partOfSpeech !== '')
                Nothing matches.
            @else
                Nothing yet — approve a proposal in staging and its words and expressions land here.
            @endif
        </td>
    </tr>
@endforelse
