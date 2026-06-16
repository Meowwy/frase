<x-html-layout>
    <a href="/" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors mb-6">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        <span>back to home</span>
    </a>

    @if($themeName)
        {{-- Legacy theme-based flow: pick a learning mode for the selected theme. --}}
        <div class="text-center mb-2">
            <p class="text-white/70">Learning theme</p>
            <p class="text-2xl font-bold">{{ $themeName }}</p>
        </div>
        <x-section-heading>Start learning by...</x-section-heading>
        <div class="flex text-center justify-center gap-4 mt-4 flex-wrap">
            <x-panel class="w-48">
                <div class="py-8">
                    <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                        <a href="/startLearning/0/sentences">Sentences</a>
                    </h3>
                    <p class="text-sm mt-4">Recall the word from English sentence with blank space.</p>
                </div>
            </x-panel>
            <x-panel class="w-48">
                <div class="py-8">
                    <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                        <a href="/startLearning/0/questions">Questions</a>
                    </h3>
                    <p class="text-sm mt-4">Recall the word when asked about it.</p>
                </div>
            </x-panel>
            <x-panel class="w-48">
                <div class="py-8">
                    <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                        <a href="/startLearning/0/words">Words</a>
                    </h3>
                    <p class="text-sm mt-4">Recall the English translation from Czech word.</p>
                </div>
            </x-panel>
            <x-panel class="w-48">
                <div class="py-8">
                    <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                        <a href="/startLearning/0/definitions">Definitions</a>
                    </h3>
                    <p class="text-sm mt-4">Recall the English translation from an English definition.</p>
                </div>
            </x-panel>
        </div>
    @elseif($targetLanguages->isEmpty())
        <div class="max-w-3xl mx-auto bg-white/5 rounded-xl border border-white/10 p-4">
            <div class="flex flex-col items-center text-center gap-3 py-6">
                <p>Choose the language(s) you want to learn before studying.</p>
                <a href="/profile/edit"><x-forms.button>Set up your languages</x-forms.button></a>
            </div>
        </div>
    @else
        {{-- Card-set builder: language → wordbox selection → due/cram → mode. --}}
        <div class="max-w-3xl mx-auto">
            <x-wordbox-picker :target-languages="$targetLanguages"
                              :wordboxes-by-language="$wordboxesByLanguage"
                              :active-language-id="$activeLanguageId"
                              heading="Wordboxes" />

            <div class="mt-6 flex gap-2">
                <button type="button"
                        class="scope-btn px-4 py-2 rounded-lg font-bold text-white transition bg-[#ff6f61] hover:bg-[#d7574d] opacity-100 ring-2 ring-white/70"
                        data-scope="due">Due cards</button>
                <button type="button"
                        class="scope-btn px-4 py-2 rounded-lg font-bold text-white transition bg-[#ff6f61] hover:bg-[#d7574d] opacity-40"
                        data-scope="cram">Cram all cards</button>
            </div>

            <x-section-heading>
                <span>Start learning </span><span id="startScope" class="bg-orange-800 text-white rounded-full px-3 py-1">due cards</span> from <span id="startTarget" class="text-blue-400">all terms</span> by...
            </x-section-heading>
            <div class="flex text-center justify-center gap-4 mt-4 flex-wrap">
                <x-panel class="w-48">
                    <div class="py-8">
                        <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                            <a href="#" class="mode-link" data-mode="sentences">Sentences</a>
                        </h3>
                        <p class="text-sm mt-4">Recall the word from English sentence with blank space.</p>
                    </div>
                </x-panel>
                <x-panel class="w-48">
                    <div class="py-8">
                        <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                            <a href="#" class="mode-link" data-mode="questions">Questions</a>
                        </h3>
                        <p class="text-sm mt-4">Recall the word when asked about it.</p>
                    </div>
                </x-panel>
                <x-panel class="w-48">
                    <div class="py-8">
                        <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                            <a href="#" class="mode-link" data-mode="words">Words</a>
                        </h3>
                        <p class="text-sm mt-4">Recall the English translation from Czech word.</p>
                    </div>
                </x-panel>
                <x-panel class="w-48">
                    <div class="py-8">
                        <h3 class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100">
                            <a href="#" class="mode-link" data-mode="definitions">Definitions</a>
                        </h3>
                        <p class="text-sm mt-4">Recall the English translation from an English definition.</p>
                    </div>
                </x-panel>
            </div>
        </div>

        <script>
            // Card-set builder: the shared wordbox picker handles language + wordbox;
            // here we add the scope toggle and fold everything into the mode links + heading.
            $(document).ready(function () {
                const init = window.WordboxPicker.current();
                const state = {
                    languageId: init.languageId,
                    wordbox: init.wordbox,
                    label: init.label,     // wordbox name / 'general vocabulary' / null
                    scope: 'due',          // 'due' | 'cram'
                };

                // Fold the current selection into the "Start learning ... by..." heading:
                // scope in the orange pill, the selected target in blue.
                function updateStartHeading() {
                    $('#startScope').text(state.scope === 'due' ? 'due cards' : 'all cards');
                    $('#startTarget').text(state.label || 'all terms');
                }

                function updateModeLinks() {
                    const params = $.param({
                        language_id: state.languageId,
                        wordbox: state.wordbox,
                        scope: state.scope,
                    });
                    $('.mode-link').each(function () {
                        $(this).attr('href', '/startLearningSet/' + $(this).data('mode') + '?' + params);
                    });
                }

                // Language / wordbox selection comes from the shared picker.
                document.addEventListener('wordboxpicker:change', function (e) {
                    state.languageId = e.detail.languageId;
                    state.wordbox = e.detail.wordbox;
                    state.label = e.detail.label;
                    updateStartHeading();
                    updateModeLinks();
                });

                // Due cards / Cram all cards toggle (both flashcard-orange; active one is bright).
                $('.scope-btn').on('click', function () {
                    state.scope = String($(this).data('scope'));
                    $('.scope-btn').each(function () {
                        const on = String($(this).data('scope')) === state.scope;
                        $(this).toggleClass('opacity-100 ring-2 ring-white/70', on).toggleClass('opacity-40', !on);
                    });
                    updateStartHeading();
                    updateModeLinks();
                });

                updateStartHeading();
                updateModeLinks();
            });
        </script>
    @endif
</x-html-layout>
