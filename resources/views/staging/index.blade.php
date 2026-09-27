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
        $anyPending = $proposals->contains(fn ($p) => in_array($p->status, ['pending', 'processing'], true));
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

            window.stagingRefresh = function () {
                $.get('{{ route('staging.list') }}', function (data) {
                    $list.html(data.rows);
                    discarding.forEach(id => $list.find('.js-proposal[data-proposal-id="' + id + '"]').addClass('hidden'));
                    $('.js-staged-count').text(data.count - discarding.size).toggleClass('hidden', data.count - discarding.size <= 0);

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

            $list.on('click', '.js-strike', function () {
                const struck = $(this).data('struck') ? 0 : 1;
                post(this, '/words/' + $(this).data('word-id') + '/strike', { struck: struck })
                    .done(window.stagingRefresh);
            });

            // An already-present chip expands to the cards that word is used in.
            $list.on('click', '.js-chip-body', function () {
                $(this).closest('.js-chip-wrap').find('.js-chip-detail').toggleClass('hidden');
            });

            $list.on('change', '.js-language', function () {
                post(this, '/language', { language_id: $(this).val() }).done(window.stagingRefresh);
            });

            $list.on('click', '.js-anchor-save', function () {
                const anchor = $(this).closest('.js-anchor').find('.js-anchor-input').val();
                post(this, '/anchor', { anchor: anchor }).done(window.stagingRefresh);
            });

            $list.on('click', '.js-anchor-clear', function () {
                post(this, '/anchor', { anchor: '' }).done(window.stagingRefresh);
            });

            $list.on('click', '.js-anchor-replace', function () {
                const $btn = $(this).prop('disabled', true);
                post(this, '/anchor', { regenerate: 1 })
                    .done(window.stagingRefresh)
                    .fail(() => $btn.prop('disabled', false));
            });

            $list.on('click', '.js-approve', function () {
                const $btn = $(this).prop('disabled', true).text('Approving…');
                post(this, '/approve').done(function (data) {
                    window.location = data.redirect;
                }).fail(function () {
                    $btn.prop('disabled', false).text('Approve');
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
