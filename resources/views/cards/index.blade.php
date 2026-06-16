<x-html-layout>
    @if($targetLanguages->isNotEmpty())
        <x-wordbox-picker :target-languages="$targetLanguages"
                          :wordboxes-by-language="$wordboxesByLanguage"
                          :active-language-id="$activeLanguageId" />
    @endif

    <div class="overflow-x-auto mt-6">
        <table class="min-w-full divide-y divide-gray-700 bg-white/5">
            <thead>
            <tr>
                {{-- Search inputs are baked into the header in place of the column titles. --}}
                <th class="px-6 py-3 text-left">
                    <input type="text" id="searchTerm" placeholder="Term" autocomplete="off"
                           value="{{ $term }}"
                           class="w-full bg-transparent text-xs font-medium text-gray-300 placeholder-gray-300 uppercase tracking-wider focus:outline-none focus:text-white focus:placeholder-gray-500">
                </th>
                <th class="px-6 py-3 text-left">
                    <input type="text" id="searchDefinition" placeholder="Definition" autocomplete="off"
                           value="{{ $definition }}"
                           class="w-full bg-transparent text-xs font-medium text-gray-300 placeholder-gray-300 uppercase tracking-wider focus:outline-none focus:text-white focus:placeholder-gray-500">
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Wordbox</th>
            </tr>
            </thead>
            <tbody id="cardsTableBody" class="divide-y divide-gray-700">
            @include('cards._rows', ['cards' => $cards])
            </tbody>
        </table>
        <div id="cardsPagination" class="mt-4">
            {{ $cards->links() }}
        </div>
    </div>

    <script>
        // Live vocabulary list: the shared picker supplies language + wordbox, the two
        // header inputs supply the term/definition search. Every change re-fetches the
        // filtered rows from /cards (AJAX) and swaps the table body + pagination.
        $(document).ready(function () {
            const init = window.WordboxPicker ? window.WordboxPicker.current() : { languageId: '{{ $activeLanguageId }}', wordbox: 'all' };
            const filter = { languageId: init.languageId, wordbox: init.wordbox };
            let debounce;

            function render(data) {
                $('#cardsTableBody').html(data.rows);
                $('#cardsPagination').html(data.pagination);
            }

            // Pass a url to follow a paginate link (it already carries the params);
            // otherwise build the query from the current filter + search inputs.
            function fetchCards(url) {
                $.get(url || '/cards', url ? {} : {
                    language_id: filter.languageId,
                    wordbox: filter.wordbox,
                    term: $('#searchTerm').val(),
                    definition: $('#searchDefinition').val(),
                }, render);
            }

            document.addEventListener('wordboxpicker:change', function (e) {
                filter.languageId = e.detail.languageId;
                filter.wordbox = e.detail.wordbox;
                fetchCards();
            });

            $('#searchTerm, #searchDefinition').on('input', function () {
                clearTimeout(debounce);
                debounce = setTimeout(() => fetchCards(), 250);
            });

            $('#cardsPagination').on('click', 'a', function (e) {
                e.preventDefault();
                const url = $(this).attr('href');
                if (url) { fetchCards(url); }
            });
        });
    </script>
</x-html-layout>
