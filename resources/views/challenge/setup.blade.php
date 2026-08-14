<x-html-layout>
    <div class="max-w-xl mx-auto">
        <x-section-heading>Conversation challenge</x-section-heading>

        <div class="mt-6 rounded-2xl border border-white/10 bg-white/5 p-6 space-y-5">
            <p class="text-white/70 text-sm leading-relaxed">
                Have a real conversation with an AI partner in your target language. Try to
                <span class="text-white font-semibold">write in full sentences</span> and do your best —
                we'll <span class="text-white font-semibold">correct you as the conversation goes</span>,
                showing the right way to say it next to each of your messages. When you're done, you'll get
                tips on what to work on next.
            </p>

            @if (session('popup_message'))
                <div class="rounded-lg border border-red-400/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">
                    {{ session('popup_message') }}
                </div>
            @endif

            @if ($languages->isEmpty())
                <p class="text-white/60 text-sm">
                    You don't have any languages set up yet.
                    <a href="/profile/edit" class="text-blue-400 hover:text-blue-300 font-semibold">Add one</a> to start.
                </p>
            @else
                <form method="post" action="/conversation/start" class="space-y-5">
                    @csrf

                    <div>
                        <label class="block text-sm font-semibold text-white/80 mb-2">Mode</label>
                        <div class="flex flex-wrap gap-2" id="modePicker">
                            <button type="button" data-mode="text"
                                    class="mode-option inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors border-[#ff6f61] bg-[#ff6f61]/15 text-white">
                                ⌨️ <span>Text</span>
                            </button>
                            <button type="button" data-mode="voice"
                                    class="mode-option inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors border-white/15 bg-white/5 text-white/70 hover:bg-white/10">
                                🎙️ <span>Voice</span>
                            </button>
                            <button type="button" data-mode="game"
                                    class="mode-option inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors border-white/15 bg-white/5 text-white/70 hover:bg-white/10">
                                🎯 <span>Game</span>
                            </button>
                        </div>
                        <input type="hidden" name="mode" id="mode" value="text">
                        <p class="text-xs text-white/40 mt-2" id="voiceHint" style="display:none;">
                            You'll speak with an AI partner out loud. It corrects you verbally as you go, and you'll see the transcript on screen.
                        </p>
                        <p class="text-xs text-white/40 mt-2" id="gameHint" style="display:none;">
                            We pick a random situation and set you a task each turn. You start with 3 lives — every mistake costs one, and a missed task costs one too. Last as many turns as you can, up to 10, before the lives run out.
                        </p>
                    </div>

                    {{-- Voice-only: how turns are taken. --}}
                    <div class="voice-only" style="display:none;">
                        <label class="block text-sm font-semibold text-white/80 mb-2">Turn-taking</label>
                        <div class="flex flex-wrap gap-2" id="turnTakingPicker">
                            <button type="button" data-turn="vad"
                                    class="turn-option inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors border-[#ff6f61] bg-[#ff6f61]/15 text-white">
                                <span>Auto — it replies when you stop talking</span>
                            </button>
                            <button type="button" data-turn="ptt"
                                    class="turn-option inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors border-white/15 bg-white/5 text-white/70 hover:bg-white/10">
                                <span>Push-to-talk — tap to speak, tap to send</span>
                            </button>
                        </div>
                        <input type="hidden" name="turn_taking" id="turn_taking" value="vad">
                    </div>

                    {{-- Voice-only: assistant voice + speaking speed. --}}
                    <div class="voice-only grid grid-cols-2 gap-3" style="display:none;">
                        <div>
                            <label for="voice" class="block text-sm font-semibold text-white/80 mb-2">Voice</label>
                            <select id="voice" name="voice"
                                    class="w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white focus:outline-none focus:border-white/30">
                                <option value="marin" selected>Marin</option>
                                <option value="cedar">Cedar</option>
                                <option value="alloy">Alloy</option>
                                <option value="ash">Ash</option>
                                <option value="ballad">Ballad</option>
                                <option value="coral">Coral</option>
                                <option value="echo">Echo</option>
                                <option value="sage">Sage</option>
                                <option value="shimmer">Shimmer</option>
                                <option value="verse">Verse</option>
                            </select>
                        </div>
                        <div>
                            <label for="speed" class="block text-sm font-semibold text-white/80 mb-2">Speaking speed</label>
                            <select id="speed" name="speed"
                                    class="w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white focus:outline-none focus:border-white/30">
                                <option value="0.75">Slower</option>
                                <option value="0.9">Slow</option>
                                <option value="1.0" selected>Normal</option>
                                <option value="1.15">Fast</option>
                            </select>
                        </div>
                    </div>

                    {{-- Voice-only: barge-in (only affects Auto turn-taking). --}}
                    <div class="voice-only" style="display:none;">
                        <label class="inline-flex items-center gap-2 text-sm text-white/80 cursor-pointer">
                            <input type="checkbox" id="barge_in" name="barge_in" value="1" checked
                                   class="rounded border-white/20 bg-[#111] text-[#ff6f61] focus:ring-0">
                            <span>Let me interrupt while it's speaking</span>
                        </label>
                        <p class="text-xs text-white/40 mt-1">Only applies to Auto turn-taking. Talk over the AI to cut it off.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-white/80 mb-2">Language</label>
                        <div class="flex flex-wrap gap-2" id="languagePicker">
                            @foreach ($languages as $language)
                                <button type="button"
                                        data-language-id="{{ $language->id }}"
                                        class="language-option inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors
                                            {{ $language->id == $activeLanguageId ? 'border-[#ff6f61] bg-[#ff6f61]/15 text-white' : 'border-white/15 bg-white/5 text-white/70 hover:bg-white/10' }}">
                                    <span>{{ $language->flag }}</span>
                                    <span>{{ $language->name }}</span>
                                </button>
                            @endforeach
                        </div>
                        <input type="hidden" name="language_id" id="language_id" value="{{ $activeLanguageId }}">
                    </div>

                    <div class="pref-block">
                        <label for="scene" class="block text-sm font-semibold text-white/80">
                            Scene <span class="font-normal text-white/40">— optional</span>
                        </label>
                        <p class="text-xs text-white/40 mb-2">What should the chat be about? e.g. “ordering coffee in a café”, “a job interview”, “planning a weekend trip”.</p>
                        <textarea id="scene" name="scene" rows="2" maxlength="200"
                                  placeholder="Leave empty and we'll pick a situation for you."
                                  class="w-full rounded-xl border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30 resize-none"></textarea>
                    </div>

                    <div class="pref-block">
                        <label for="feedback_focus" class="block text-sm font-semibold text-white/80">
                            Feedback focus <span class="font-normal text-white/40">— optional</span>
                        </label>
                        <p class="text-xs text-white/40 mb-2">Something specific to practise? We'll emphasise it in the corrections. e.g. “present perfect”, “word order”, “past tense verbs”, “prepositions”.</p>
                        <textarea id="feedback_focus" name="feedback_focus" rows="2" maxlength="200"
                                  placeholder="Leave empty for general corrections."
                                  class="w-full rounded-xl border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30 resize-none"></textarea>
                    </div>

                    <x-forms.button type="submit" class="w-full bg-[#ff6f61] hover:bg-[#d7574d]">
                        <span id="submitLabel">Start conversation</span>
                    </x-forms.button>
                </form>
            @endif
        </div>
    </div>

    <script>
        $(document).ready(function () {
            const activeCls = 'border-[#ff6f61] bg-[#ff6f61]/15 text-white';
            const idleCls = 'border-white/15 bg-white/5 text-white/70 hover:bg-white/10';

            function selectPill($group, $btn) {
                $group.removeClass(activeCls).addClass(idleCls);
                $btn.removeClass(idleCls).addClass(activeCls);
            }

            $('#languagePicker').on('click', '.language-option', function () {
                const $btn = $(this);
                $('#language_id').val($btn.data('language-id'));
                selectPill($('.language-option'), $btn);
            });

            $('#modePicker').on('click', '.mode-option', function () {
                const $btn = $(this);
                const mode = $btn.data('mode');
                $('#mode').val(mode);
                selectPill($('.mode-option'), $btn);

                const isVoice = mode === 'voice';
                const isGame = mode === 'game';
                $('.voice-only').toggle(isVoice);
                // The game sets its own scene and drills nothing specific — no preference inputs.
                $('.pref-block').toggle(!isGame);
                $('#voiceHint').toggle(isVoice);
                $('#gameHint').toggle(isGame);
                $('#submitLabel').text(isVoice ? 'Start voice conversation' : (isGame ? 'Start game' : 'Start conversation'));
            });

            $('#turnTakingPicker').on('click', '.turn-option', function () {
                const $btn = $(this);
                $('#turn_taking').val($btn.data('turn'));
                selectPill($('.turn-option'), $btn);
            });
        });
    </script>
</x-html-layout>
