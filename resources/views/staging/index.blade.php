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
            const UNDO_MS = 6000;
            let pollTimer;

            // Discard hides the row and only sends the DELETE once the undo window closes,
            // so "undo" is cancelling a timer and nothing is ever kept server-side (no
            // discard history, which is the point). The row is therefore still in every
            // re-render until then, so the ids being discarded are tracked here and hidden
            // again after each one — otherwise the 2s poll would resurrect a row the
            // learner has already dismissed, and Undo would then be unhiding a node that
            // had been replaced.
            const discarding = new Set();

            // Approved rows stay on the page as one line — "approving…" while CALL 2 runs,
            // then a link to the new card — until the page is reloaded. The server has
            // deleted the proposal by then, so each re-render puts these lines back in
            // capture order. id → the line's current markup.
            const settled = new Map();
            const approving = new Set();

            function setBadge(serverCount) {
                const count = serverCount - discarding.size - approving.size;
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
                    discarding.forEach(id => $list.find('.js-proposal[data-proposal-id="' + id + '"]').addClass('hidden'));
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

            // Strike a new word as known, or tap a known one to un-know it.
            $list.on('click', '.js-known', function () {
                post(this, '/words/' + $(this).data('word-id') + '/known', { known: $(this).data('known') })
                    .done(window.stagingRefresh);
            });

            $list.on('click', '.js-strike-expression', function () {
                post(this, '/expressions/' + $(this).data('expression-id') + '/strike', { struck: $(this).data('struck') })
                    .done(window.stagingRefresh);
            });

            // Mark a related or identical card to be merged away on approval, or unmark it.
            $list.on('click', '.js-merge', function () {
                post(this, '/merge/' + $(this).data('card-id'), { merge: $(this).data('merge') })
                    .done(window.stagingRefresh);
            });

            // An already-present chip expands to the cards that word is used in.
            $list.on('click', '.js-chip-body', function () {
                $(this).closest('.js-chip-wrap').find('.js-chip-detail').toggleClass('hidden');
            });

            // Correcting the detected language and answering the language picker are the
            // same call: the language is pinned and CALL 1 runs again.
            $list.on('change', '.js-language, .js-language-option', function () {
                post(this, '/language', { language_id: $(this).val() }).done(window.stagingRefresh);
            });

            // Saving the Context, or picking a sense (written out as the Context), re-runs CALL 1.
            $list.on('change', '.js-context', function () {
                post(this, '/context', { context: $(this).val() }).done(window.stagingRefresh);
            });

            $list.on('change', '.js-sense', function () {
                post(this, '/context', { context: $(this).data('context') }).done(window.stagingRefresh);
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

            // Regenerate the card that's in the way instead of ever making a second one,
            // then the proposal has nothing left to add.
            $list.on('click', '.js-regenerate', function () {
                const $btn = $(this).prop('disabled', true).text('Regenerating…');
                const id = proposalId(this);

                $.post('/cards/' + $(this).data('card-id') + '/regenerate', { _token: csrf })
                    .done(function (data) {
                        $.ajax({ url: '/staging/' + id, type: 'DELETE', data: { _token: csrf } })
                            .always(function () { window.location = data.redirect; });
                    })
                    .fail(function () {
                        if (window.toastr) { toastr.error('Could not regenerate that card.'); }
                        $btn.prop('disabled', false).text('Regenerate that card');
                    });
            });

            // Leaving the page inside an undo window still discards: the timer would never
            // fire, so the pending DELETEs go out as beacons (POST + _method, since a beacon
            // can't send a DELETE).
            window.addEventListener('pagehide', function () {
                discarding.forEach(function (id) {
                    const body = new FormData();
                    body.append('_token', csrf);
                    body.append('_method', 'DELETE');
                    navigator.sendBeacon('/staging/' + id, body);
                });
            });

            // Discard with an undo window (see `discarding` above).
            $list.on('click', '.js-discard', function () {
                const id = proposalId(this);

                discarding.add(id);
                $(this).closest('.js-proposal').addClass('hidden');
                $('.js-staged-count').text(function (i, text) { return Math.max(0, (+text || 0) - 1); });

                const hide = () => discarding.delete(id);

                const timer = setTimeout(function () {
                    hide();
                    $.ajax({ url: '/staging/' + id, type: 'DELETE', data: { _token: csrf } })
                        .done(window.stagingRefresh);
                }, UNDO_MS);

                if (! window.toastr) { return; }

                // escapeHtml is on globally (see html-layout); this one body is static
                // markup with nothing user-supplied in it, so it opts out for the link.
                const $toast = toastr.info('Discarded. <a href="#" class="js-undo font-bold underline">Undo</a>', null, {
                    timeOut: UNDO_MS,
                    extendedTimeOut: 0,
                    escapeHtml: false,
                });

                $toast.on('click', '.js-undo', function (e) {
                    e.preventDefault();
                    clearTimeout(timer);
                    hide();
                    toastr.clear($toast);
                    // Re-render rather than unhiding the row: a poll may have replaced it
                    // by now, and the one we captured would no longer be in the document.
                    window.stagingRefresh();
                });
            });
        });
    </script>
</x-html-layout>
