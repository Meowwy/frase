<x-html-layout>
    <div class="max-w-4xl mx-auto relative">
        <a href="/" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors mb-4">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            <span>Home</span>
        </a>

        <div class="mb-3 flex items-baseline justify-between">
            <h1 class="text-lg font-bold">Conversation challenge</h1>
            <span class="text-xs text-white/40">Corrections appear on the right, next to each message.</span>
        </div>

        {{-- Scene label (shown above the chat once the opening loads). --}}
        <div id="sceneBanner" class="hidden mb-4 rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm">
            <span class="text-white/40">Scene:</span>
            <span id="sceneText" class="text-white/85 font-medium"></span>
        </div>

        {{-- Loading state while the AI sets up the opening (same spinner as the gap-fill generator). --}}
        <div id="openingLoading" class="flex flex-col items-center text-center py-12">
            <svg class="animate-spin h-12 w-12 text-blue-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="text-white/60 text-sm">Setting the scene…</p>
        </div>

        {{-- Message thread. Each turn is a two-column row: message on the left, and for the
             learner's own messages a live correction on the right at the same level. --}}
        <div id="thread" class="space-y-3 mb-4"></div>

        {{-- Composer --}}
        <div id="composer" class="flex gap-2 items-end">
            <textarea id="chatInput" rows="1" autocomplete="off" placeholder="Write a full sentence…"
                      class="flex-1 resize-none max-h-40 overflow-y-auto rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30"></textarea>
            <button id="sendBtn" type="button"
                    class="rounded-lg bg-[#ff6f61] hover:bg-[#d7574d] px-5 py-2 text-sm font-bold text-white transition-colors disabled:opacity-40">
                Send
            </button>
        </div>

        {{-- End the chat and get recommendations. --}}
        <div class="mt-6 flex justify-center">
            <button id="endBtn" type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 hover:bg-white/10 px-5 py-2 text-sm font-semibold text-white transition-colors disabled:opacity-40">
                <span id="endLabel">End chat and get feedback</span>
            </button>
        </div>

        {{-- Recommendations (revealed once the chat ends). --}}
        <div id="recapPanel" class="hidden mt-8 rounded-xl border border-white/10 bg-white/5 p-5">
            <div id="recapLoading" class="hidden flex-col items-center text-center py-6">
                <svg class="animate-spin h-10 w-10 text-blue-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-white/60 text-sm">Preparing your feedback…</p>
            </div>
            <div id="recapContent" class="hidden">
                <h3 class="text-lg font-semibold mb-3">What to work on next</h3>
                <div id="recapList"></div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {
            const csrf = '{{ csrf_token() }}';

            const $thread = $('#thread');
            const $input = $('#chatInput');
            const $send = $('#sendBtn');
            const $end = $('#endBtn');
            const $endLabel = $('#endLabel');

            let recapped = false;    // recommendations already fetched + shown
            let busy = false;        // a request is in flight

            function escapeHtml(s) {
                return $('<div>').text(s == null ? '' : s).html();
            }

            // Shared spinning loader (same look as the gap-fill generator).
            function spinnerSvg(sizeClasses) {
                return '<svg class="animate-spin ' + sizeClasses + '" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">' +
                    '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>' +
                    '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>' +
                    '</svg>';
            }

            // A two-column grid row: [message column | correction column].
            function newRow() {
                const row = $('<div>').addClass('grid grid-cols-1 md:grid-cols-2 gap-4 items-start');
                $thread.append(row);
                return row;
            }

            // Assistant message: bubble on the left, right column empty.
            function appendAssistant(text) {
                const row = newRow();
                row.append($('<div>').addClass('flex justify-start').append(
                    $('<div>').addClass('max-w-[90%] rounded-2xl bg-white/10 px-4 py-2 text-sm text-white').html(escapeHtml(text))
                ));
                row.append($('<div>')); // keep the correction column reserved
                row[0].scrollIntoView({ behavior: 'smooth', block: 'end' });
            }

            // Learner message: bubble on the left, correction cell on the right (returned so
            // it can be filled once the reply arrives).
            function appendUser(text) {
                const row = newRow();
                row.append($('<div>').addClass('flex justify-end').append(
                    $('<div>').addClass('max-w-[90%] rounded-2xl bg-[#ff6f61] px-4 py-2 text-sm text-white').html(escapeHtml(text))
                ));
                const cell = $('<div>').addClass('flex items-center text-white/50 pt-2')
                    .html(spinnerSvg('h-4 w-4'));
                row.append(cell);
                row[0].scrollIntoView({ behavior: 'smooth', block: 'end' });
                return cell;
            }

            function renderCorrection(cell, correction) {
                if (!correction || !correction.has_error || !correction.feedback) {
                    cell.removeClass('flex items-center')
                        .html('<div class="flex items-center gap-1 text-xs text-green-400/80 pt-2"><span>✓</span><span>Looks good</span></div>');
                    return;
                }
                cell.removeClass('flex items-center').html(
                    '<div class="rounded-xl border border-orange-300/25 bg-orange-300/5 px-3 py-2">' +
                        '<p class="text-sm text-white/85">' + escapeHtml(correction.feedback) + '</p>' +
                    '</div>'
                );
            }

            function lockComposer() {
                $input.prop('disabled', true);
                $send.prop('disabled', true);
            }

            // Grow the textarea with its content (capped by max-h via overflow).
            function autoGrow() {
                const el = $input[0];
                el.style.height = 'auto';
                el.style.height = el.scrollHeight + 'px';
            }

            function sendMessage() {
                const text = $input.val().trim();
                if (busy || recapped || text === '') { return; }

                busy = true;
                $send.prop('disabled', true);
                const cell = appendUser(text);
                $input.val('');
                autoGrow();

                $.post('/conversation/message', { message: text, _token: csrf }, function (data) {
                    renderCorrection(cell, data.correction);
                    appendAssistant(data.reply);
                }).fail(function (xhr) {
                    cell.removeClass('flex items-center').html(
                        '<div class="text-xs text-red-400/80 pt-2">Could not check this message.</div>'
                    );
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Something went wrong. Please try again.';
                    if (window.toastr) { toastr.error(msg); }
                }).always(function () {
                    busy = false;
                    $send.prop('disabled', false);
                    $input.focus();
                });
            }

            // End the chat: fetch recommendations and render them below.
            function endConversation() {
                if (recapped) { window.location.href = '/'; return; }
                if (busy) { return; }
                busy = true;
                lockComposer();
                $end.prop('disabled', true);

                $('#recapContent').addClass('hidden');
                $('#recapLoading').removeClass('hidden').addClass('flex');
                $('#recapPanel').removeClass('hidden')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });

                $.post('/conversation/recap', { _token: csrf }, function (data) {
                    renderRecap(data);
                    recapped = true;
                    $endLabel.text('Back to home');
                    $end.prop('disabled', false);
                }).fail(function (xhr) {
                    $('#recapLoading').addClass('hidden').removeClass('flex');
                    $('#recapPanel').addClass('hidden');
                    $end.prop('disabled', false);
                    lockComposer();
                    $input.prop('disabled', false);
                    $send.prop('disabled', false);
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not load your feedback.';
                    if (window.toastr) { toastr.error(msg); }
                }).always(function () {
                    busy = false;
                });
            }

            function renderRecap(data) {
                const $list = $('#recapList').empty();
                const recs = data.recommendations || [];
                if (!recs.length) {
                    $list.append('<p class="text-sm text-white/70">Nothing major to fix — keep practising!</p>');
                } else {
                    const ul = $('<ul class="list-disc list-inside space-y-1 text-sm text-white/80">');
                    recs.forEach(function (r) { ul.append('<li>' + escapeHtml(r) + '</li>'); });
                    $list.append(ul);
                }
                $('#recapLoading').addClass('hidden').removeClass('flex');
                $('#recapContent').removeClass('hidden');
                $('#recapPanel')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            // Fetch the opening line + scene after the page is already visible (spinner shown).
            function loadOpening() {
                lockComposer();
                $end.prop('disabled', true);

                $.post('/conversation/opening', { _token: csrf }, function (data) {
                    $('#openingLoading').remove();
                    if (data.scene) {
                        $('#sceneText').text(data.scene);
                        $('#sceneBanner').removeClass('hidden');
                    }
                    appendAssistant(data.reply);
                    $input.prop('disabled', false);
                    $send.prop('disabled', false);
                    $end.prop('disabled', false);
                    $input.focus();
                }).fail(function (xhr) {
                    $('#openingLoading').html(
                        '<p class="text-red-400/80 text-sm">Could not start the conversation. ' +
                        '<a href="/conversation" class="underline hover:text-red-300">Try again</a>.</p>'
                    );
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not start the conversation.';
                    if (window.toastr) { toastr.error(msg); }
                });
            }

            $send.on('click', sendMessage);
            $input.on('input', autoGrow);
            $input.on('keydown', function (e) {
                // Enter sends; Shift+Enter inserts a newline.
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
            });
            $end.on('click', endConversation);

            loadOpening();
        });
    </script>
</x-html-layout>
