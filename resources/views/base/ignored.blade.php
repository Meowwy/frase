<x-html-layout>
    {{-- The ignored words of one language (docs/cards.md "Ignored words"): staging never
         proposes them as new base words. Same table look as /base. --}}
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold">Ignored words</h1>
        <a href="{{ route('base', ['language_id' => $activeLanguageId]) }}" class="text-sm text-white/60 hover:text-white hover:underline max-lg:py-2">← Vocabulary base</a>
    </div>

    @if($targetLanguages->count() > 1)
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($targetLanguages as $language)
                <a href="{{ route('base.ignored', ['language_id' => $language->id]) }}"
                   @class([
                       'rounded-lg border border-white/10 px-3 py-1 text-sm transition-colors max-lg:py-2.5',
                       'bg-blue-600/30 ring-1 ring-blue-500' => (int) $activeLanguageId === $language->id,
                       'bg-white/5 hover:bg-white/10' => (int) $activeLanguageId !== $language->id,
                   ])>
                    {{ $language->flag }} {{ $language->name }}
                </a>
            @endforeach
        </div>
    @endif

    <p class="mb-4 text-sm text-white/50">
        Words you struck in staging, or marked as ignored when deleting them from the base. Staging never
        proposes them again, in any form. Remove one and it can be proposed again.
    </p>

    {{-- Only a lexicon word can be ignored up front, so its lemma and part of speech match what
         staging would name. --}}
    @if($hasLexicon)
        <div class="mb-6 max-w-lg">
            <x-lexicon-search id="ignoredAddSearch" :language-id="$activeLanguageId" action="Ignore" placeholder="Search the dictionary to ignore a word…" />
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 bg-white/5 max-lg:block max-lg:rounded-xl">
            <thead class="max-lg:hidden">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Word</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Lemma</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Part of speech</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Ignored</th>
                <th class="w-12 px-4 py-3"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-700 max-lg:block">
            @forelse($words as $word)
                <tr class="js-ignored-row hover:bg-white/10 max-lg:relative max-lg:flex max-lg:flex-wrap max-lg:items-baseline max-lg:gap-x-2 max-lg:py-3 max-lg:pl-4 max-lg:pr-16">
                    <td class="px-6 py-2 text-sm text-white max-lg:basis-full max-lg:p-0 max-lg:text-base max-lg:font-medium">{{ $displayForms[$word->id] ?? $word->lemma }}</td>
                    <td class="px-6 py-2 text-sm text-gray-300 max-lg:p-0 max-lg:text-xs">{{ $word->lemma }}</td>
                    <td class="px-6 py-2 text-sm text-gray-400 max-lg:p-0 max-lg:text-xs">{{ $word->part_of_speech }}</td>
                    <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300 max-lg:p-0 max-lg:text-xs max-lg:text-white/40">{{ $word->created_at?->timezone(Auth::user()->timezone ?? config('app.timezone'))->format('j M Y') }}</td>
                    <td class="px-4 py-2 text-right max-lg:absolute max-lg:inset-y-0 max-lg:right-2 max-lg:flex max-lg:items-center max-lg:p-0">
                        <button type="button" class="js-unignore text-white/50 hover:text-white max-lg:min-h-11 max-lg:min-w-11" data-id="{{ $word->id }}" title="Remove from the list — staging can propose it again">✕</button>
                    </td>
                </tr>
            @empty
                <tr class="max-lg:block">
                    <td colspan="5" class="px-6 py-6 text-center text-sm text-gray-400 max-lg:block">No ignored words in this language.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $words->links() }}</div>

    <script>
        $(function () {
            const csrf = '{{ csrf_token() }}';

            $('.js-unignore').on('click', function () {
                const $row = $(this).closest('.js-ignored-row');
                $.ajax({ url: '/base/ignored/' + $(this).data('id'), type: 'DELETE', data: { _token: csrf } })
                    .done(() => $row.remove())
                    .fail(() => window.toastr && toastr.error('Could not remove it.'));
            });

            $('#ignoredAddSearch').on('lexicon:pick', function (e, result, $button) {
                $button.prop('disabled', true);
                $.post('/base/ignored', { _token: csrf, language_id: '{{ $activeLanguageId }}', lemma: result.lemma, part_of_speech: result.part_of_speech })
                    .done(() => window.location.reload())
                    .fail(function (xhr) {
                        if (window.toastr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Could not ignore it.'); }
                        $button.prop('disabled', false);
                    });
            });
        });
    </script>
</x-html-layout>
