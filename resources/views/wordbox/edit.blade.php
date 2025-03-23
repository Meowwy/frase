@props(['foundCards' => [], 'searchTermWb' => ''])
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

            <!-- Card List -->
            <ul id="cardList" class="space-y-4">

            </ul>
            <x-forms.form id="cardsForm" method="POST" action="/saveCards/{{$wordbox->id}}">
                <input id="cardsInput" type="hidden" name="cards">
            </x-forms.form>
            <div class="mt-3 space-x-2">
                <x-forms.button id="saveCardsBtn">Save cards</x-forms.button>
                <a href="/wordbox/{{$wordbox->id}}">
                    <x-forms.button-small>Back to wordbox</x-forms.button-small>
                </a>
            </div>
        </div>

    </section>
    <section>
        <h2 class="text-3xl font-bold">Add Existing Cards</h2>
        <div class="">
                <form action="/searchWordbox/{{$wordbox->id}}" method="get" id="searchForm">
                    <x-forms.input-search name="searchTerm" placeholder="Search for a card"></x-forms.input-search>
                    <button type="submit">Search</button>
                </form>
        </div>
        @if(isset($foundCards))
        <div class="mb-4">
            <p class="text-center text-4xl">"{{$searchTermWb}}"</p>
        </div>

        @if(count($foundCards) == 0)
            <p class="text-center">No results</p>
        @endif
        <div class="mt-4 space-y-2">
            @foreach($foundCards as $card)
                <x-card-wordbox :card="$card" data-id="{{$card->id}}"></x-card-wordbox>
            @endforeach
        </div>
        @endif
    </section>

    <script>
        let cards = [];
        let foundCards = [];

        function createArray() {
            cards = @json($cards);
            foundCards = @json($foundCards);
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

        document.addEventListener("DOMContentLoaded", function () {
            /* Add a event for an ADD button */
            document.querySelectorAll("[id^='card-']").forEach(button => {
                button.addEventListener("click", function () {
                    let cardId = this.id.replace("card-", ""); // Extract the numeric card ID
                    let phrase = this.dataset.phrase; // Get phrase from data attribute
                    let translation = this.dataset.translation; // Get translation from data attribute
                    addCard(cardId, phrase, translation);
                });
            });

            let searchForm = document.getElementById("searchForm");
            searchForm.addEventListener("submit", function (event) {
                event.preventDefault(); // Prevent immediate form submission

                saveAndExit().then(() => {
                    searchForm.submit(); // Submit the form after saveCards() completes
                }).catch(error => {
                    console.error("Error saving cards:", error);
                    searchForm.submit(); // Proceed even if saveCards() fails
                });
            });
        });


        function addCard(id, phrase, translation) {
            // Add a new card object with
            cards.push({
                id: id,
                phrase: phrase,
                translation: translation
            });

            // Refresh the list to include the new theme
            refreshCards();

            console.log('card added');
        };

        document.getElementById('saveCardsBtn').addEventListener('click', function () {
            saveAndExit();
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

        function saveAndExit() {
            console.log('saveCards started');
            document.getElementById('cardsInput').value = JSON.stringify(cards);
            document.getElementById('cardsForm').submit();
        }

        createArray();
        refreshCards();
    </script>
</x-html-layout>
