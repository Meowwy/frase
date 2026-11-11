{{-- "Write sentences" (docs/frammenti.md): pick up to 50 of your Terms, the AI writes a native
     sentence around each, you write it in the target language and grade yourself. Practice
     only — nothing is saved, so the whole round lives on this page. --}}
<x-html-layout :tab-bar="false">
    <div class="max-w-2xl mx-auto">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Write sentences</h1>
                <p class="text-sm text-white/50">Your own Terms in new {{ $language->name }} sentences. Practice only: no levels change.</p>
            </div>
            <a href="{{ route('frammenti', ['language_id' => $language->id]) }}" class="text-white/70 hover:text-white max-lg:min-h-11 max-lg:px-2">Back</a>
        </div>

        {{-- Picker: checkboxes, at most $max, or "pick for me". --}}
        <div id="pick">
            @if($cards->isEmpty())
                <x-panel class="flex-col items-center text-center gap-3 py-6">
                    <p>You have no cards in {{ $language->name }} yet.</p>
                </x-panel>
            @else
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <input id="filter" type="search" placeholder="Filter…" autocomplete="off"
                           class="min-w-0 flex-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-base text-white focus:outline-none">
                    <x-forms.button-small id="randomBtn" type="button" class="max-lg:min-h-11">Pick {{ $pick }} for me</x-forms.button-small>
                </div>
                <ul id="cardList" class="max-h-[55vh] divide-y divide-white/5 overflow-y-auto rounded-xl border border-white/10 bg-white/5">
                    @foreach($cards as $card)
                        <li class="js-card-row">
                            <label class="flex cursor-pointer items-baseline gap-3 px-4 py-2 hover:bg-white/10 max-lg:py-3">
                                <input type="checkbox" class="js-card-check" value="{{ $card->id }}">
                                <span class="js-card-term">{{ $card->term }}</span>
                                <span class="text-sm text-white/40">{{ $card->translation }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4 flex items-center gap-3 max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-40 max-lg:border-t max-lg:border-white/10 max-lg:bg-black max-lg:px-4 max-lg:pt-3 max-lg:pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <span class="text-sm text-white/60"><span id="pickedCount">0</span> / {{ $max }}</span>
                    <x-forms.button id="startBtn" type="button" class="max-lg:min-h-12 max-lg:flex-1" disabled="true">Start</x-forms.button>
                </div>
                <div class="h-24 lg:hidden" aria-hidden="true"></div>
            @endif
        </div>

        <div id="loading" class="hidden py-16 text-center text-white/60 animate-pulse">Writing your sentences…</div>
        <div id="error" class="hidden py-16 text-center">
            <p class="mb-4">The sentences could not be written.</p>
            <x-forms.button id="retryBtn" type="button">Try again</x-forms.button>
        </div>

        <div id="play" class="hidden">
            <p class="mb-2 text-sm text-white/50"><span id="position"></span> · write it in {{ $language->name }}</p>
            <p id="sentence" class="mb-6 text-2xl leading-relaxed max-lg:text-xl"></p>
            <textarea id="attempt" rows="2" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="done"
                      class="w-full rounded-lg bg-white/5 border border-white/10 p-3"></textarea>
            <div id="compare" class="hidden my-4">
                <p class="text-sm text-white/50">You wrote</p>
                <p id="yours" class="mb-3 text-xl"></p>
                <p class="text-sm text-white/50">One way to say it</p>
                <p id="answer" class="mb-3 text-xl"></p>
                <p class="text-sm text-white/50">Your Term: <span id="term" class="text-white"></span></p>
            </div>
            <div class="mt-4 flex flex-wrap gap-2 max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-40 max-lg:border-t max-lg:border-white/10 max-lg:bg-black max-lg:px-4 max-lg:pt-3 max-lg:pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                <x-forms.button id="revealBtn" type="button" class="max-lg:min-h-12 max-lg:flex-1">Show answer</x-forms.button>
                <x-forms.button id="wrongBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">I didn't</x-forms.button>
                <x-forms.button id="rightBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">I had it</x-forms.button>
            </div>
            <div class="h-24 lg:hidden" aria-hidden="true"></div>
        </div>

        <div id="recap" class="hidden">
            <h3 id="recapTitle" class="mt-6 text-center text-2xl font-bold"></h3>
            <ul id="recapList" class="my-4 space-y-2"></ul>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('frammenti.sentences', ['language_id' => $language->id]) }}"><x-forms.button type="button">Write more</x-forms.button></a>
                <a href="{{ route('frammenti', ['language_id' => $language->id]) }}"><x-forms.button-small type="button" class="max-lg:min-h-12">Back to Frammenti</x-forms.button-small></a>
            </div>
        </div>
    </div>

    <script>
        $(function () {
            const max = {{ $max }};
            const pick = {{ $pick }};
            const esc = s => $('<div>').text(s).html();
            const mark = s => esc(s).replace(/\[\[(.+?)\]\]/g, '<mark class="bg-orange-700/60 text-white rounded px-1">$1</mark>');
            let sentences = [], index = 0, grades = [];

            function picked() { return $('.js-card-check:checked').map((i, el) => +el.value).get(); }

            function refreshPick() {
                const n = picked().length;
                $('#pickedCount').text(n);
                $('#startBtn').prop('disabled', n === 0);
                // At the cap, the rest can't be ticked.
                $('.js-card-check:not(:checked)').prop('disabled', n >= max);
            }

            $('#cardList').on('change', '.js-card-check', refreshPick);

            $('#filter').on('input', function () {
                const q = $.trim($(this).val()).toLowerCase();
                $('.js-card-row').each(function () {
                    $(this).toggleClass('hidden', q !== '' && !$(this).text().toLowerCase().includes(q));
                });
            });

            $('#randomBtn').on('click', function () {
                const $all = $('.js-card-check').prop('checked', false).prop('disabled', false).get();
                $all.sort(() => Math.random() - 0.5).slice(0, pick).forEach(el => { el.checked = true; });
                refreshPick();
            });

            function generate() {
                $('#pick, #error').addClass('hidden');
                $('#loading').removeClass('hidden');
                $.post('{{ route('frammenti.sentences') }}', { _token: '{{ csrf_token() }}', language_id: {{ $language->id }}, card_ids: picked() })
                    .done(function (data) {
                        sentences = data.sentences; index = 0; grades = [];
                        $('#loading').addClass('hidden');
                        $('#play').removeClass('hidden');
                        show();
                    })
                    .fail(function () {
                        $('#loading').addClass('hidden');
                        $('#error').removeClass('hidden');
                    });
            }

            $('#startBtn, #retryBtn').on('click', generate);

            function show() {
                const s = sentences[index];
                $('#position').text((index + 1) + ' / ' + sentences.length);
                $('#sentence').text(s.sentence);
                $('#attempt').val('').prop('disabled', false).trigger('focus');
                $('#compare, #wrongBtn, #rightBtn').addClass('hidden');
                $('#revealBtn').removeClass('hidden');
            }

            function reveal() {
                const s = sentences[index];
                $('#yours').text($.trim($('#attempt').val()) || '—');
                $('#answer').html(mark(s.answer));
                $('#term').text(s.term);
                $('#attempt').prop('disabled', true);
                $('#compare, #wrongBtn, #rightBtn').removeClass('hidden');
                $('#revealBtn').addClass('hidden');
            }

            function grade(right) {
                grades.push(right);
                if (++index < sentences.length) { show(); return; }

                $('#play').addClass('hidden');
                const n = grades.filter(Boolean).length;
                $('#recapTitle').text(n + ' / ' + sentences.length + ' right');
                $('#recapList').html(sentences.map((s, i) =>
                    '<li class="rounded-lg bg-white/5 border border-white/10 px-4 py-2">' + (grades[i] ? '✓ ' : '✗ ') + mark(s.answer) + '</li>').join(''));
                $('#recap').removeClass('hidden');
            }

            $('#revealBtn').on('click', reveal);
            $('#rightBtn').on('click', () => grade(true));
            $('#wrongBtn').on('click', () => grade(false));

            // Enter reveals (Shift+Enter breaks a line); grading stays on the buttons.
            $('#attempt').on('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    reveal();
                }
            });
        });
    </script>
</x-html-layout>
