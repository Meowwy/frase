
<x-html-layout>
    <div class="flex justify-center items-center">
        <div class="flex-col items-center">
            <div class="flashcard" id="flashcard">
                <div class="front" id="front">
                    Chemistry Topic 1
                </div>
                <div class="back" id="back">
                    Information about Chemistry Topic 1
                </div>
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

    <div class="flex justify-center gap-2 items-center mt-6">
        <x-number-display id="unseenInfo" number="5" text="unseen"></x-number-display>
        <x-number-display id="wrongInfo" number="0"  text="wrong"></x-number-display>
        <x-number-display id="correctInfo" number="0"  text="correct"></x-number-display>
    </div>

    <script>
        let cards = [
            { id:"1", front: "First", back: "Atoms consist of a nucleus containing protons and neutrons, surrounded by electrons in shells." },
            { id:"2", front: "Second", back: "A table of the chemical elements arranged in order of atomic number." },
            { id:"3", front: "Third", back: "Atoms combine by sharing or transferring electrons to achieve stability." },
            { id:"4", front: "Forth", back: "A mole is a unit that measures the amount of substance, containing Avogadro's number of particles." },
            { id:"5", front: "Fifth", back: "Acids donate protons (H+), while bases accept protons." }
        ];
        let initialLength = 5;
        let results = [];
        let currentIndex = 0;
        let allCardsShown = false;
        let currentNumber = 0;

        const flashcard = document.getElementById('flashcard');
        const front = document.getElementById('front');
        const back = document.getElementById('back');
        const wrongBtn = document.getElementById('wrongBtn');
        const correctBtn = document.getElementById('correctBtn');
        const flipBtn = document.getElementById('flipBtn');

        const unseenInfo = document.getElementById('unseen');
        const wrongInfo = document.getElementById('wrong');
        const correctInfo = document.getElementById('correct');



        function updateFlashcard(index) {
            front.textContent = cards[index].front;
            back.textContent = cards[index].back;
            wrongBtn.classList.add('hidden');
            correctBtn.classList.add('hidden');
            flipBtn.classList.remove('hidden');
            flashcard.classList.remove('is-flipped');
            currentNumber = parseInt(unseenInfo.innerText);
            if(currentNumber !== 0){
                currentNumber--;
                unseenInfo.innerText = currentNumber.toString();
            }
        }

        flashcard.addEventListener('click', () => {
            flashcard.classList.toggle('is-flipped');
            flipBtn.classList.add('hidden');
            wrongBtn.classList.remove('hidden');
            correctBtn.classList.remove('hidden');
        });

        wrongBtn.addEventListener('click', () => {
            if(allCardsShown === false){
                currentNumber = parseInt(wrongInfo.innerText);
                currentNumber++;
                wrongInfo.innerText = currentNumber.toString();
            }
            if (currentIndex < cards.length - 1) {
                currentIndex++;
                updateFlashcard(currentIndex);
            } else {
                currentIndex = 0;
                allCardsShown = true;
                initialLength = cards.length;
                updateFlashcard(currentIndex);
            }
        });

        correctBtn.addEventListener('click', () => {
            results.push({
                id: cards[currentIndex].id,
                result: 1
            });
            cards.splice(currentIndex, 1);
            if(cards.length === 0){
                end();
            }
            currentNumber = parseInt(correctInfo.innerText);
            currentNumber++;
            correctInfo.innerText = currentNumber.toString();
            if(allCardsShown === true){
                currentNumber = parseInt(wrongInfo.innerText);
                currentNumber--;
                wrongInfo.innerText = currentNumber.toString();
            }
            if (currentIndex <= cards.length - 1) {
                updateFlashcard(currentIndex);
            } else {
                currentIndex = 0;
                allCardsShown = true;
                initialLength = cards.length;
                updateFlashcard(currentIndex);
            }
        });

        flipBtn.addEventListener('click', () => {
            flashcard.classList.toggle('is-flipped');
            flipBtn.classList.add('hidden');
            wrongBtn.classList.remove('hidden');
            correctBtn.classList.remove('hidden');
        });

        function end(){
            //
        }

        updateFlashcard(currentIndex);
    </script>
</x-html-layout>
