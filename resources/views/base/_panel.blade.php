{{-- /base's side panels, and the live search over the table — a wordbox's page has the same
     (base._table). The first panel is empty until a row is clicked, then it shows the Terms of
     the cards that word or expression is part of, and its synonyms. Each row carries its own
     content in a template (the cards are eager-loaded with the page), so a click costs no
     request. The panel's bin deletes the entry, after a dialog that for a base word also offers
     marking it known.

     Each checked row gets a panel of its own below, kept across searches and pages, and two
     checked entries can be marked as synonyms. On /base checked words can be added to a
     wordbox of the page's language (expressions never go in one); on a wordbox's page they can
     be taken out of it.

     Below lg the two jobs come apart, so neither can cover the list, and only one of them is
     ever on screen:
     - with nothing checked, tapping a row opens its panel as a height-capped bottom sheet above
       the tab bar (scrolling inside, closed by its handle or X); tapping that row again closes
       it and deselects the row;
     - once a row is checked, the sheet closes and checked rows get no panels, only a slim action
       bar above the tab bar (#baseActionBar: count, Mark synonyms, the wordbox action, Delete,
       clear) that never grows; while it shows, tapping a row checks or unchecks it instead of
       opening the sheet, and clearing the last check takes the bar away. --}}
<div id="baseSide" class="space-y-4 lg:sticky lg:top-24 lg:w-72 lg:shrink-0 max-lg:fixed max-lg:inset-x-0 max-lg:bottom-[calc(4rem_+_env(safe-area-inset-bottom))] max-lg:z-30 max-lg:flex max-lg:hidden max-lg:max-h-[55dvh] max-lg:flex-col max-lg:space-y-0 max-lg:overflow-y-auto max-lg:overscroll-contain max-lg:rounded-t-2xl max-lg:border-t max-lg:border-white/10 max-lg:bg-[#111] max-lg:px-4 max-lg:pb-4 max-lg:shadow-2xl">
    <aside id="basePanel" class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm max-lg:border-0 max-lg:bg-transparent max-lg:p-0 max-lg:text-base">
        <p class="text-white/40">Select a row to see the cards it is part of.</p>
    </aside>

    {{-- Desktop only: below lg the action bar takes these over. --}}
    <div id="checkedBar" class="hidden space-y-2 max-lg:hidden">
        <x-forms.button id="markSynonyms" type="button" class="w-full">Mark as synonyms</x-forms.button>
        @isset($wordbox)
            <x-forms.button type="button" class="js-wordbox-remove w-full">Remove from wordbox</x-forms.button>
        @elseif($wordboxes->isNotEmpty())
            <select class="js-wordbox-add w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white focus:outline-none">
                <option value="" selected disabled>Add to wordbox…</option>
                @foreach($wordboxes as $box)
                    <option value="{{ $box->id }}">{{ $box->name }}</option>
                @endforeach
            </select>
        @endisset
    </div>

    <div id="checkedPanels" class="space-y-4 max-lg:hidden"></div>

    {{-- The sheet's own header — a drag-handle look and an X, both closing it — kept in view
         while the panel scrolls. Last in the DOM so the desktop column's spacing is untouched. --}}
    <div class="sticky top-0 z-10 -mx-4 mb-2 flex items-center justify-between bg-[#111] px-4 pt-1 lg:hidden max-lg:order-first">
        <span class="w-11"></span>
        <button type="button" aria-label="Close" class="js-base-sheet-close flex h-8 flex-1 items-center justify-center">
            <span class="h-1 w-10 rounded-full bg-white/25"></span>
        </button>
        <button type="button" aria-label="Close" class="js-base-sheet-close flex h-11 w-11 items-center justify-center -mr-2.5 text-white/60">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>
</div>

{{-- Below lg only: what to do with the checked rows. Fixed above the tab bar, one row high
     whatever is checked. --}}
<div id="baseActionBar" class="hidden fixed inset-x-0 bottom-[calc(4rem_+_env(safe-area-inset-bottom))] z-20 border-t border-white/10 bg-[#111] px-4 py-2 lg:hidden">
    <div class="flex items-center gap-2">
        <button type="button" id="baseClearChecks" aria-label="Clear selection"
                class="-ml-2.5 flex h-11 w-11 shrink-0 items-center justify-center text-white/60">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
        <span id="baseCheckedCount" class="min-w-0 flex-1 truncate text-sm text-white/80"></span>
        <button type="button" id="baseBarSynonyms" title="Check exactly two to mark them as synonyms"
                class="min-h-11 shrink-0 rounded bg-blue-800 px-3 text-sm font-bold transition-colors hover:bg-blue-600 disabled:opacity-40">
            Mark synonyms
        </button>
        @isset($wordbox)
            <button type="button"
                    class="js-wordbox-remove min-h-11 shrink-0 rounded bg-white/10 px-3 text-sm font-bold transition-colors hover:bg-white/20">
                Remove
            </button>
        @elseif($wordboxes->isNotEmpty())
            <select class="js-wordbox-add min-h-11 w-28 shrink-0 rounded border border-white/10 bg-[#111] px-2 text-base text-white focus:outline-none">
                <option value="" selected disabled>Wordbox…</option>
                @foreach($wordboxes as $box)
                    <option value="{{ $box->id }}">{{ $box->name }}</option>
                @endforeach
            </select>
        @endisset
        <button type="button" id="baseBarDelete"
                class="min-h-11 shrink-0 rounded bg-red-800 px-3 text-sm font-bold transition-colors hover:bg-red-600">
            Delete
        </button>
    </div>
</div>

<x-modal name="confirm-delete-base" title="Delete from the base?">
    <div class="mb-6 space-y-2 text-white/60">
        <p>
            <span id="baseDeleteTitle" class="font-bold text-white"></span> <span id="baseDeleteVerb">leaves</span> the base, its cards and Frammenti.
            Saving <span id="baseDeleteIt">it</span> in a new card brings it back, with its old cards and progress.
        </p>
        <p id="baseDeleteKnownHint"><span class="font-bold text-white">Mark as known</span> also stops staging from proposing it again.</p>
    </div>
    <div class="flex flex-wrap justify-end gap-3">
        <button type="button" onclick="closeModal('confirm-delete-base')"
                class="px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all max-lg:min-h-12 max-lg:flex-1">
            Cancel
        </button>
        <button type="button" id="baseDeleteKnown" data-known="1"
                class="js-base-delete-confirm px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all max-lg:min-h-12 max-lg:flex-1">
            Mark as known
        </button>
        <button type="button" data-known="0"
                class="js-base-delete-confirm px-5 py-2 rounded-xl font-bold bg-red-600 hover:bg-red-500 text-white transition-all max-lg:min-h-12 max-lg:flex-1">
            Delete
        </button>
    </div>
</x-modal>

<script>
    $(function () {
        const csrf = '{{ csrf_token() }}';
        let debounce;
        // What the delete dialog is about: the panel's entry, or (below lg) every checked one.
        let pendingKeys = [];
        let selectedKey = null;
        // The rows on screen, so a link or unlink can load them again as they are.
        let currentUrl = window.location.href;
        const emptyPanel = $('#basePanel').html();
        // Checked entries, key → their panel's content, held outside the tbody (which is
        // swapped on every fetch) so a check survives searching and paging.
        const checked = new Map();

        const rowOf = key => $('.js-base-row').filter((i, row) => $(row).data('key') === key);

        // The mobile detail sheet (see above); opening and closing it has no effect from lg up,
        // and the tap rules that only make sense with it check `mobile()` first.
        const mobile = () => window.matchMedia('(max-width: 1023.98px)').matches;
        const sheetOpen = () => ! $('#baseSide').hasClass('max-lg:hidden');
        const openSheet = () => $('#baseSide').removeClass('max-lg:hidden');
        const closeSheet = () => $('#baseSide').addClass('max-lg:hidden');
        $('#baseSide').on('click', '.js-base-sheet-close', closeSheet);

        function select($row) {
            selectedKey = $row.data('key');
            $('.js-base-row').removeClass('bg-blue-600/20');
            $row.addClass('bg-blue-600/20');
            $('#basePanel').html($row.find('.js-row-cards').html());
        }

        function deselect() {
            selectedKey = null;
            $('.js-base-row').removeClass('bg-blue-600/20');
            $('#basePanel').html(emptyPanel);
        }

        // A checked entry's panel looks like the selected one's, with an X that unchecks it
        // in place of the bin. The mobile action bar follows the same checks.
        function renderChecked() {
            $('#checkedPanels').empty();
            checked.forEach(html => {
                const $panel = $('<aside>', { class: $('#basePanel').attr('class') }).html(html);
                $panel.find('.js-base-delete').remove();
                $panel.find('.js-base-uncheck').removeClass('hidden');
                $('#checkedPanels').append($panel);
            });
            $('#checkedBar').toggleClass('hidden', checked.size === 0);
            $('#markSynonyms, #baseBarSynonyms').prop('disabled', checked.size !== 2);
            $('#baseActionBar, #baseActionSpacer').toggleClass('hidden', checked.size === 0);
            $('#baseCheckedCount').text(checked.size + ' selected');
        }

        function uncheckAll() {
            checked.clear();
            $('.js-base-checkbox').prop('checked', false);
            renderChecked();
        }

        // After a fetch: pick the selected row again and re-check the checked ones, taking
        // their panels from the fresh rows.
        function restore() {
            const $picked = rowOf(selectedKey);
            if ($picked.length) { select($picked); }
            checked.forEach((html, key) => {
                const $row = rowOf(key);
                if (! $row.length) { return; }
                $row.find('.js-base-checkbox').prop('checked', true);
                checked.set(key, $row.find('.js-row-cards').html());
            });
            renderChecked();
        }

        // Rows are swapped on every search, so the click is delegated. A click on the
        // checkbox only checks it. Below lg, while anything is checked a tap on a row checks
        // or unchecks it too, and a second tap on the open row closes its sheet.
        $('#baseRows').on('click', '.js-base-row', function (e) {
            if ($(e.target).closest('.js-base-check').length) { return; }
            const $row = $(this);
            if (mobile() && checked.size) {
                const $box = $row.find('.js-base-checkbox');
                $box.prop('checked', ! $box.prop('checked')).trigger('change');
                return;
            }
            if (mobile() && sheetOpen() && selectedKey === $row.data('key')) {
                closeSheet();
                deselect();
                return;
            }
            select($row);
            openSheet();
        });

        $('#baseRows').on('change', '.js-base-checkbox', function (e) {
            e.stopPropagation();
            const key = $(this).data('key');
            if (this.checked) {
                checked.set(key, $(this).closest('.js-base-row').find('.js-row-cards').html());
                // Below lg the action bar takes the sheet's place.
                if (mobile() && sheetOpen()) {
                    closeSheet();
                    deselect();
                }
            } else {
                checked.delete(key);
            }
            renderChecked();
        });

        $('#baseSide').on('click', '.js-base-uncheck', function () {
            const key = $(this).data('key');
            checked.delete(key);
            rowOf(key).find('.js-base-checkbox').prop('checked', false);
            renderChecked();
        });

        $('#baseClearChecks').on('click', uncheckAll);

        $('#markSynonyms, #baseBarSynonyms').on('click', function () {
            if (checked.size !== 2) { return; }
            const [first, second] = [...checked.keys()];
            $.post('/base/synonyms', { _token: csrf, first, second }).done(function () {
                uncheckAll();
                fetchRows(currentUrl);
                if (window.toastr) { toastr.success('Marked as synonyms.'); }
            }).fail(function (xhr) {
                if (window.toastr) { toastr.error(xhr.responseJSON?.message || 'Could not mark them as synonyms.'); }
            });
        });

        $('#baseSide').on('click', '.js-synonym-unlink', function () {
            $.ajax({
                url: '/base/synonyms',
                type: 'DELETE',
                data: { _token: csrf, first: $(this).data('first'), second: $(this).data('second') },
            }).done(function () {
                fetchRows(currentUrl);
            }).fail(function () {
                if (window.toastr) { toastr.error('Could not unlink them.'); }
            });
        });

        // Only words go in a wordbox: checked expressions are left out.
        $('.js-wordbox-add').on('change', function () {
            const wordbox = $(this).val();
            $(this).val('');
            const ids = [...checked.keys()].filter(key => key.startsWith('word:')).map(key => key.split(':')[1]);
            if (! ids.length) {
                if (window.toastr) { toastr.error('Only words go in a wordbox, not expressions.'); }
                return;
            }
            const skipped = checked.size > ids.length;
            $.post('/wordbox/' + wordbox + '/words/add', { _token: csrf, ids }).done(function (r) {
                uncheckAll();
                if (window.toastr) { toastr.success('Added to ' + r.wordbox + '.' + (skipped ? ' Expressions stay out of wordboxes.' : '')); }
            }).fail(function () {
                if (window.toastr) { toastr.error('Could not add them to the wordbox.'); }
            });
        });

        @isset($wordbox)
            // The words stay in the base; the page reloads so its gap-fill checklist follows.
            $('.js-wordbox-remove').on('click', function () {
                const ids = [...checked.keys()].map(key => key.split(':')[1]);
                if (! ids.length) { return; }
                $.post('/wordbox/{{ $wordbox->id }}/words/remove', { _token: csrf, ids }).done(function () {
                    location.reload();
                }).fail(function () {
                    if (window.toastr) { toastr.error('Could not remove them from the wordbox.'); }
                });
            });
        @endisset

        // An expression can't be known, so a dialog that touches one offers plain delete only.
        function askDelete(keys, title) {
            pendingKeys = keys;
            const one = keys.length === 1;
            $('#baseDeleteTitle').text(one ? title : keys.length + ' entries');
            $('#baseDeleteVerb').text(one ? 'leaves' : 'leave');
            $('#baseDeleteIt').text(one ? 'it' : 'one');
            $('#baseDeleteKnown, #baseDeleteKnownHint').toggle(keys.every(key => key.startsWith('word:')));
            openModal('confirm-delete-base');
        }

        $('#basePanel').on('click', '.js-base-delete', function () {
            askDelete([$(this).data('key')], $(this).data('title'));
        });

        // The action bar deletes every checked entry; one checked is named like the bin's.
        $('#baseBarDelete').on('click', function () {
            const keys = [...checked.keys()];
            if (! keys.length) { return; }
            askDelete(keys, $('<div>').html(checked.get(keys[0])).find('.js-base-delete').attr('data-title'));
        });

        $('.js-base-delete-confirm').on('click', function () {
            const keys = pendingKeys;
            const known = $(this).data('known');
            $.when(...keys.map(key => $.ajax({
                url: '/base/' + key.replace(':', '/'),
                type: 'DELETE',
                data: { _token: csrf, known },
            }))).done(function () {
                closeModal('confirm-delete-base');
                keys.forEach(key => { rowOf(key).remove(); checked.delete(key); });
                if (keys.includes(selectedKey)) {
                    deselect();
                    closeSheet();
                }
                renderChecked();
            }).fail(function () {
                if (window.toastr) { toastr.error('Could not delete it.'); }
                // Some of several may have gone: show the rows as they are now.
                if (keys.length > 1) { fetchRows(currentUrl); }
            });
        });

        // Arrived from a card's base-word chip: that row is already picked.
        const $selected = $('.js-base-row.js-selected').trigger('click');
        if ($selected.length) { $selected[0].scrollIntoView({ block: 'center' }); }

        // Pass a url to follow a pagination link (it already carries the filters);
        // otherwise build the query from the header inputs.
        function fetchRows(url) {
            const params = { language_id: '{{ $activeLanguageId }}' };
            $('.js-base-filter').each(function () { params[this.name] = $(this).val(); });
            currentUrl = url || '{{ url()->current() }}?' + $.param(params);

            $.get(currentUrl, function (data) {
                $('#baseRows').html(data.rows);
                $('#basePagination').html(data.pagination);
                restore();
            });
        }

        $('input.js-base-filter').on('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(() => fetchRows(), 250);
        });
        $('select.js-base-filter').on('change', () => fetchRows());

        $('#basePagination').on('click', 'a', function (e) {
            e.preventDefault();
            fetchRows($(this).attr('href'));
        });
    });
</script>
