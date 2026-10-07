@props(['cards', 'cardCount', 'mode' => 'translation'])
@php
    // Words mode and Refresher deal individual BASE WORDS rather than cards, so a deck
    // entry is a word and the part of speech travels with it. They differ in one thing:
    // Words is scheduled and can clear a card, Refresher only ever stamps last recall.
    $wordDeck = in_array($mode, ['words', 'refresher'], true);
    $clearsCards = $mode === 'words';
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
        <div class="flex-col items-center">
            <div class="flex justify-center items-center gap-2 mb-4">
                <span id="wordboxName" class="invisible text-lg mr-1 font-bold bg-orange-800 text-white rounded-full px-3 py-1">&nbsp;</span>
                @if($wordDeck)
                    {{-- Outside the card on purpose, so it is visible on both front and
                         back: two base words can share a lemma and differ only by part of
                         speech, so the learner needs it before flipping too. --}}
                    <span id="partOfSpeech" class="text-sm italic text-white/60"></span>
                @endif
            </div>
            <div class="flashcard" id="flashcard">
                <div class="front" id="front">
                    No cards loaded.
                </div>
                <div class="back" id="back">
                    No cards loaded.
                </div>
            </div>
            <div>
                {{-- Hidden for a card without a hint. Only Definitions carries one now; the
                     panel stays so a future hint source can switch it back on. --}}
                <x-panel class="mb-6 cursor-pointer justify-center items-center max-w-[300px]" outline="orange" id="hint">
                    <p id="hintText" class="text-sm text-center">Click to show hint.</p>
                </x-panel>
            </div>
            <div class="navigationStyle flex justify-center">
                <button class="w-[300px]" id="flipBtn">Flip</button>
            </div>
            <div class="navigationStyle">
                <button class="hidden" id="wrongBtn">Wrong</button>
                <button class="hidden" id="correctBtn">Correct</button>
            </div>
        </div>
    </div>
    </div>

    <div class="flex justify-center gap-2 items-center mt-6">
        <x-number-display number-id="queueCount" number="{{$cardCount}}" text="queue"></x-number-display>
        <x-number-display number-id="wrongCount" number="0" text="wrong"></x-number-display>
        <x-number-display number-id="correctCount" number="0" text="correct"></x-number-display>
    </div>
    <div>
        <x-forms.form id="resultsForm" method="POST" action="/saveLearning">
            <input id="resultsInput" type="hidden" name="results">
            {{-- The per-word array a word deck submits alongside (or, for Refresher,
                 instead of) the per-card one. See docs/learning-flow.md. --}}
            <input id="wordsInput" type="hidden" name="words">
        </x-forms.form>
    </div>

    <script>
        {!! $cards !!}

        // A word deck grades base words, so `results` holds base-word ids; the card-level
        // grades are derived from them at the end (Words) or not sent at all (Refresher).
        const wordDeck = @json($wordDeck);
        const clearsCards = @json($clearsCards);
        const dealtWords = wordDeck ? cards.slice() : [];

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
        const wordsInput = document.getElementById('wordsInput');
        const partOfSpeech = document.getElementById('partOfSpeech');

        const queueInfo = document.getElementById('queueCount');
        const wrongInfo = document.getElementById('wrongCount');
        const correctInfo = document.getElementById('correctCount');

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

        // A card clears only once EVERY one of its base words was answered correctly —
        // first time round, the same rule the other modes apply to a card's own answer. A
        // word left unanswered (the learner quit early) leaves its card untouched.
        function derivedCardResults() {
            // A word linked to several cards is dealt once and counts towards all of them.
            const byCard = new Map();
            dealtWords.forEach(w => w.card_ids.forEach(cardId => {
                if (! byCard.has(cardId)) { byCard.set(cardId, []); }
                byCard.get(cardId).push(w.id);
            }));

            const cardResults = [];
            byCard.forEach((wordIds, cardId) => {
                const graded = wordIds.map(id => results.find(r => r.id === id));
                if (graded.some(g => ! g)) { return; }
                cardResults.push({ id: cardId, result: graded.every(g => g.result === 1) ? 1 : 0 });
            });

            return cardResults;
        }

        function end() {
            if (wordDeck) {
                wordsInput.value = JSON.stringify(results);
                resultsInput.value = JSON.stringify(clearsCards ? derivedCardResults() : []);
            } else {
                resultsInput.value = JSON.stringify(results);
            }
            resultsForm.submit();
        }

        const flashcard = document.getElementById('flashcard');
        const front = document.getElementById('front');
        const back = document.getElementById('back');
        const wrongBtn = document.getElementById('wrongBtn');
        const correctBtn = document.getElementById('correctBtn');
        const flipBtn = document.getElementById('flipBtn');

        function showCard() {
            flashcard.classList.remove('is-flipped');
            front.textContent = cards[currentIndex].front;
            showWordbox();
            if (partOfSpeech) { partOfSpeech.textContent = cards[currentIndex].part_of_speech; }
            wrongBtn.classList.add('hidden');
            correctBtn.classList.add('hidden');
            flipBtn.classList.remove('hidden');
            hintText.textContent = 'Click to show hint.';
            hintElement.classList.toggle('hidden', ! cards[currentIndex].hint);
            updateCounters();
        }

        function flip() {
            back.textContent = cards[currentIndex].back;
            flashcard.classList.toggle('is-flipped');
            flipBtn.classList.add('hidden');
            wrongBtn.classList.remove('hidden');
            correctBtn.classList.remove('hidden');
        }

        flashcard.addEventListener('click', flip);
        flipBtn.addEventListener('click', flip);
        wrongBtn.addEventListener('click', () => advance(false));
        correctBtn.addEventListener('click', () => advance(true));

        // Spacebar flips the current card, mirroring a click — but not while the user is
        // typing in a field (e.g. the nav-bar search).
        document.addEventListener('keydown', (e) => {
            const el = e.target;
            const typing = el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable;
            if (e.code === 'Space' && !e.repeat && !typing) {
                e.preventDefault(); // stop the page from scrolling
                flip();
            }
        });

        // An empty deck is reachable — Refresher over an empty vocabulary base, or Words
        // mode over cards with no base words — so don't try to deal a card that isn't there. The card's own "No cards loaded." default stands.
        if (cards.length) {
            showCard();
        } else {
            document.querySelectorAll('.navigationStyle').forEach(el => el.classList.add('hidden'));
        }
    </script>
</x-html-layout>
