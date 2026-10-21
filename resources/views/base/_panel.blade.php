{{-- /base's side panels, and the live search over the table. The first panel is
     empty until a row is clicked, then it shows the Terms of the cards that word or expression
     is part of, and its synonyms. Each row carries its own content in a template (the cards are
     eager-loaded with the page), so a click costs no request. The panel's bin deletes the entry,
     after a dialog that for a base word also offers marking it known.

     Each checked row gets a panel of its own below, kept across searches and pages, and two
     checked entries can be marked as synonyms. --}}
<div id="baseSide" class="space-y-4 lg:sticky lg:top-24 lg:w-72 lg:shrink-0">
    <aside id="basePanel" class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
        <p class="text-white/40">Select a row to see the cards it is part of.</p>
    </aside>

    <div id="checkedPanels" class="space-y-4"></div>

    <div id="synonymBar" class="hidden space-y-2 text-sm">
        <p id="checkedCount" class="text-white/50"></p>
        <x-forms.button id="markSynonyms" type="button" class="w-full">Mark as synonyms</x-forms.button>
    </div>
</div>

<x-modal name="confirm-delete-base" title="Delete from the base?">
    <div class="mb-6 space-y-2 text-white/60">
        <p>
            <span id="baseDeleteTitle" class="font-bold text-white"></span> leaves the base, its cards and Frammenti.
            Saving it in a new card brings it back, with its old cards and progress.
        </p>
        <p id="baseDeleteKnownHint"><span class="font-bold text-white">Mark as known</span> also stops staging from proposing it again.</p>
    </div>
    <div class="flex flex-wrap justify-end gap-3">
        <button type="button" onclick="closeModal('confirm-delete-base')"
                class="px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all">
            Cancel
        </button>
        <button type="button" id="baseDeleteKnown" data-known="1"
                class="js-base-delete-confirm px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all">
            Mark as known
        </button>
        <button type="button" data-known="0"
                class="js-base-delete-confirm px-5 py-2 rounded-xl font-bold bg-red-600 hover:bg-red-500 text-white transition-all">
            Delete
        </button>
    </div>
</x-modal>

<script>
    $(function () {
        const csrf = '{{ csrf_token() }}';
        let debounce;
        let pendingKey = null;
        let selectedKey = null;
        // The rows on screen, so a link or unlink can load them again as they are.
        let currentUrl = window.location.href;
        const emptyPanel = $('#basePanel').html();
        // Checked entries, key → their panel's content, held outside the tbody (which is
        // swapped on every fetch) so a check survives searching and paging.
        const checked = new Map();

        const rowOf = key => $('.js-base-row').filter((i, row) => $(row).data('key') === key);

        function select($row) {
            selectedKey = $row.data('key');
            $('.js-base-row').removeClass('bg-blue-600/20');
            $row.addClass('bg-blue-600/20');
            $('#basePanel').html($row.find('.js-row-cards').html());
        }

        // A checked entry's panel looks like the selected one's, with an X that unchecks it
        // in place of the bin.
        function renderChecked() {
            $('#checkedPanels').empty();
            checked.forEach(html => {
                const $panel = $('<aside>', { class: $('#basePanel').attr('class') }).html(html);
                $panel.find('.js-base-delete').remove();
                $panel.find('.js-base-uncheck').removeClass('hidden');
                $('#checkedPanels').append($panel);
            });
            $('#synonymBar').toggleClass('hidden', checked.size === 0);
            $('#checkedCount').text(checked.size + ' checked' + (checked.size === 2 ? '' : ' · check two to mark them as synonyms'));
            $('#markSynonyms').prop('disabled', checked.size !== 2);
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
        // checkbox only checks it.
        $('#baseRows').on('click', '.js-base-row', function (e) {
            if ($(e.target).closest('.js-base-check').length) { return; }
            select($(this));
        });

        $('#baseRows').on('change', '.js-base-checkbox', function (e) {
            e.stopPropagation();
            const key = $(this).data('key');
            if (this.checked) {
                checked.set(key, $(this).closest('.js-base-row').find('.js-row-cards').html());
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

        $('#markSynonyms').on('click', function () {
            const [first, second] = [...checked.keys()];
            $.post('/base/synonyms', { _token: csrf, first, second }).done(function () {
                checked.clear();
                $('.js-base-checkbox').prop('checked', false);
                renderChecked();
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

        // An expression can't be known, so its dialog offers plain delete only.
        $('#basePanel').on('click', '.js-base-delete', function () {
            pendingKey = $(this).data('key');
            const isWord = pendingKey.startsWith('word:');
            $('#baseDeleteTitle').text($(this).data('title'));
            $('#baseDeleteKnown, #baseDeleteKnownHint').toggle(isWord);
            openModal('confirm-delete-base');
        });

        $('.js-base-delete-confirm').on('click', function () {
            $.ajax({
                url: '/base/' + pendingKey.replace(':', '/'),
                type: 'DELETE',
                data: { _token: csrf, known: $(this).data('known') },
            }).done(function () {
                closeModal('confirm-delete-base');
                rowOf(pendingKey).remove();
                $('#basePanel').html(emptyPanel);
                selectedKey = null;
                checked.delete(pendingKey);
                renderChecked();
            }).fail(function () {
                if (window.toastr) { toastr.error('Could not delete it.'); }
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
            currentUrl = url || '{{ route('base') }}?' + $.param(params);

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
