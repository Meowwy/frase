@props(['opening', 'targetWords'])
<x-html-layout>
    <div class="max-w-2xl mx-auto relative">
        <div class="mb-3 text-sm text-white/60">
            Words left to use:
            <span id="remainingCount" class="font-bold text-white">{{ count($targetWords) }}</span>
        </div>

        {{-- Message thread; pre-seeded with the AI's opening line. --}}
        <div id="thread" class="space-y-3 mb-4 min-h-[240px]">
            <div class="flex justify-start">
                <div class="max-w-[80%] rounded-2xl bg-white/10 px-4 py-2 text-sm text-white">{{ $opening }}</div>
            </div>
        </div>

        {{-- Composer --}}
        <div id="composer" class="flex gap-2">
            <input id="chatInput" type="text" autocomplete="off" placeholder="Type your reply…"
                   class="flex-1 rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30">
            <button id="sendBtn" type="button"
                    class="rounded-lg bg-[#ff6f61] hover:bg-[#d7574d] px-5 py-2 text-sm font-bold text-white transition-colors disabled:opacity-40">
                Send
            </button>
        </div>

        {{-- End the chat and show feedback (also fires automatically when the chat ends). --}}
        <div class="mt-6 flex justify-center">
            <button id="endBtn" type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 hover:bg-white/10 px-5 py-2 text-sm font-semibold text-white transition-colors disabled:opacity-40">
                <span id="endLabel">End chat and display feedback</span>
            </button>
        </div>

        {{-- Recap (revealed once the chat ends). --}}
        <div id="recapPanel" class="hidden mt-8 rounded-xl border border-white/10 bg-white/5 p-5">
            {{-- Loading state while the AI prepares the feedback. --}}
            <div id="recapLoading" class="hidden flex-col items-center text-center py-6">
                <svg class="animate-spin h-10 w-10 text-blue-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-white/60 text-sm">Preparing your feedback…</p>
            </div>
            <div id="recapContent" class="hidden">
                <h3 class="text-lg font-semibold mb-3">Recap</h3>
                <div id="recapWords" class="space-y-1 mb-4"></div>
                <div id="recapDidWell" class="mb-4"></div>
                <div id="recapCorrections"></div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {
            const csrf = '{{ csrf_token() }}';
            const totalWords = {{ count($targetWords) }};

            const $thread = $('#thread');
            const $input = $('#chatInput');
            const $send = $('#sendBtn');
            const $remaining = $('#remainingCount');
            const $end = $('#endBtn');
            const $endLabel = $('#endLabel');

            let ended = false;       // an end condition was reached
            let recapped = false;    // recap already fetched + shown
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

            function appendBubble(role, text) {
                const mine = role === 'user';
                const row = $('<div>').addClass('flex ' + (mine ? 'justify-end' : 'justify-start'));
                const bubble = $('<div>')
                    .addClass('max-w-[80%] rounded-2xl px-4 py-2 text-sm text-white ' + (mine ? 'bg-[#ff6f61]' : 'bg-white/10'))
                    .html(escapeHtml(text));
                row.append(bubble);
                $thread.append(row);
                row[0].scrollIntoView({ behavior: 'smooth', block: 'end' });
            }

            function showTyping() {
                const row = $('<div id="typingRow">').addClass('flex justify-start');
                row.append($('<div>').addClass('rounded-2xl bg-white/10 px-4 py-2 text-white/60 flex items-center').html(spinnerSvg('h-5 w-5')));
                $thread.append(row);
                row[0].scrollIntoView({ behavior: 'smooth', block: 'end' });
            }

            function hideTyping() {
                $('#typingRow').remove();
            }

            function lockComposer() {
                $input.prop('disabled', true);
                $send.prop('disabled', true);
            }

            function sendMessage() {
                const text = $input.val().trim();
                if (busy || ended || text === '') { return; }

                busy = true;
                $send.prop('disabled', true);
                appendBubble('user', text);
                $input.val('');
                showTyping();

                $.post('/chat/message', { message: text, _token: csrf }, function (data) {
                    hideTyping();
                    appendBubble('assistant', data.reply);
                    $remaining.text(data.remaining_count);
                    if (data.ended) {
                        ended = true;
                        lockComposer();
                    }
                }).fail(function (xhr) {
                    hideTyping();
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Something went wrong. Please try again.';
                    if (window.toastr) { toastr.error(msg); }
                }).always(function () {
                    // Clear busy before any end handoff so endConversation() isn't blocked
                    // by the in-flight guard (jQuery runs done/fail before always).
                    busy = false;
                    if (ended) {
                        endConversation();
                    } else {
                        $send.prop('disabled', false);
                        $input.focus();
                    }
                });
            }

            // End the practice: fetch the recap (applies spaced-repetition on the server)
            // and render it below the chat. Once recapped, the button goes home.
            function endConversation() {
                if (recapped) { window.location.href = '/'; return; }
                if (busy) { return; }
                busy = true;
                ended = true;
                lockComposer();
                $end.prop('disabled', true);

                // Reveal the recap panel in its loading state while the AI works.
                $('#recapContent').addClass('hidden');
                $('#recapLoading').removeClass('hidden').addClass('flex');
                $('#recapPanel').removeClass('hidden')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });

                $.post('/chat/recap', { _token: csrf }, function (data) {
                    renderRecap(data);
                    recapped = true;
                    $endLabel.text('Back to home');
                    $end.prop('disabled', false);
                }).fail(function (xhr) {
                    $('#recapLoading').addClass('hidden').removeClass('flex');
                    $('#recapPanel').addClass('hidden');
                    $end.prop('disabled', false);
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not load the recap.';
                    if (window.toastr) { toastr.error(msg); }
                }).always(function () {
                    busy = false;
                });
            }

            function renderRecap(data) {
                const $words = $('#recapWords').empty();
                (data.words || []).forEach(function (w) {
                    const mark = w.got
                        ? '<span class="text-green-400">✓</span>'
                        : '<span class="text-red-400">✗</span>';
                    const note = w.note ? ' <span class="text-white/50">— ' + escapeHtml(w.note) + '</span>' : '';
                    $words.append('<div class="text-sm">' + mark + ' <span class="font-medium">' + escapeHtml(w.term) + '</span>' + note + '</div>');
                });

                const $well = $('#recapDidWell').empty();
                if ((data.did_well || []).length) {
                    $well.append('<h4 class="text-sm font-semibold text-green-400 mb-1">What you did well</h4>');
                    const ul = $('<ul class="list-disc list-inside space-y-1 text-sm text-white/80">');
                    data.did_well.forEach(function (b) { ul.append('<li>' + escapeHtml(b) + '</li>'); });
                    $well.append(ul);
                }

                const $corr = $('#recapCorrections').empty();
                if ((data.corrections || []).length) {
                    $corr.append('<h4 class="text-sm font-semibold text-orange-300 mb-1">Corrections</h4>');
                    const ul = $('<ul class="list-disc list-inside space-y-1 text-sm text-white/80">');
                    data.corrections.forEach(function (b) { ul.append('<li>' + escapeHtml(b) + '</li>'); });
                    $corr.append(ul);
                }

                $('#recapLoading').addClass('hidden').removeClass('flex');
                $('#recapContent').removeClass('hidden');
                $('#recapPanel')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            $send.on('click', sendMessage);
            $input.on('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); sendMessage(); }
            });
            $end.on('click', endConversation);

            $input.focus();
        });
    </script>
</x-html-layout>
