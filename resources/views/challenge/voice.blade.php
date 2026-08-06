<x-html-layout>
    <div class="max-w-4xl mx-auto relative">
        <a href="/" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors mb-4">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            <span>Home</span>
        </a>

        <div class="mb-3 flex items-baseline justify-between">
            <h1 class="text-lg font-bold">Voice conversation</h1>
            <span id="statusLine" class="text-xs text-white/40">Connecting…</span>
        </div>

        {{-- Loading state while we connect the audio session (same spinner as the gap-fill generator). --}}
        <div id="connectLoading" class="flex flex-col items-center text-center py-12">
            <svg class="animate-spin h-12 w-12 text-blue-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="text-white/60 text-sm">Connecting your microphone…</p>
        </div>

        {{-- Live transcript of the spoken conversation. --}}
        <div id="thread" class="space-y-3 mb-4"></div>

        {{-- Push-to-talk control (only shown in PTT mode once connected). --}}
        <div id="pttBar" class="hidden flex justify-center mb-4">
            <button id="pttBtn" type="button"
                    class="inline-flex items-center gap-2 rounded-full bg-[#ff6f61] hover:bg-[#d7574d] px-6 py-3 text-sm font-bold text-white transition-colors disabled:opacity-40">
                🎙️ <span id="pttLabel">Tap to talk</span>
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
                <h3 class="text-lg font-semibold mb-3">Your feedback</h3>
                <div id="recapEmpty" class="hidden text-sm text-white/70">Nothing major to fix — keep practising!</div>
                <div id="recapDidWell" class="mb-4"></div>
                <div id="recapCorrections" class="mb-4"></div>
                <div id="recapVocabulary"></div>
            </div>
        </div>

        <audio id="aiAudio" autoplay></audio>
    </div>

    <script>
        $(document).ready(function () {
            const csrf = '{{ csrf_token() }}';
            const turnTaking = @json($turnTaking); // 'vad' | 'ptt'

            const $thread = $('#thread');
            const $status = $('#statusLine');
            const $end = $('#endBtn');
            const $endLabel = $('#endLabel');

            let pc = null;               // RTCPeerConnection
            let dc = null;               // data channel for events
            let micStream = null;        // MediaStream from getUserMedia
            let micTrack = null;         // the audio track we can enable/disable
            let recapped = false;        // recommendations already shown
            let ending = false;          // end flow in progress
            let talking = false;         // PTT: currently capturing

            // Server-authoritative transcript we send to the recap endpoint.
            const transcript = [];
            // In-progress assistant transcript keyed by response/item id.
            const assistantBuffers = {};

            function escapeHtml(s) {
                return $('<div>').text(s == null ? '' : s).html();
            }

            function setStatus(text) { $status.text(text); }

            function appendBubble(role, text) {
                const isUser = role === 'user';
                const row = $('<div>').addClass('flex ' + (isUser ? 'justify-end' : 'justify-start'));
                const bubble = $('<div>')
                    .addClass('max-w-[80%] rounded-2xl px-4 py-2 text-sm text-white ' + (isUser ? 'bg-[#ff6f61]' : 'bg-white/10'))
                    .html(escapeHtml(text));
                row.append(bubble);
                $thread.append(row);
                row[0].scrollIntoView({ behavior: 'smooth', block: 'end' });
            }

            // ---- Realtime event handling ---------------------------------------------------
            function handleEvent(evt) {
                switch (evt.type) {
                    // Learner's own speech, transcribed.
                    case 'conversation.item.input_audio_transcription.completed': {
                        const text = (evt.transcript || '').trim();
                        if (text) {
                            appendBubble('user', text);
                            transcript.push({ role: 'user', text: text });
                        }
                        break;
                    }
                    // Assistant spoken reply transcript (streamed, then finalised).
                    case 'response.output_audio_transcript.delta': {
                        const key = evt.response_id || evt.item_id || 'cur';
                        assistantBuffers[key] = (assistantBuffers[key] || '') + (evt.delta || '');
                        break;
                    }
                    case 'response.output_audio_transcript.done': {
                        const key = evt.response_id || evt.item_id || 'cur';
                        const text = (evt.transcript || assistantBuffers[key] || '').trim();
                        delete assistantBuffers[key];
                        if (text) {
                            appendBubble('assistant', text);
                            transcript.push({ role: 'assistant', text: text });
                        }
                        break;
                    }
                    case 'input_audio_buffer.speech_started':
                        if (turnTaking === 'vad') { setStatus('Listening…'); }
                        break;
                    case 'response.created':
                        setStatus('Speaking…');
                        break;
                    case 'response.done':
                        setStatus(turnTaking === 'ptt' ? 'Tap to talk' : 'Listening…');
                        break;
                    case 'error':
                        console.error('Realtime error', evt);
                        break;
                }
            }

            // ---- Connection ----------------------------------------------------------------
            async function connect() {
                let tokenData;
                try {
                    tokenData = await $.post('/conversation/voice/token', { _token: csrf });
                } catch (xhr) {
                    return failConnect((xhr.responseJSON && xhr.responseJSON.message) || 'Could not start the voice session.');
                }

                // getUserMedia only exists in a secure context (HTTPS or http://localhost).
                // On plain http:// (e.g. an unsecured Herd .test site) navigator.mediaDevices
                // is undefined and no permission prompt is ever shown — flag that clearly.
                if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    return failConnect('Voice mode needs a secure (https://) connection to use the microphone. Open this site over https (for Herd, run "herd secure frase" and use https://frase.test).');
                }
                try {
                    micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                } catch (e) {
                    const denied = e && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
                    return failConnect(denied
                        ? 'Microphone access was blocked. Allow the mic for this site in your browser (click the camera/mic icon in the address bar) and try again.'
                        : 'No microphone was found. Connect one and try again.');
                }
                micTrack = micStream.getAudioTracks()[0];

                pc = new RTCPeerConnection();
                pc.ontrack = (e) => { document.getElementById('aiAudio').srcObject = e.streams[0]; };
                pc.addTrack(micTrack, micStream);

                dc = pc.createDataChannel('oai-events');
                dc.addEventListener('message', (e) => {
                    try { handleEvent(JSON.parse(e.data)); } catch (err) { /* ignore non-JSON */ }
                });
                dc.addEventListener('open', onConnected);

                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);

                let sdpResponse;
                try {
                    sdpResponse = await fetch('https://api.openai.com/v1/realtime/calls?model=' + encodeURIComponent(tokenData.model), {
                        method: 'POST',
                        body: offer.sdp,
                        headers: {
                            Authorization: 'Bearer ' + tokenData.value,
                            'Content-Type': 'application/sdp',
                        },
                    });
                } catch (e) {
                    return failConnect('Could not reach the voice service. Please try again.');
                }
                if (!sdpResponse.ok) {
                    return failConnect('The voice service refused the connection. Please try again.');
                }
                const answer = { type: 'answer', sdp: await sdpResponse.text() };
                await pc.setRemoteDescription(answer);
            }

            function onConnected() {
                $('#connectLoading').remove();
                $end.prop('disabled', false);
                if (turnTaking === 'ptt') {
                    // Push-to-talk: keep the mic muted until the user taps to talk.
                    micTrack.enabled = false;
                    $('#pttBar').removeClass('hidden').addClass('flex');
                    setStatus('Tap to talk');
                } else {
                    setStatus('Listening…');
                }
            }

            function failConnect(message) {
                $('#connectLoading').html(
                    '<p class="text-red-400/80 text-sm">' + escapeHtml(message) +
                    ' <a href="/conversation" class="underline hover:text-red-300">Try again</a>.</p>'
                );
                if (window.toastr) { toastr.error(message); }
            }

            // ---- Push-to-talk --------------------------------------------------------------
            function togglePtt() {
                if (!dc || dc.readyState !== 'open' || !micTrack) { return; }
                if (!talking) {
                    talking = true;
                    micTrack.enabled = true;
                    $('#pttLabel').text('Tap to send');
                    setStatus('Listening…');
                } else {
                    talking = false;
                    micTrack.enabled = false;
                    $('#pttLabel').text('Tap to talk');
                    setStatus('Thinking…');
                    dc.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
                    dc.send(JSON.stringify({ type: 'response.create' }));
                }
            }

            // ---- End + recap ---------------------------------------------------------------
            function teardown() {
                try { if (micTrack) { micTrack.enabled = false; } } catch (e) {}
                try { if (micStream) { micStream.getTracks().forEach((t) => t.stop()); } } catch (e) {}
                try { if (dc) { dc.close(); } } catch (e) {}
                try { if (pc) { pc.close(); } } catch (e) {}
            }

            function endConversation() {
                if (recapped) { window.location.href = '/'; return; }
                if (ending) { return; }
                ending = true;

                teardown();
                $end.prop('disabled', true);
                $('#pttBar').addClass('hidden');
                setStatus('Ended');

                $('#recapContent').addClass('hidden');
                $('#recapLoading').removeClass('hidden').addClass('flex');
                $('#recapPanel').removeClass('hidden')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });

                $.post('/conversation/voice/recap', { transcript: transcript, _token: csrf }, function (data) {
                    renderRecap(data);
                    recapped = true;
                    $endLabel.text('Back to home');
                    $end.prop('disabled', false);
                }).fail(function (xhr) {
                    $('#recapLoading').addClass('hidden').removeClass('flex');
                    recapped = true; // chat is already torn down; let the button take us home
                    $endLabel.text('Back to home');
                    $end.prop('disabled', false);
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not load your feedback.';
                    if (window.toastr) { toastr.error(msg); }
                });
            }

            function renderSection($el, heading, headingCls, items) {
                $el.empty();
                if (!items || !items.length) { return 0; }
                $el.append('<h4 class="text-sm font-semibold ' + headingCls + ' mb-1">' + heading + '</h4>');
                const ul = $('<ul class="list-disc list-inside space-y-1 text-sm text-white/80">');
                items.forEach(function (b) { ul.append('<li>' + escapeHtml(b) + '</li>'); });
                $el.append(ul);
                return items.length;
            }

            function renderRecap(data) {
                let total = 0;
                total += renderSection($('#recapDidWell'), 'What you did well', 'text-green-400', data.did_well);
                total += renderSection($('#recapCorrections'), 'Grammar to fix', 'text-orange-300', data.corrections);
                total += renderSection($('#recapVocabulary'), 'Better vocabulary', 'text-blue-300', data.vocabulary);

                $('#recapEmpty').toggleClass('hidden', total > 0);

                $('#recapLoading').addClass('hidden').removeClass('flex');
                $('#recapContent').removeClass('hidden');
            }

            $('#pttBtn').on('click', togglePtt);
            $end.on('click', endConversation);
            $(window).on('beforeunload', teardown);

            $end.prop('disabled', true);
            connect();
        });
    </script>
</x-html-layout>
