<x-html-layout>
    <div class="max-w-2xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">Frammenti</h1>
                <p class="text-sm text-white/50">Short fragments built around your words and fixed expressions, five at a time.</p>
            </div>
            <button id="quitBtn" type="button" class="hidden text-white/70 hover:text-white transition-colors">Save and quit</button>
        </div>

        {{-- Target languages only: native-language vocabulary is never dealt. --}}
        @if($targetLanguages->count() > 1)
            <div id="languages" class="mb-6 flex flex-wrap gap-2">
                @foreach($targetLanguages as $lang)
                    <a href="{{ route('frammenti', ['language_id' => $lang->id]) }}"
                       @class([
                           'rounded-lg border border-white/10 px-3 py-1 text-sm transition-colors',
                           'bg-blue-600/30 ring-1 ring-blue-500' => $language->id === $lang->id,
                           'bg-white/5 hover:bg-white/10' => $language->id !== $lang->id,
                       ])>
                        {{ $lang->flag }} {{ $lang->name }}
                    </a>
                @endforeach
            </div>
        @endif

        @if(! $language)
            <x-panel class="flex-col items-center text-center gap-3 py-6">
                <p>Choose the language(s) you want to learn first.</p>
                <a href="/profile/edit"><x-forms.button>Set up your languages</x-forms.button></a>
            </x-panel>
        @elseif(! $enough)
            <x-panel class="flex-col items-center text-center gap-3 py-6">
                <p>Frammenti needs at least {{ \App\Models\Frammenti::MIN_NOUNS_AND_VERBS }} nouns and {{ \App\Models\Frammenti::MIN_NOUNS_AND_VERBS }} verbs saved in {{ $language->name }}. Capture a few more and come back.</p>
                <a href="/staging"><x-forms.button>Capture words</x-forms.button></a>
            </x-panel>
        @else
            {{-- Nothing is generated until the learner has picked a language and started. --}}
            <x-panel id="intro" class="flex-col items-center text-center gap-3 py-6">
                <p>Practise your {{ $language->name }} words, five fragments at a time.</p>
                <x-forms.button id="startBtn" type="button">Start</x-forms.button>
            </x-panel>

            <div id="loading" class="hidden py-16 text-center text-white/60 animate-pulse">Writing your fragments…</div>

            <div id="error" class="hidden py-16 text-center">
                <p class="mb-4">Your batch could not be generated.</p>
                <x-forms.button id="retryBtn" type="button">Try again</x-forms.button>
            </div>

            <div id="play" class="hidden">
                <p class="mb-2 text-sm text-white/50"><span id="position"></span> / 5 · <span id="cue"></span></p>
                <p id="translation" class="mb-3 text-white/60"></p>
                <p id="fragment" class="mb-6 text-2xl leading-relaxed"></p>

                <div id="options" class="grid gap-2"></div>

                <div id="write" class="hidden">
                    <textarea id="attempt" rows="2" class="w-full rounded-lg bg-white/5 border border-white/10 p-3"></textarea>
                </div>

                <div id="compare" class="hidden mb-4">
                    <p class="text-sm text-white/50">You wrote</p>
                    <p id="yours" class="mb-3 text-xl"></p>
                    <p class="text-sm text-white/50">One correct version</p>
                    <p id="correct" class="text-xl"></p>
                </div>

                <p id="feedback" class="my-4 min-h-6"></p>

                <div class="flex flex-wrap gap-2">
                    <x-forms.button id="checkBtn" type="button" class="hidden">Check</x-forms.button>
                    <x-forms.button id="revealBtn" type="button" class="hidden">Show answer</x-forms.button>
                    <x-forms.button id="hadBtn" type="button" class="hidden">I had it</x-forms.button>
                    <x-forms.button id="missedBtn" type="button" class="hidden">I didn't</x-forms.button>
                    <x-forms.button id="nextBtn" type="button" class="hidden">Next</x-forms.button>
                </div>
                <p id="gradeNote" class="hidden mt-2 text-sm text-white/50">This is one way to say it — mark whether you used the highlighted word correctly.</p>
            </div>

            <div id="recap" class="hidden">
                <x-section-heading>Batch done</x-section-heading>
                <ul id="recapList" class="my-4 space-y-2"></ul>
                <div class="flex gap-2">
                    <x-forms.button id="nextBatchBtn" type="button">Next batch</x-forms.button>
                    <a href="/"><x-forms.button type="button">Done</x-forms.button></a>
                </div>
            </div>

            <script>
                $(function () {
                    const csrf = '{{ csrf_token() }}';
                    const languageId = {{ $language->id }};

                    let batch = [], index = 0, answers = [], prefetch = null, retried = false;

                    const esc = s => $('<div>').text(s).html();
                    // [[…]] marks the tested item; ___ is a gap.
                    const marked = s => esc(s).replace(/\[\[(.+?)\]\]/g, '<mark class="bg-orange-700/60 text-white rounded px-1">$1</mark>');
                    const gapped = (s, html) => marked(s).replace(/_{3,}/g, () => html);
                    const key = f => ({ type: f.type, id: f.id });

                    const post = (url, data) => $.ajax({
                        url, method: 'POST', contentType: 'application/json',
                        headers: { 'X-CSRF-TOKEN': csrf }, data: JSON.stringify(data),
                    });
                    const fetchBatch = exclude => post('/frammenti/batch', { language_id: languageId, exclude })
                        .then(data => data.fragments);

                    function show(id) {
                        $('#intro, #languages, #loading, #error, #play, #recap').addClass('hidden');
                        $('#' + id).removeClass('hidden');
                        $('#quitBtn').toggleClass('hidden', id !== 'play');
                    }

                    function start(request) {
                        show('loading');
                        request.then(fragments => {
                            batch = fragments; index = 0; answers = [];
                            // The next batch is chosen now, without this batch's items, so
                            // it is ready the moment the recap is.
                            prefetch = fetchBatch(batch.map(key));
                            prefetch.catch(() => {});
                            show('play');
                            render();
                        }, () => show('error'));
                    }

                    function render() {
                        const f = batch[index];
                        retried = false;
                        $('#position').text(index + 1);
                        $('#options').empty();
                        $('#feedback').text('').removeClass('text-red-400 text-green-400');
                        $('#write, #compare, #gradeNote, #checkBtn, #revealBtn, #hadBtn, #missedBtn, #nextBtn').addClass('hidden');
                        $('#translation').text('');

                        if (f.fragment_type === 'Ia' || f.fragment_type === 'Ib') {
                            $('#cue').text(f.fragment_type === 'Ia' ? 'pick the missing word' : 'pick the meaning of the highlighted word');
                            $('#fragment').html(gapped(f.fragment, '<span class="text-white/40">_____</span>'));
                            f.options.forEach(option => $('<button type="button" class="option rounded-lg border border-white/10 bg-white/5 px-4 py-3 text-left hover:bg-white/10">')
                                .text(option).appendTo('#options').on('click', () => pick(option)));
                        } else if (f.fragment_type === 'II') {
                            $('#cue').text('type the missing ' + f.part_of_speech);
                            $('#translation').text(f.translation);
                            $('#fragment').html(gapped(f.fragment, '<input type="text" class="gap mx-1 w-32 rounded bg-white/10 border border-white/20 px-2 text-xl" autocomplete="off">'));
                            $('#checkBtn').removeClass('hidden');
                            $('.gap').first().trigger('focus');
                        } else {
                            $('#cue').text('write this in ' + @json($language->name));
                            $('#fragment').html(marked(f.translation));
                            $('#attempt').val('');
                            $('#write, #revealBtn').removeClass('hidden');
                            $('#attempt').trigger('focus');
                        }
                    }

                    function grade(correct, message) {
                        answers.push({ ...key(batch[index]), result: correct });
                        $('#feedback').text(message).removeClass('text-red-400').addClass(correct ? 'text-green-400' : 'text-red-400');
                        $('#checkBtn, #revealBtn, #hadBtn, #missedBtn, #gradeNote').addClass('hidden');
                        $('#nextBtn').removeClass('hidden');
                    }

                    function pick(option) {
                        const f = batch[index];
                        $('.option').prop('disabled', true).each(function () {
                            if ($(this).text() === f.answer) $(this).addClass('ring-2 ring-green-500');
                            else if ($(this).text() === option) $(this).addClass('ring-2 ring-red-500');
                        });
                        grade(option === f.answer, option === f.answer ? 'Correct!' : 'The answer is ' + f.answer + '.');
                    }

                    // Case, extra spaces and surrounding punctuation never count.
                    const normalize = s => s.toLowerCase().trim().replace(/\s+/g, ' ').replace(/^[\p{P}\s]+|[\p{P}\s]+$/gu, '');
                    const bare = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '');
                    function distance(a, b) {
                        let row = Array.from({ length: b.length + 1 }, (_, i) => i);
                        for (let i = 1; i <= a.length; i++) {
                            const next = [i];
                            for (let j = 1; j <= b.length; j++) {
                                next[j] = Math.min(row[j] + 1, next[j - 1] + 1, row[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
                            }
                            row = next;
                        }
                        return row[b.length];
                    }

                    // Tier II: each gap is right, a near miss (one character or one accent
                    // off), or wrong. Any near miss earns one retry; the second check is final.
                    function check() {
                        const f = batch[index];
                        const verdicts = $('.gap').map(function (i) {
                            const typed = normalize($(this).val());
                            const accepted = f.accepted[i].map(normalize);
                            if (accepted.includes(typed)) return 'right';
                            return accepted.some(a => distance(a, typed) === 1 || bare(a) === bare(typed)) ? 'near' : 'wrong';
                        }).get();

                        if (verdicts.every(v => v === 'right')) {
                            $('.gap').prop('disabled', true);
                            return grade(true, 'Correct!');
                        }
                        if (! retried && ! verdicts.includes('wrong')) {
                            retried = true;
                            return $('#feedback').text('Almost — check your spelling.').addClass('text-red-400');
                        }
                        $('.gap').prop('disabled', true);
                        grade(false, 'The answer is ' + f.accepted.map(a => a[0]).join(' … ') + '.');
                    }

                    // Tier III: the learner's attempt above the correct fragment, then they
                    // grade themselves.
                    function reveal() {
                        const f = batch[index];
                        const item = (f.fragment.match(/\[\[(.+?)\]\]/) || [])[1];
                        let yours = esc($('#attempt').val());
                        if (item) {
                            const pattern = new RegExp(esc(item).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                            yours = yours.replace(pattern, m => '<mark class="bg-orange-700/60 text-white rounded px-1">' + m + '</mark>');
                        }
                        $('#yours').html(yours || '<span class="text-white/40">(nothing)</span>');
                        $('#correct').html(marked(f.fragment));
                        $('#write, #revealBtn').addClass('hidden');
                        $('#compare, #hadBtn, #missedBtn, #gradeNote').removeClass('hidden');
                    }

                    // Answers are saved once per batch: at its end, or on Save and quit.
                    function save() {
                        return answers.length ? post('/frammenti/results', { results: answers }) : $.Deferred().resolve([]);
                    }

                    function recap(changes) {
                        $('#recapList').empty();
                        batch.forEach((f, i) => {
                            const change = changes.find(c => c.type === f.type && c.id === f.id);
                            const arrow = ! change || change.tier_after === change.tier_before ? ''
                                : change.tier_after > change.tier_before ? ' <span class="text-green-400">↑</span>' : ' <span class="text-red-400">↓</span>';
                            const ok = answers[i].result;
                            $('<li>').html((ok ? '<span class="text-green-400">✓</span> ' : '<span class="text-red-400">✗</span> ')
                                + esc(f.item) + arrow).appendTo('#recapList');
                        });
                        show('recap');
                    }

                    function finish() {
                        show('loading');
                        save().then(recap, () => {
                            toastr.error('Could not save your answers.');
                            recap([]);
                        });
                    }

                    // Enter takes the step on screen: check (or show the answer), then next,
                    // then, on the recap, the next batch. Shift+Enter still breaks a line.
                    $(document).on('keydown', e => {
                        if (e.key !== 'Enter' || e.shiftKey || e.repeat) return;
                        const button = ['#nextBtn', '#checkBtn', '#revealBtn', '#nextBatchBtn'].find(id => $(id).is(':visible'));
                        if (! button) return;
                        e.preventDefault();
                        $(button).trigger('click');
                    });

                    $('#checkBtn').on('click', check);
                    $('#revealBtn').on('click', reveal);
                    $('#hadBtn').on('click', () => grade(true, ''));
                    $('#missedBtn').on('click', () => grade(false, ''));
                    $('#nextBtn').on('click', () => ++index < batch.length ? render() : finish());
                    $('#retryBtn').on('click', () => start(fetchBatch([])));
                    $('#nextBatchBtn').on('click', () => start(prefetch.then(null, () => fetchBatch([]))));
                    $('#quitBtn').on('click', () => save().always(() => window.location = '/'));
                    $('#startBtn').on('click', () => start(fetchBatch([])));
                });
            </script>
        @endif
    </div>
</x-html-layout>
