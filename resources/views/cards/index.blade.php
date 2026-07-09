<x-html-layout>
    <a href="/" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors mb-6">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        <span>back to home</span>
    </a>

    @if($targetLanguages->isNotEmpty())
        <x-wordbox-picker :target-languages="$targetLanguages"
                          :wordboxes-by-language="$wordboxesByLanguage"
                          :active-language-id="$activeLanguageId" />
    @endif

    {{-- Bulk-action bar: always visible, right-aligned; inert + grayed out when nothing is selected. --}}
    <div class="mt-6 flex justify-end">
        <div id="bulkBar" class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 opacity-40 pointer-events-none transition-opacity">
            <span id="bulkCount" class="text-sm text-white/80">0 selected</span>
            <select id="bulkWordbox"
                    class="rounded-lg border border-white/10 bg-[#111] px-2 py-1 text-sm text-white focus:outline-none">
                <option value="" disabled selected>Add to wordbox…</option>
            </select>
            <button type="button" id="bulkDelete" aria-label="Delete selected"
                    class="inline-flex items-center rounded-lg bg-red-600 p-1.5 text-white transition hover:bg-red-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </button>
        </div>
    </div>

    <div class="overflow-x-auto mt-6">
        <table class="min-w-full divide-y divide-gray-700 bg-white/5">
            <thead>
            <tr>
                {{-- Leftmost checkbox column (hover-revealed per row). --}}
                <th class="w-10 px-4 py-3"></th>
                {{-- Search inputs are baked into the header in place of the column titles. --}}
                <th class="px-6 py-3 text-left">
                    <div class="flex items-center gap-2 border-b border-white/60">
                        <svg class="w-4 h-4 shrink-0 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" id="cardSearchTerm" placeholder="Term" autocomplete="off"
                               value="{{ $term }}"
                               class="w-full bg-transparent py-1 text-xs font-medium text-gray-300 placeholder-gray-300 uppercase tracking-wider focus:outline-none focus:text-white focus:placeholder-gray-500">
                    </div>
                </th>
                <th class="px-6 py-3 text-left">
                    <div class="flex items-center gap-2 border-b border-white/60">
                        <svg class="w-4 h-4 shrink-0 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" id="cardSearchDefinition" placeholder="Definition" autocomplete="off"
                               value="{{ $definition }}"
                               class="w-full bg-transparent py-1 text-xs font-medium text-gray-300 placeholder-gray-300 uppercase tracking-wider focus:outline-none focus:text-white focus:placeholder-gray-500">
                    </div>
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Wordbox</th>
                <th class="w-10 px-4 py-3"></th>
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

    {{-- Shared confirm modal for both bulk delete and single-row (3-dot) delete. --}}
    <x-modal name="confirm-delete-cards" title="Delete terms?">
        <p class="text-white/60 mb-6"><span id="confirmDeleteMsg"></span> This action cannot be undone.</p>
        <div class="flex justify-end gap-3">
            <button type="button" onclick="closeModal('confirm-delete-cards')"
                    class="px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all">
                Cancel
            </button>
            <button type="button" id="confirmDeleteBtn"
                    class="px-5 py-2 rounded-xl font-bold bg-red-600 hover:bg-red-500 text-white transition-all">
                Delete
            </button>
        </div>
    </x-modal>

    <script>
        // Live vocabulary list: the shared picker supplies language + wordbox, the two
        // header inputs supply the term/definition search. Every change re-fetches the
        // filtered rows from /cards (AJAX) and swaps the table body + pagination.
        $(document).ready(function () {
            const init = window.WordboxPicker ? window.WordboxPicker.current() : { languageId: '{{ $activeLanguageId }}', wordbox: 'all' };
            const filter = { languageId: init.languageId, wordbox: init.wordbox };
            let debounce;

            // Wordboxes grouped by language, for the "Add to wordbox" dropdown (no backend call).
            const wordboxesByLanguage = @json($wordboxesByLanguage);
            const csrf = '{{ csrf_token() }}';

            // Row selection state, held outside the tbody (which is swapped on every fetch).
            const selected = new Set();
            let pendingDeleteIds = [];

            function render(data) {
                $('#cardsTableBody').html(data.rows);
                $('#cardsPagination').html(data.pagination);
            }

            // Pass a url to follow a paginate link (it already carries the params);
            // otherwise build the query from the current filter + search inputs.
            // Every fetch resets the selection — it is scoped to the current view.
            function fetchCards(url) {
                selected.clear();
                syncBar();
                $.get(url || '/cards', url ? {} : {
                    language_id: filter.languageId,
                    wordbox: filter.wordbox,
                    term: $('#cardSearchTerm').val(),
                    definition: $('#cardSearchDefinition').val(),
                }, render);
            }

            // Keep the count in sync and gray out / disable the bar when nothing is selected.
            function syncBar() {
                const n = selected.size;
                $('#bulkBar').toggleClass('opacity-40 pointer-events-none', n === 0);
                $('#bulkCount').text(n + ' selected');
            }

            // Repopulate the "Add to wordbox" dropdown for the given language.
            function rebuildWordboxSelect(langId) {
                const boxes = wordboxesByLanguage[langId] || [];
                const $sel = $('#bulkWordbox');
                $sel.empty().append($('<option>', { value: '', disabled: true, selected: true, text: 'Add to wordbox…' }));
                boxes.forEach(function (wb) {
                    $sel.append($('<option>', { value: wb.id, text: wb.name }));
                });
                $sel.prop('disabled', boxes.length === 0);
            }

            document.addEventListener('wordboxpicker:change', function (e) {
                filter.languageId = e.detail.languageId;
                filter.wordbox = e.detail.wordbox;
                rebuildWordboxSelect(e.detail.languageId);
                fetchCards();
            });

            $('#cardSearchTerm, #cardSearchDefinition').on('input', function () {
                clearTimeout(debounce);
                debounce = setTimeout(() => fetchCards(), 250);
            });

            $('#cardsPagination').on('click', 'a', function (e) {
                e.preventDefault();
                const url = $(this).attr('href');
                if (url) { fetchCards(url); }
            });

            // --- Row interactions (delegated, since rows are re-rendered on every fetch) ---

            // Navigate to the card unless the click came from a control (checkbox / actions).
            $('#cardsTableBody').on('click', '.js-card-row', function (e) {
                if ($(e.target).closest('.js-card-check, .js-card-actions').length) { return; }
                window.location = '/cards/' + $(this).data('card-id');
            });

            $('#cardsTableBody').on('change', '.js-card-checkbox', function (e) {
                e.stopPropagation();
                const id = String($(this).val());
                if (this.checked) { selected.add(id); } else { selected.delete(id); }
                syncBar();
            });

            // 3-dot menu: toggle this row's menu, closing any other open one.
            $('#cardsTableBody').on('click', '.js-card-menu-btn', function (e) {
                e.stopPropagation();
                const $menu = $(this).siblings('.js-card-menu');
                $('.js-card-menu').not($menu).addClass('hidden');
                $menu.toggleClass('hidden');
            });

            // Single-row delete from the menu.
            $('#cardsTableBody').on('click', '.js-card-delete', function (e) {
                e.stopPropagation();
                const $row = $(this).closest('.js-card-row');
                pendingDeleteIds = [String($row.data('card-id'))];
                $('#confirmDeleteMsg').text('This will permanently delete “' + $row.data('card-term') + '”.');
                $('.js-card-menu').addClass('hidden');
                openModal('confirm-delete-cards');
            });

            // Close any open row menu when clicking elsewhere.
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.js-card-actions').length) {
                    $('.js-card-menu').addClass('hidden');
                }
            });

            // --- Bulk bar actions ---

            $('#bulkDelete').on('click', function () {
                if (selected.size === 0) { return; }
                pendingDeleteIds = [...selected];
                const n = pendingDeleteIds.length;
                $('#confirmDeleteMsg').text('This will permanently delete ' + n + ' term' + (n === 1 ? '' : 's') + '.');
                openModal('confirm-delete-cards');
            });

            $('#confirmDeleteBtn').on('click', function () {
                if (pendingDeleteIds.length === 0) { return; }
                $.post('/cards/bulk-destroy', { ids: pendingDeleteIds, _token: csrf }, function () {
                    location.reload();
                });
            });

            $('#bulkWordbox').on('change', function () {
                const wordboxId = $(this).val();
                if (!wordboxId || selected.size === 0) { return; }
                $.post('/cards/assign-wordbox', { ids: [...selected], wordbox_id: wordboxId, _token: csrf }, function () {
                    location.reload();
                });
            });

            // Initial dropdown population for the starting language.
            rebuildWordboxSelect(filter.languageId);
        });
    </script>
</x-html-layout>
