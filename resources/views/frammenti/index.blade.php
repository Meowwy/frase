{{-- A focused practice screen: below lg the tab bar steps aside and the step buttons sit in a
     bar stuck to the bottom of the screen; the phone keyboard's Enter key takes the same step
     (see the keydown handler below). --}}
<x-html-layout :tab-bar="false">
    <div class="max-w-2xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">Frammenti</h1>
                <p class="text-sm text-white/50">Daily active-vocabulary drill: your words and fixed expressions in fresh sentences, five at a time.</p>
            </div>
            <button id="quitBtn" type="button" class="hidden text-white/70 hover:text-white transition-colors max-lg:min-h-11 max-lg:px-2">Quit</button>
        </div>

        {{-- Target languages only: native-language vocabulary is never dealt. --}}
        @if($targetLanguages->count() > 1)
            <div id="languages" class="mb-6 flex flex-wrap gap-2">
                @foreach($targetLanguages as $lang)
                    <a href="{{ route('frammenti', ['language_id' => $lang->id]) }}"
                       @class([
                           'rounded-lg border border-white/10 px-3 py-1 text-sm transition-colors max-lg:py-2.5',
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
                <p><x-frammenti-today :enough="true" :session="$session" :plan="$plan" /></p>
                <p class="text-sm text-white/50">{{ $activeWords }} active words in {{ $language->name }} · {{ $streak }}-day streak</p>
                @if($session || $plan['batches'])
                    <x-forms.button id="startBtn" type="button" class="max-lg:w-full max-lg:min-h-12">{{ $session ? 'Continue' : 'Start' }}</x-forms.button>
                @endif
            </x-panel>

            <div id="loading" class="hidden py-16 text-center text-white/60 animate-pulse">Writing your fragments…</div>

            <div id="error" class="hidden py-16 text-center">
                <p class="mb-4">Your batch could not be generated.</p>
                <x-forms.button id="retryBtn" type="button">Try again</x-forms.button>
            </div>

            <div id="play" class="hidden">
                <p class="mb-2 text-sm text-white/50"><span id="batchLabel"></span> · <span id="position"></span> / 5 · <span id="cue"></span></p>
                <p id="translation" class="mb-3 text-white/60"></p>
                <p id="fragment" class="mb-6 text-2xl leading-relaxed max-lg:text-xl"></p>

                {{-- A target-language answer: no autocorrect pulling it towards the keyboard's own language. --}}
                <div id="write" class="hidden">
                    <textarea id="attempt" rows="2" autocorrect="off" enterkeyhint="done" class="w-full rounded-lg bg-white/5 border border-white/10 p-3"></textarea>
                </div>

                <div id="compare" class="hidden mb-4">
                    <p class="text-sm text-white/50">You wrote</p>
                    <p id="yours" class="mb-3 text-xl"></p>
                    <div id="correctedBox" class="hidden">
                        <p class="text-sm text-white/50">Corrected</p>
                        <p id="corrected" class="mb-3 text-xl"></p>
                    </div>
                    <p id="note" class="hidden mb-3 text-white/70"></p>
                    <p id="correctLabel" class="text-sm text-white/50"></p>
                    <p id="correct" class="text-xl"></p>
                </div>

                <p id="feedback" class="my-4 min-h-6"></p>

                <div class="flex flex-wrap gap-2 max-lg:sticky max-lg:bottom-0 max-lg:z-20 max-lg:-mx-4 max-lg:border-t max-lg:border-white/10 max-lg:bg-black max-lg:px-4 max-lg:pt-3 max-lg:pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <x-forms.button id="checkBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">Check</x-forms.button>
                    <x-forms.button id="revealBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">Show answer</x-forms.button>
                    <x-forms.button id="evalBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">Evaluate</x-forms.button>
                    <x-forms.button id="passBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">I pass</x-forms.button>
                    <x-forms.button id="hadBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">I had it</x-forms.button>
                    <x-forms.button id="missedBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">I didn't</x-forms.button>
                    <x-forms.button id="nextBtn" type="button" class="hidden max-lg:min-h-12 max-lg:flex-1">Next</x-forms.button>
                </div>
                <p id="gradeNote" class="hidden mt-2 text-sm text-white/50">This is one way to say it — mark whether you used the highlighted word correctly.</p>
            </div>

            <div id="recap" class="hidden">
                <h3 id="recapTitle" class="mt-6 text-center text-2xl font-bold"></h3>
                <ul id="recapList" class="my-4 space-y-2"></ul>
                <div id="summary" class="hidden mb-6 space-y-2 rounded-xl bg-white/5 border border-white/10 p-4"></div>
                <div class="flex gap-2">
                    <x-forms.button id="nextBatchBtn" type="button" class="max-lg:min-h-12 max-lg:flex-1"></x-forms.button>
                    <a href="/" class="max-lg:flex-1"><x-forms.button type="button" class="max-lg:min-h-12 max-lg:w-full">Done</x-forms.button></a>
                </div>
            </div>

            {{-- The article drill: flip cards over the nouns, only for a language whose nouns
                 carry an article. --}}
            @if($articles !== null)
                <x-panel id="articles" class="mt-6 flex-col items-center text-center gap-3 py-6">
                    <h2 class="text-lg font-bold">Articles</h2>
                    <p class="text-sm text-white/50">Your nouns, one at a time: recall each one's article.</p>
                    <p>{{ $articles }} due</p>
                    @if($articles)
                        <a href="{{ route('frammenti.articles', ['language_id' => $language->id]) }}" class="max-lg:w-full">
                            <x-forms.button id="articlesBtn" type="button" class="max-lg:w-full max-lg:min-h-12">Start</x-forms.button>
                        </a>
                    @endif
                </x-panel>
            @endif

            <script>
                $(function () {
                    const csrf = '{{ csrf_token() }}';
                    const languageId = {{ $language->id }};
                    // The tier a level is tested at, as Frammenti::tier(): 0–2 I, 3–5 II, 6–8 III.
                    const tier = level => Math.floor(level / 3) + 1;

                    let batch = [], index = 0, results = [], prefetch = null, retried = false;
                    let planned = 0, done = 0, recapData = null;
                    // Every item answered in this session: its level before the first answer
                    // and after the latest.
                    const seen = new Map();
                    // Answers are posted one by one, in order, so each lands on the state the
                    // previous one left. This chain never rejects.
                    let saving = $.Deferred().resolve().promise();

                    const esc = s => $('<div>').text(s).html();
                    const mark = s => '<mark class="bg-orange-700/60 text-white rounded px-1">' + s + '</mark>';
                    // [[…]] marks the tested item; ___ is a gap.
                    const marked = s => esc(s).replace(/\[\[(.+?)\]\]/g, (m, item) => mark(item));
                    const gapped = (s, html) => marked(s).replace(/_{3,}/g, () => html);
                    const key = f => ({ type: f.type, id: f.id });

                    const post = (url, data) => $.ajax({
                        url, method: 'POST', contentType: 'application/json',
                        headers: { 'X-CSRF-TOKEN': csrf }, data: JSON.stringify(data),
                    });
                    const fetchBatch = onScreen => post('/frammenti/batch', { language_id: languageId, on_screen: onScreen });

                    function show(id) {
                        $('#intro, #languages, #articles, #loading, #error, #play, #recap').addClass('hidden');
                        $('#' + id).removeClass('hidden');
                        $('#quitBtn').toggleClass('hidden', id !== 'play');
                    }

                    function start(request) {
                        show('loading');
                        prefetch = null;
                        request.then(data => {
                            batch = data.fragments; index = 0; results = [];
                            planned = data.planned_batches;
                            // A prefetched batch was dealt before the last batch was done.
                            done = Math.max(done, data.batches_done);
                            show('play');
                            render();
                        }, () => show('error'));
                    }

                    function render() {
                        const f = batch[index];
                        retried = false;
                        $('#batchLabel').text('Batch ' + (done + 1) + ' of ' + planned);
                        $('#position').text(index + 1);
                        $('#feedback').text('').removeClass('text-red-400 text-green-400');
                        $('#write, #compare, #correctedBox, #note, #gradeNote, #checkBtn, #revealBtn, #evalBtn, #passBtn, #hadBtn, #missedBtn, #nextBtn').addClass('hidden');
                        $('#evalBtn, #passBtn').prop('disabled', false);
                        $('#translation').text('');

                        // Prefetch at fragment 4, when answers 1–3 are in, so the next batch
                        // is dealt knowing them. Never after the last planned batch: another
                        // session is fetched on demand.
                        if (index === 3 && done + 1 < planned) {
                            prefetch = saving.then(() => fetchBatch(batch.map(key)));
                            prefetch.catch(() => {});
                        }

                        if (f.fragment_type === 'I') {
                            $('#cue').text('type the missing ' + f.part_of_speech);
                            $('#translation').text(f.translation);
                            $('#fragment').html(gapped(f.fragment, '<input type="text" class="gap mx-1 w-32 rounded bg-white/10 border border-white/20 px-2 text-xl max-lg:py-1" autocomplete="off" autocapitalize="none" autocorrect="off" enterkeyhint="done">'));
                            $('#checkBtn').removeClass('hidden');
                            $('.gap').first().trigger('focus');
                        } else {
                            if (f.fragment_type === 'II') {
                                $('#cue').text('write this in ' + @json($language->name));
                                $('#fragment').text(f.translation);
                                $('#revealBtn').removeClass('hidden');
                            } else {
                                $('#cue').text('your sentence in ' + @json($language->name));
                                $('#translation').text(f.item_translation + ' · ' + f.part_of_speech);
                                $('#fragment').text(f.task);
                                $('#evalBtn, #passBtn').removeClass('hidden');
                            }
                            $('#attempt').val('');
                            $('#write').removeClass('hidden');
                            $('#attempt').trigger('focus');
                        }
                    }

                    // Each answer is posted as it's given; the last one of a batch says so,
                    // which counts the batch done on the server.
                    function grade(correct, message) {
                        const f = batch[index], i = index;
                        results[i] = { f, correct, change: null };
                        saving = saving.then(() => post('/frammenti/answer', { ...key(f), result: correct, last: i === batch.length - 1 })
                            .then(r => record(f, i, r), () => toastr.error('Could not save your answer.')));

                        $('#feedback').text(message).removeClass('text-red-400 text-green-400').addClass(correct ? 'text-green-400' : 'text-red-400');
                        $('#checkBtn, #revealBtn, #evalBtn, #passBtn, #hadBtn, #missedBtn, #gradeNote').addClass('hidden');
                        $('#nextBtn').removeClass('hidden');
                    }

                    function record(f, i, r) {
                        results[i].change = r;
                        const k = f.type + ':' + f.id;
                        seen.set(k, { item: f.item, from: seen.has(k) ? seen.get(k).from : r.level_before, to: r.level_after });
                        if (r.batches_done !== null) done = r.batches_done;
                        if (r.recap) recapData = r.recap;
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

                    // Tier I: each gap is right, a linked synonym, a near miss (one character
                    // or one accent off), or wrong. A synonym is cleared and asked for again,
                    // ungraded, as often as it comes. Any near miss earns one retry; the
                    // second check is final.
                    function check() {
                        const f = batch[index];
                        const verdicts = $('.gap').map(function (i) {
                            const typed = normalize($(this).val());
                            const accepted = f.accepted[i].map(normalize);
                            if (accepted.includes(typed)) return 'right';
                            if (f.synonym_answers[i].map(normalize).includes(typed)) return 'synonym';
                            return accepted.some(a => distance(a, typed) === 1 || bare(a) === bare(typed)) ? 'near' : 'wrong';
                        }).get();

                        if (verdicts.every(v => v === 'right')) {
                            $('.gap').prop('disabled', true);
                            return grade(true, 'Correct!');
                        }
                        if (verdicts.includes('synonym')) {
                            $('.gap').filter(i => verdicts[i] === 'synonym').val('').first().trigger('focus');
                            return $('#feedback').text('That\'s a synonym — give another way.').removeClass('text-red-400');
                        }
                        if (! retried && ! verdicts.includes('wrong')) {
                            retried = true;
                            return $('#feedback').text('Almost — check your spelling.').addClass('text-red-400');
                        }
                        $('.gap').prop('disabled', true);
                        grade(false, 'The answer is ' + f.accepted.map(a => a[0]).join(' … ') + '.');
                    }

                    // The learner's attempt, with the item highlighted wherever it appears.
                    function showAttempt() {
                        const f = batch[index];
                        const target = f.fragment_type === 'III' ? f.answer : f.fragment;
                        const item = (target.match(/\[\[(.+?)\]\]/) || [])[1];
                        let yours = esc($('#attempt').val());
                        if (item) {
                            const pattern = new RegExp(esc(item).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                            yours = yours.replace(pattern, mark);
                        }
                        $('#yours').html(yours || '<span class="text-white/40">(nothing)</span>');
                        $('#correctLabel').text(f.fragment_type === 'III' ? 'One way to say it' : 'One correct version');
                        $('#correct').html(marked(target));
                        $('#write').addClass('hidden');
                        $('#compare').removeClass('hidden');
                    }

                    // Tier II, and tier III when its evaluation fails: compare, then grade
                    // yourself.
                    function reveal() {
                        showAttempt();
                        $('#revealBtn, #evalBtn, #passBtn').addClass('hidden');
                        $('#hadBtn, #missedBtn, #gradeNote').removeClass('hidden');
                    }

                    // Tier III: the AI judges the item only; other mistakes are corrected and
                    // explained but don't fail it. A linked synonym in its place is cleared
                    // and asked for again, ungraded.
                    function evaluate() {
                        const f = batch[index];
                        const sentence = $('#attempt').val().trim();
                        if (! sentence) return $('#attempt').trigger('focus');

                        $('#evalBtn, #passBtn').prop('disabled', true);
                        $('#feedback').text('Checking…').removeClass('text-red-400 text-green-400');
                        post('/frammenti/evaluate', { ...key(f), task: f.task, sentence }).then(e => {
                            if (e.used_synonym && ! e.correct) {
                                $('#evalBtn, #passBtn').prop('disabled', false);
                                $('#attempt').val('').trigger('focus');
                                return $('#feedback').text('You used a synonym (' + f.synonyms.map(s => s.form).join(', ') + '); say it another way.');
                            }
                            showAttempt();
                            if (e.corrected.trim() !== sentence) {
                                $('#corrected').text(e.corrected);
                                $('#correctedBox').removeClass('hidden');
                            }
                            if (e.note) $('#note').text(e.note).removeClass('hidden');
                            grade(e.correct, e.correct ? 'You used it correctly!' : 'Not quite — see the correction.');
                        }, () => {
                            $('#feedback').text('');
                            reveal();
                        });
                    }

                    function recap() {
                        $('#recapList').empty();
                        results.forEach(({ f, correct, change }) => {
                            const arrow = ! change || tier(change.level_after) === tier(change.level_before) ? ''
                                : change.level_after > change.level_before ? ' <span class="text-green-400">↑</span>' : ' <span class="text-red-400">↓</span>';
                            $('<li>').html((correct ? '<span class="text-green-400">✓</span> ' : '<span class="text-red-400">✗</span> ')
                                + esc(f.item) + arrow).appendTo('#recapList');
                        });

                        $('#summary').toggleClass('hidden', ! recapData);
                        if (recapData) {
                            const items = [...seen.values()];
                            const list = rows => rows.map(r => esc(r.item)).join(', ');
                            const up = items.filter(r => tier(r.to) > tier(r.from)), down = items.filter(r => tier(r.to) < tier(r.from));
                            const gained = items.filter(r => tier(r.from) < 2 && tier(r.to) >= 2).length - items.filter(r => tier(r.from) >= 2 && tier(r.to) < 2).length;
                            // Level 0 is always due: a miss not yet made good comes back.
                            const missed = items.filter(r => r.to === 0);
                            $('#summary').html(
                                '<p class="text-lg font-bold">' + (gained >= 0 ? '+' : '') + gained + ' active words · '
                                    + recapData.active_words + ' in total · ' + recapData.streak + '-day streak</p>'
                                + (up.length ? '<p><span class="text-green-400">Promoted:</span> ' + list(up) + '</p>' : '')
                                + (down.length ? '<p><span class="text-red-400">Demoted:</span> ' + list(down) + '</p>' : '')
                                + (missed.length ? '<p class="text-white/70">' + missed.length + ' missed, due again: ' + list(missed) + '</p>' : '')
                                + (recapData.due ? '' : '<p class="text-white/70">Nothing more due, come back tomorrow.</p>')
                            );
                        }

                        $('#recapTitle').text(recapData ? 'Session done' : 'Batch done');
                        $('#nextBatchBtn').text(recapData ? 'Start another session' : 'Next batch')
                            .toggleClass('hidden', !! recapData && ! recapData.due);
                        show('recap');
                    }

                    // Enter takes the step on screen: check, show the answer or evaluate, then
                    // next, then, on the recap, the next batch. Shift+Enter still breaks a line.
                    $(document).on('keydown', e => {
                        if (e.key !== 'Enter' || e.shiftKey || e.repeat) return;
                        const button = ['#nextBtn', '#checkBtn', '#revealBtn', '#evalBtn', '#nextBatchBtn'].find(id => $(id).is(':visible:enabled'));
                        if (! button) return;
                        e.preventDefault();
                        $(button).trigger('click');
                    });

                    $('#checkBtn').on('click', check);
                    $('#revealBtn').on('click', reveal);
                    $('#evalBtn').on('click', evaluate);
                    $('#passBtn').on('click', () => { showAttempt(); grade(false, ''); });
                    $('#hadBtn').on('click', () => grade(true, ''));
                    $('#missedBtn').on('click', () => grade(false, ''));
                    $('#nextBtn').on('click', () => {
                        if (++index < batch.length) return render();
                        show('loading');
                        saving.then(recap);
                    });
                    $('#retryBtn').on('click', () => start(fetchBatch(batch.map(key))));
                    $('#nextBatchBtn').on('click', () => {
                        // After a session's last batch, this starts another.
                        if (recapData) {
                            recapData = null; done = 0; seen.clear();
                        }
                        start(prefetch ? prefetch.then(null, () => fetchBatch(batch.map(key))) : fetchBatch(batch.map(key)));
                    });
                    $('#quitBtn').on('click', () => saving.then(() => window.location = '/'));
                    $('#startBtn').on('click', () => start(fetchBatch([])));
                });
            </script>
        @endif
    </div>
</x-html-layout>
