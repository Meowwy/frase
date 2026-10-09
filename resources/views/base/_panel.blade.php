{{-- /base's side panel, and the live search over the table. The panel is
     empty until a row is clicked, then it shows the Terms of the cards that word or expression
     is part of. Each row carries its own list in a template (the cards are eager-loaded with the
     page), so a click costs no request. --}}
<aside id="basePanel" class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm lg:sticky lg:top-24 lg:w-72 lg:shrink-0">
    <p class="text-white/40">Select a row to see the cards it is part of.</p>
</aside>

<script>
    $(function () {
        let debounce;

        // Rows are swapped on every search, so the click is delegated.
        $('#baseRows').on('click', '.js-base-row', function () {
            $('.js-base-row').removeClass('bg-blue-600/20');
            $(this).addClass('bg-blue-600/20');
            $('#basePanel').html($(this).find('.js-row-cards').html());
        });

        // Arrived from a card's base-word chip: that row is already picked.
        const $selected = $('.js-base-row.js-selected').trigger('click');
        if ($selected.length) { $selected[0].scrollIntoView({ block: 'center' }); }

        // Pass a url to follow a pagination link (it already carries the filters);
        // otherwise build the query from the header inputs.
        function fetchRows(url) {
            const params = { language_id: '{{ $activeLanguageId }}' };
            $('.js-base-filter').each(function () { params[this.name] = $(this).val(); });

            $.get(url || '{{ route('base') }}', url ? {} : params, function (data) {
                $('#baseRows').html(data.rows);
                $('#basePagination').html(data.pagination);
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
