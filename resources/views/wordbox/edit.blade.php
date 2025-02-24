<x-html-layout>
    <x-forms.form method="post" action="/wordbox/{{$wordbox->id}}">
        @csrf
        @method('PATCH')
        <input hidden value="{{$wordbox->id}}" name="id"/>
        <x-forms.input value="{{$wordbox->name}}" label="Name" name="name"/>
            <x-forms.button>Save</x-forms.button>
    </x-forms.form>

    <section>
        <div class="container mx-auto p-6">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-3xl font-bold">Manage Cards</h2>
                    <p>Add or remove cards from the wordbox.</p>
                </div>

                <button class="bg-blue-500 text-white rounded-full px-4 py-2 hover:bg-blue-600" id="addCardBtn">Add New
                    Theme
                </button>
            </div>

            <!-- Themes List -->
            <ul id="cardList" class="space-y-4">

            </ul>
            <x-forms.form id="cardsForm" method="POST" action="/saveCards">
                <input id="cardsInput" type="hidden" name="cards">
            </x-forms.form>
            <div class="mt-3 space-x-2">
                <x-forms.button id="saveCardsBtn">Save cards</x-forms.button>
                <a href="/profile">
                    <x-forms.button-small>Back to wordbox</x-forms.button-small>
                </a>
            </div>
        </div>

    </section>

    <script>
        let cards = [];

        function createArray() {
            cards = @json($cards);
        }

        function refreshCards() {
            const cardList = document.getElementById('cardList');
            cardList.innerHTML = ''; // Clear existing items
            cards.forEach(card => {
                // Create list item
                const li = document.createElement('li');
                li.className = 'flex flex-col bg-white/10 border border-white/10 p-1 rounded-lg';

                // Create div for text and button
                const div = document.createElement('div');
                div.className = 'flex justify-between items-center';

                // Create a text input element for the card name
                const p_card_phrase = document.createElement('span');
                p_card_phrase.textContent = card.phrase;
                p_card_phrase.className = 'p-2';  // Style the input as needed

                const p_card_translation = document.createElement('span');
                p_card_translation.textContent = card.translation;
                p_card_translation.className = 'p-2';  // Style the input as needed


                // Create delete button
                const button = document.createElement('button');
                button.className = 'bg-red-500 text-white rounded-full px-4 py-2 hover:bg-red-600 ml-4';
                button.textContent = 'Delete';
                button.onclick = function () {
                    deleteCard(card.id);
                };

                // Append input and button to div
                div.appendChild(p_card_phrase);
                div.appendChild(p_card_translation);
                div.appendChild(button);

                // Append div to list item
                li.appendChild(div);

                // Append list item to the list
                cardList.appendChild(li);
            });


        }

        document.getElementById('addCardBtn').addEventListener('click', function () {
            // Add a new card object with
            cards.push({
                id: null,
                name: ''
            });

            // Refresh the list to include the new theme
            refreshCards();

            console.log('card added');
        });

        document.getElementById('saveCardsBtn').addEventListener('click', function () {
            saveCards();
        });

        function deleteCard(id) {
            // Find the index of the card with the given id
            const index = cards.findIndex(card => card.id === id);

            // If the theme is found, remove it from the array
            if (index !== -1) {
                cards.splice(index, 1);
            }

            // Refresh the list to reflect changes
            refreshCards();
        }


        function saveCards() {
            console.log('saveCards started');
            /*let themesToSave = [];
            const themeInputs = document.querySelectorAll('#themeList input[type="text"]');
            themeInputs.forEach(function (input) {
                themesToSave.push( input.value);
            });*/
            document.getElementById('cardsInput').value = JSON.stringify(cards);
            document.getElementById('cardsForm').submit();
        }

        createArray();
        refreshCards();

    </script>
</x-html-layout>
