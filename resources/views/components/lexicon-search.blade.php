{{-- The shared lexicon search (docs/cards.md "The lexicon"): an input that searches one
     language's lexicon (GET /lexicon/search) as the learner types, and lists the matches one
     row per lemma + part of speech.

     - `action` set: each row gets a button with that label, and pressing it triggers the jQuery
       event `lexicon:pick` on the component's root (`#{id}`) with the result as its argument —
       the page decides what picking means (add to the base, ignore it…).
     - `detailed`: each row also lists every field the lexicon holds for each of its entries
       (the status page).

     Rendered only for a language that has a lexicon; the caller checks Lexicon::has(). --}}
@props(['id', 'languageId', 'action' => null, 'detailed' => false, 'placeholder' => 'Search the lexicon…'])

<div id="{{ $id }}" class="js-lexicon-search" data-language-id="{{ $languageId }}" data-action="{{ $action }}" data-detailed="{{ $detailed ? 1 : 0 }}">
    <input type="search" class="js-lexicon-query w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-base text-white focus:outline-none"
           placeholder="{{ $placeholder }}" autocomplete="off" maxlength="100">
    <ul class="js-lexicon-results mt-2 max-h-80 divide-y divide-white/5 overflow-y-auto text-sm"></ul>
</div>

@once
    <script>
        $(function () {
            const esc = text => $('<div>').text(text == null ? '' : String(text)).html();

            function render($root, results) {
                const action = $root.data('action');
                const detailed = +$root.data('detailed') === 1;
                const $list = $root.find('.js-lexicon-results').empty();

                if (!results.length) {
                    $list.append('<li class="py-2 text-white/40">Nothing in the lexicon.</li>');
                    return;
                }

                results.forEach(function (result) {
                    const $item = $('<li class="py-2">');
                    const $line = $('<div class="flex items-center gap-3">').appendTo($item);
                    $line.append('<span class="min-w-0 flex-1"><span class="font-bold text-white">' + esc(result.display_form) +
                        '</span> <span class="text-xs text-white/40">' + esc(result.part_of_speech) + '</span></span>');

                    if (action) {
                        $('<button type="button" class="js-lexicon-pick shrink-0 rounded bg-blue-800 px-3 py-1 text-sm font-bold hover:bg-blue-600 max-lg:min-h-11">')
                            .text(action).data('result', result).appendTo($line);
                    }

                    if (detailed) {
                        result.entries.forEach(function (entry) {
                            const fields = Object.keys(entry).filter(key => key !== 'id')
                                .map(key => '<span class="text-white/40">' + esc(key) + ':</span> ' + esc(entry[key] ?? '—'));
                            $item.append('<p class="mt-1 text-xs text-white/70">' + fields.join(' · ') + '</p>');
                        });
                    }

                    $list.append($item);
                });
            }

            $('.js-lexicon-search').each(function () {
                const $root = $(this);
                let debounce;
                let latest = 0;

                $root.on('input', '.js-lexicon-query', function () {
                    const q = $.trim($(this).val());
                    clearTimeout(debounce);

                    if (!q) { $root.find('.js-lexicon-results').empty(); return; }

                    debounce = setTimeout(function () {
                        const request = ++latest;
                        $.get('{{ route('lexicon.search') }}', { language_id: $root.data('language-id'), q: q }, function (data) {
                            // A slower, older answer never overwrites a newer one.
                            if (request === latest) { render($root, data.results); }
                        });
                    }, 250);
                });

                $root.on('click', '.js-lexicon-pick', function () {
                    $root.trigger('lexicon:pick', [$(this).data('result'), $(this)]);
                });
            });
        });
    </script>
@endonce
