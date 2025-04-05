<x-html-layout>
    <div class="container mx-auto p-6">
        <h1>Gap-Fill Exercise for {{ $wordbox->name }}</h1>

        <p>Instructions: Fill in the blanks with the words provided below.</p>

        <div class="gap-fill-text">
            {!! nl2br(e($textWithGaps)) !!}  <!-- Use nl2br for newlines -->
        </div>

        <h2>Words to use:</h2>
        <ul>
            @foreach ($usedWords as $word)
                <li>{{ $word }}</li>
            @endforeach
        </ul>

        <a href="{{ route('wordbox.show', ['id' => $wordbox->id]) }}" class="btn btn-primary">Back to Wordbox</a>
    </div>
</x-html-layout>

<x-html-layout>
    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold mb-4 text-center text-blue-600">Gap-Fill Exercise for {{ $wordbox->name }}</h1>

        <div class="bg-white shadow-lg rounded-lg p-6 mb-6">
            <p class="text-gray-700 mb-4">Instructions: Fill in the blanks with the correct phrases. The words below can be dragged into the gaps!</p>

            <div class="gap-fill-text mb-6" id="gapFillText">
                {!! preg_replace_callback('/\[(\d+)\]/', function($matches) {
                    return '<input type="text" data-number="'.$matches[1].'" 
                            class="border-2 border-gray-300 rounded px-2 py-1 w-32 focus:outline-none focus:border-blue-500 transition-colors duration-300">';
                }, $textWithGaps) !!}
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                @foreach ($usedWords as $word)
                    <div class="bg-blue-100 p-2 rounded-lg text-center cursor-grab active:cursor-grabbing draggable-word" 
                         draggable="true" data-word="{{ $word }}">
                        {{ $word }}
                    </div>
                @endforeach
            </div>

            <div class="flex justify-center space-x-4">
                <button id="checkAnswersBtn" class="bg-blue-500 hover:bg-blue-600 text-white font-semibold px-4 py-2 rounded transition-colors">
                    Check Answers
                </button>
                <a href="{{ route('wordbox.show', ['id' => $wordbox->id]) }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded transition-colors">
                    Back to Wordbox
                </a>
            </div>
        </div>
    </div>

    <script>
        const expectedWords = {!! json_encode($ExpectedWordsArray) !!};
        const wordMap = new Map(Object.entries(expectedWords).map(([num, word]) => [num, word.toLowerCase().trim()]));
        const inputs = document.querySelectorAll('#gapFillText input');
        const draggableWords = document.querySelectorAll('.draggable-word');

        // Input validation
        inputs.forEach(input => {
            input.addEventListener('input', () => validateInput(input));
        });

        // Drag and drop functionality
        draggableWords.forEach(word => {
            word.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', e.target.dataset.word);
                setTimeout(() => word.classList.add('opacity-50'), 0);
            });

            word.addEventListener('dragend', () => word.classList.remove('opacity-50'));
        });

        inputs.forEach(input => {
            input.addEventListener('dragover', (e) => e.preventDefault());
            input.addEventListener('drop', (e) => {
                e.preventDefault();
                const word = e.dataTransfer.getData('text/plain');
                input.value = word;
                validateInput(input);
            });
        });

        // Check all answers button
        document.getElementById('checkAnswersBtn').addEventListener('click', () => {
            inputs.forEach(input => validateInput(input, true));
        });

        function validateInput(input, forceCheck = false) {
            const number = input.dataset.number;
            const expected = wordMap.get(number);
            const value = input.value.toLowerCase().trim();

            if (forceCheck || value === expected) {
                input.classList.remove('border-red-500');
                input.classList.add('border-green-500');
                input.insertAdjacentHTML('afterend', '<span class="ml-2 text-green-500">✓</span>');
            } else {
                input.classList.remove('border-green-500');
                input.classList.add('border-red-500');
            }
        }
    </script>
</x-html-layout>