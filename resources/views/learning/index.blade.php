@props(['cards', 'cardCount', 'mode' => 'sentences'])
@php
    // The writing variant of Sentences swaps the flashcard for the sentence with an
    // inline input; everything around it (pill, hint, counters, exit) stays the same.
    $writeMode = $mode === 'sentences_write';
@endphp
<x-html-layout>
    <div class="relative">
        <button id="exitBtn" type="button" class="absolute left-0 top-0 inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            <span>Save and quit</span>
        </button>

    <div class="flex justify-center items-center">
        <div class="flex-col items-center {{ $writeMode ? 'w-full max-w-2xl' : '' }}">
            <div class="flex justify-center mb-4">
                <span id="wordboxName" class="invisible text-lg mr-1 font-bold bg-orange-800 text-white rounded-full px-3 py-1">&nbsp;</span>
            </div>
            @if($writeMode)
                <div class="mb-6">
                    <div id="sentenceBox" class="bg-white/5 rounded-[15px] border border-white/10 p-6 text-xl leading-relaxed text-center cursor-text">
                        <span id="sentenceBefore"></span><input id="answerInput" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                               class="bg-transparent border-b-2 border-white/20 px-2 py-0 w-44 text-center mx-1 font-medium text-blue-400 focus:outline-none focus:border-blue-500 transition-colors"><span id="sentenceAfter"></span>
                    </div>
                    <p id="reveal" class="hidden mt-4 text-center text-white/60">
                        Correct answer: <span id="revealAnswer" class="font-bold text-green-500"></span>
                    </p>
                </div>
            @else
                <div class="flashcard" id="flashcard">
                    <div class="front" id="front">
                        No cards loaded.
                    </div>
                    <div class="back" id="back">
                        No cards loaded.
                    </div>
                </div>
            @endif
            <div>
                <x-panel class="mb-6 cursor-pointer justify-center items-center max-w-[300px] {{ $writeMode ? 'mx-auto' : '' }}" outline="orange" id="hint">
                    <p id="hintText" class="text-sm text-center">Click to show hint.</p>
                </x-panel>
            </div>
            @if($writeMode)
                <div class="navigationStyle flex justify-center mx-auto">
                    <button class="w-[300px]" id="actionBtn">Check</button>
                </div>
            @else
                <div class="navigationStyle flex justify-center">
                    <button class="w-[300px]" id="flipBtn">Flip</button>
                </div>
                <div class="navigationStyle">
                    <button class="hidden" id="wrongBtn">Wrong</button>
                    <button class="hidden" id="correctBtn">Correct</button>
                </div>
            @endif
        </div>
    </div>
    </div>

    <div class="flex justify-center gap-2 items-center mt-6">
        <x-number-display id="unseenInfo" number="{{$cardCount}}" text="queue"></x-number-display>
        <x-number-display id="wrongInfo" number="0"  text="wrong"></x-number-display>
        <x-number-display id="correctInfo" number="0"  text="correct"></x-number-display>
    </div>
    <div>
        <x-forms.form id="resultsForm" method="POST" action="/saveLearning">
            <input id="resultsInput" type="hidden" name="results">
        </x-forms.form>
    </div>

    <script>
        {!! $cards !!};

        // Sentences (writing): the front is a sentence with an inline input instead of a
        // card to flip, and the grade comes from what the learner typed.
        const writeMode = @json($writeMode);

        // Total cards dealt at the start of the session; used to derive the "correct"
        // counter (a card leaves the deck only when answered correctly).
        const totalCards = {{ $cardCount }};
        const results = [];
        let currentIndex = 0;

        const wordboxName = document.getElementById('wordboxName');
        const exitBtn = document.getElementById('exitBtn');
        const hintElement = document.getElementById('hint');
        const hintText = document.getElementById('hintText');
        const resultsForm = document.getElementById('resultsForm');
        const resultsInput = document.getElementById('resultsInput');

        const queueInfo = document.getElementById('queue');
        const wrongInfo = document.getElementById('wrong');
        const correctInfo = document.getElementById('correct');

        // A card is "reviewed" once it has a results entry (its first answer, which is
        // the grade sent to the backend — repeat-until-correct never overwrites it).
        const isReviewed = card => results.some(r => r.id === card.id);

        // Record a card's first answer only; wrong cards reappear until cleared, but
        // their original (wrong) grade must stand for scheduling.
        function recordResult(result) {
            if (!isReviewed(cards[currentIndex])) {
                results.push({ id: cards[currentIndex].id, result });
            }
        }

        function updateCounters() {
            // queue: still-unanswered cards. wrong: cards left in the deck after a wrong
            // answer. correct: cards cleared from the deck. Together they sum to totalCards.
            queueInfo.innerText   = cards.filter(c => !isReviewed(c)).length.toString();
            wrongInfo.innerText   = cards.filter(isReviewed).length.toString();
            correctInfo.innerText = (totalCards - cards.length).toString();
        }

        function showWordbox() {
            if (cards[currentIndex].wordbox) {
                wordboxName.textContent = cards[currentIndex].wordbox;
                wordboxName.classList.remove('invisible');
            } else {
                // Keep the pill's space reserved so nothing shifts, just hide it.
                wordboxName.innerHTML = '&nbsp;';
                wordboxName.classList.add('invisible');
            }
        }

        // Grade the current card and move on: a correct answer clears it from the deck,
        // a wrong one keeps it in rotation until it is answered correctly.
        function advance(correct) {
            recordResult(correct ? 1 : 0);

            if (correct) {
                cards.splice(currentIndex, 1);

                if (cards.length === 0) {
                    end();
                    return;
                }
                // The splice shifted everything left, so wrap if we were on the last card.
                if (currentIndex >= cards.length) {
                    currentIndex = 0;
                }
            } else {
                currentIndex = (currentIndex + 1) % cards.length;
            }

            showCard();
        }

        hintElement.addEventListener('click', () => {
            hintText.textContent = cards[currentIndex].hint;
        });

        exitBtn.addEventListener('click', end);

        function end() {
            resultsInput.value = JSON.stringify(results);
            resultsForm.submit();
        }

        let showCard;
        // The spacebar shortcut and the main button share one action per mode: flipping
        // the card, or checking the typed answer / moving to the next sentence.
        let primaryAction;

        if (writeMode) {
            const sentenceBox = document.getElementById('sentenceBox');
            const sentenceBefore = document.getElementById('sentenceBefore');
            const sentenceAfter = document.getElementById('sentenceAfter');
            const answerInput = document.getElementById('answerInput');
            const reveal = document.getElementById('reveal');
            const revealAnswer = document.getElementById('revealAnswer');
            const actionBtn = document.getElementById('actionBtn');

            // Same result colours as the gap-fill exercise.
            const RESULT_CLASSES = ['!border-green-500', '!text-green-500', '!border-red-500', '!text-red-500'];

            // checked = the answer is revealed and the button now deals the next card.
            let checked = false;
            let wasCorrect = false;

            // Punctuation the sentence may carry into the blank (a trailing "." or "!")
            // should never cost the answer; apostrophes stay, they are part of the word.
            const normalize = value => value
                .toLowerCase()
                .replace(/[.,!?;:…"“”„«»¡¿()\[\]{}]/g, '')
                .replace(/\s+/g, ' ')
                .trim();

            // A hyphenated term is also accepted written with spaces ("e-mail" / "e mail").
            const flattenHyphens = value => value.replace(/[-‐‑]/g, ' ').replace(/\s+/g, ' ').trim();

            function isAnswerCorrect(typed, answer) {
                const a = normalize(typed);
                const b = normalize(answer);

                return a === b || flattenHyphens(a) === flattenHyphens(b);
            }

            showCard = function () {
                showWordbox();
                sentenceBefore.textContent = cards[currentIndex].before;
                sentenceAfter.textContent = cards[currentIndex].after;
                answerInput.value = '';
                answerInput.readOnly = false;
                answerInput.classList.remove(...RESULT_CLASSES);
                reveal.classList.add('hidden');
                actionBtn.textContent = 'Check';
                checked = false;
                hintText.textContent = 'Click to show hint.';
                updateCounters();
                answerInput.focus();
            };

            // Grade what was typed against the form the sentence actually hides, colour
            // the input like the gap-fill exercise and reveal the answer below it.
            function check() {
                wasCorrect = isAnswerCorrect(answerInput.value, cards[currentIndex].answer);
                answerInput.readOnly = true;
                answerInput.classList.add(...(wasCorrect
                    ? ['!border-green-500', '!text-green-500']
                    : ['!border-red-500', '!text-red-500']));
                revealAnswer.textContent = cards[currentIndex].answer;
                reveal.classList.remove('hidden');
                actionBtn.textContent = 'Next';
                checked = true;
            }

            primaryAction = function () {
                checked ? advance(wasCorrect) : check();
            };

            actionBtn.addEventListener('click', primaryAction);
            sentenceBox.addEventListener('click', () => answerInput.focus());

            // Enter works while typing; the shared spacebar shortcut only takes over once
            // the input is read-only (after checking), so a space still types a space.
            answerInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    primaryAction();
                }
            });
        } else {
            const flashcard = document.getElementById('flashcard');
            const front = document.getElementById('front');
            const back = document.getElementById('back');
            const wrongBtn = document.getElementById('wrongBtn');
            const correctBtn = document.getElementById('correctBtn');
            const flipBtn = document.getElementById('flipBtn');

            showCard = function () {
                flashcard.classList.remove('is-flipped');
                front.textContent = cards[currentIndex].front;
                showWordbox();
                wrongBtn.classList.add('hidden');
                correctBtn.classList.add('hidden');
                flipBtn.classList.remove('hidden');
                hintText.textContent = 'Click to show hint.';
                updateCounters();
            };

            primaryAction = function () {
                back.textContent = cards[currentIndex].back;
                flashcard.classList.toggle('is-flipped');
                flipBtn.classList.add('hidden');
                wrongBtn.classList.remove('hidden');
                correctBtn.classList.remove('hidden');
            };

            flashcard.addEventListener('click', primaryAction);
            flipBtn.addEventListener('click', primaryAction);
            wrongBtn.addEventListener('click', () => advance(false));
            correctBtn.addEventListener('click', () => advance(true));
        }

        // Spacebar flips the current card (checks / advances in writing mode), mirroring a
        // click — but not while the user is typing in a field (e.g. the nav-bar search).
        document.addEventListener('keydown', (e) => {
            const el = e.target;
            const typing = (el.tagName === 'INPUT' && !el.readOnly) || el.tagName === 'TEXTAREA' || el.isContentEditable;
            if (e.code === 'Space' && !e.repeat && !typing) {
                e.preventDefault(); // stop the page from scrolling
                primaryAction();
            }
        });

        showCard();
    </script>
</x-html-layout>
