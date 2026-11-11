<x-html-layout>
    <section class="mb-8">
        <x-section-heading>capture a term into Frase</x-section-heading>
        <div class="mt-6 flex justify-center">
            <x-capture-form :autofocus="true" />
        </div>
    </section>

    <section>
        <h2 class="text-lg font-bold mb-4">Staging</h2>
        <p class="mb-4 text-sm text-white/50">
            Nothing here is in your vocabulary yet. Approve a proposal to write its card and
            its base words; discard it and nothing is kept.
        </p>

        <div id="stagingList" class="space-y-4">
            @include('staging._proposals')
        </div>
    </section>

    @php
        // Whether CALL 1 is still out on anything, which is the only reason to poll.
        $anyPending = $proposals->contains(fn ($p) => $p->isAwaitingAnalysis());
    @endphp

    <script>
        // Every action re-renders the list from the server, so the cardinality rules that
        // decide which controls are live stay in PHP and can't drift from the UI.
        $(function () {
            const csrf = '{{ csrf_token() }}';
            const $list = $('#stagingList');
            let pollTimer;

            // Approved and discarded rows stay on the page as one line — "approving…" while
            // CALL 2 runs, then a link to the new card; or "discarded" with Capture again —
            // until the page is reloaded. The server has deleted the proposal by then, so
            // each re-render puts these lines back in capture order. id → the line's markup.
            const settled = new Map();
            const approving = new Set();

            function setBadge(serverCount) {
                const count = serverCount - approving.size;
                $('.js-staged-count').text(Math.max(0, count)).toggleClass('hidden', count <= 0);
            }

            function placeSettled() {
                settled.forEach(function ($line, id) {
                    const $row = $list.find('.js-proposal[data-proposal-id="' + id + '"]');
                    if ($row.length) { $row.empty().append($line); return; }

                    const $wrap = $('<div class="rounded-xl border border-white/10 bg-white/5 p-4 js-proposal">')
                        .attr('data-proposal-id', id).append($line);
                    const $after = $list.find('.js-proposal').filter((i, el) => +$(el).data('proposal-id') > id).first();
                    $after.length ? $wrap.insertBefore($after) : $list.append($wrap);
                });
                if (settled.size) { $list.find('.js-empty').remove(); }
            }

            function line(html, term) {
                const $line = $(html);
                $line.find('.js-line-term').text(term);
                return $line;
            }

            window.stagingRefresh = function () {
                $.get('{{ route('staging.list') }}', function (data) {
                    $list.html(data.rows);
                    placeSettled();
                    setBadge(data.count);

                    clearTimeout(pollTimer);
                    // Poll only while CALL 1 is still out on something.
                    if (data.pending) { pollTimer = setTimeout(window.stagingRefresh, 2000); }
                });
            };

            if (@json($anyPending)) {
                pollTimer = setTimeout(window.stagingRefresh, 2000);
            }

            const proposalId = el => $(el).closest('.js-proposal').data('proposal-id');

            function post(el, path, data) {
                return $.post('/staging/' + proposalId(el) + path, $.extend({ _token: csrf }, data || {}))
                    .fail(function (xhr) {
                        const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'That did not work.';
                        if (window.toastr) { toastr.error(msg); }
                    });
            }

            // Strike a new word as ignored, or tap an ignored one to un-ignore it.
            $list.on('click', '.js-ignore', function () {
                post(this, '/words/' + $(this).data('word-id') + '/ignored', { ignored: $(this).data('ignored') })
                    .done(window.stagingRefresh);
            });

            $list.on('click', '.js-strike-expression', function () {
                post(this, '/expressions/' + $(this).data('expression-id') + '/strike', { struck: $(this).data('struck') })
                    .done(window.stagingRefresh);
            });

            // Switch an existing card this one contains on to be replaced on approval, or off.
            $list.on('click', '.js-replace', function () {
                post(this, '/replace/' + $(this).data('card-id'), { replace: $(this).data('replace') })
                    .done(window.stagingRefresh);
            });

            // Correcting the detected language and answering the language picker are the
            // same call: the language is pinned and CALL 1 runs again.
            $list.on('change', '.js-language, .js-language-option', function () {
                post(this, '/language', { language_id: $(this).val() }).done(window.stagingRefresh);
            });

            // Saving the Context re-runs CALL 1.
            $list.on('change', '.js-context', function () {
                post(this, '/context', { context: $(this).val() }).done(window.stagingRefresh);
            });

            // Answering a clarifying question adds the answer to the Context and re-runs CALL 1.
            $list.on('change', '.js-clarify', function () {
                post(this, '/clarify', { answer: $(this).data('answer') }).done(window.stagingRefresh);
            });

            $list.on('change', '.js-clarify-own', function () {
                if ($.trim($(this).val()).length >= 2) {
                    post(this, '/clarify', { answer: $.trim($(this).val()) }).done(window.stagingRefresh);
                }
            });

            // Which saved meaning of an already-saved word this Term uses, or a new one.
            $list.on('change', '.js-sense', function () {
                const wordId = $(this).data('word-id');
                const choice = $(this).val();

                if (choice !== 'new') {
                    post(this, '/words/' + wordId + '/sense', { sense_id: choice }).done(window.stagingRefresh);
                    return;
                }

                const translation = $.trim(window.prompt('Translation of this new meaning:') || '');
                if (translation) {
                    post(this, '/words/' + wordId + '/sense', { translation: translation }).done(window.stagingRefresh);
                } else {
                    window.stagingRefresh();
                }
            });

            // Regenerate: the panel dims at once, then the re-render shows it reading again
            // and the poll swaps the new proposal in, in the same place.
            $list.on('click', '.js-regenerate', function () {
                $(this).closest('.js-proposal').addClass('opacity-50 animate-pulse pointer-events-none');
                post(this, '/regenerate').always(window.stagingRefresh);
            });

            $list.on('click', '.js-retry', function () {
                $(this).prop('disabled', true);
                post(this, '/retry').always(window.stagingRefresh);
            });

            // Approve collapses the row to one line at once, the same as a pending one, and
            // stays on staging: once the card is written the line links to it.
            $list.on('click', '.js-approve', function () {
                const id = proposalId(this);
                const term = $(this).closest('.js-proposal').find('.js-term').text();
                // Sent before the row collapses: collapsing removes this button, and post()
                // reads the proposal id from it.
                const request = post(this, '/approve');

                approving.add(id);
                settled.set(id, line(
                    '<div class="flex items-center gap-3"><span class="h-4 w-32 animate-pulse rounded bg-white/20"></span>' +
                    '<span class="text-sm text-white/40">approving "<span class="js-line-term"></span>"…</span></div>', term));
                placeSettled();
                $('.js-staged-count').text(function (i, text) { return Math.max(0, (+text || 0) - 1); });

                request.done(function (data) {
                    approving.delete(id);
                    settled.set(id, line(
                        '<div class="flex items-center gap-3"><span class="text-green-400">✓</span>' +
                        '<a class="js-line-term font-bold hover:underline"></a><span class="text-sm text-white/40">approved</span></div>', data.term)
                        .find('a').attr('href', data.url).end());
                    placeSettled();
                    setBadge(data.staged_count);
                }).fail(function () {
                    // Not approved (a duplicate in the way, say): the full row comes back.
                    approving.delete(id);
                    settled.delete(id);
                    window.stagingRefresh();
                });
            });

            // Discard deletes the proposal at once and leaves a one-line "discarded" note in
            // its place, like an approved row. Nothing is kept server-side, so Capture again
            // is a fresh capture of the same input; the new proposal joins the end of the list.
            $list.on('click', '.js-discard', function () {
                const $row = $(this).closest('.js-proposal');
                const id = $row.data('proposal-id');
                const term = $row.find('.js-term').text() || $row.attr('data-raw-input');

                $.ajax({ url: '/staging/' + id, type: 'DELETE', data: { _token: csrf } })
                    .done(function (data) {
                        settled.set(id, line(
                            '<div class="flex items-center gap-3"><span class="text-white/40">✕</span>' +
                            '<span class="js-line-term font-bold text-white/60"></span><span class="text-sm text-white/40">discarded</span>' +
                            '<button type="button" class="js-capture-again ml-auto text-sm text-white/60 underline hover:text-white max-lg:min-h-11 max-lg:px-1">Capture again</button></div>', term)
                            .find('.js-capture-again').attr({ 'data-raw-input': $row.attr('data-raw-input'), 'data-context': $row.attr('data-context') }).end());
                        placeSettled();
                        setBadge(data.staged_count);
                    })
                    .fail(function () {
                        if (window.toastr) { toastr.error('Could not discard that proposal.'); }
                    });
            });

            $list.on('click', '.js-capture-again', function () {
                const id = proposalId(this);
                const $btn = $(this).prop('disabled', true);

                $.post('{{ route('capture') }}', { _token: csrf, capturedWord: $btn.attr('data-raw-input'), context: $btn.attr('data-context') })
                    .done(function () {
                        settled.delete(id);
                        $list.find('.js-proposal[data-proposal-id="' + id + '"]').remove();
                        window.stagingRefresh();
                    })
                    .fail(function (xhr) {
                        const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not capture it again.';
                        if (window.toastr) { toastr.error(msg); }
                        $btn.prop('disabled', false);
                    });
            });
        });
    </script>
</x-html-layout>
