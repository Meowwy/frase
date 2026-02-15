@props(['foundCards' => [], 'searchTermWb' => ''])
<x-html-layout>
    <div class="container mx-auto p-6">
        <div class="flex flex-col lg:flex-row gap-8">
            <!-- Left Column: Wordbox Info & Inventory -->
            <div class="lg:w-1/2 space-y-6">
                <x-panel>
                    <h2 class="text-xl font-bold mb-4">Wordbox Settings</h2>
                    <x-forms.form method="post" action="/wordbox/{{$wordbox->id}}" class="max-w-full">
                        @csrf
                        @method('PATCH')
                        <input hidden value="{{$wordbox->id}}" name="id"/>
                        <x-forms.input value="{{$wordbox->name}}" label="Name" name="name"/>
                        <div class="flex justify-end mt-4">
                            <x-forms.button>Save Settings</x-forms.button>
                        </div>
                    </x-forms.form>
                </x-panel>

                <x-panel>
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h2 class="text-xl font-bold">Inventory</h2>
                            <p class="text-sm text-white/50">Cards currently in this wordbox.</p>
                        </div>
                        <a href="/wordbox/{{$wordbox->id}}">
                            <x-forms.button-small>Back to wordbox</x-forms.button-small>
                        </a>
                    </div>

                    <!-- Card List -->
                    <div id="cardList" class="space-y-3 max-h-[600px] overflow-y-auto pr-2">
                        <!-- Inventory cards will appear here -->
                    </div>

                    <x-forms.form id="cardsForm" method="POST" action="/saveCards/{{$wordbox->id}}" class="hidden">
                        <input id="cardsInput" type="hidden" name="cards">
                    </x-forms.form>

                    <div class="mt-6 pt-6 border-t border-white/10 flex justify-between items-center">
                        <p class="text-sm text-white/50"><span id="cardCount">0</span> cards</p>
                        <x-forms.button id="saveCardsBtn">Save Changes</x-forms.button>
                    </div>
                </x-panel>
            </div>

            <!-- Right Column: Search & Discovery -->
            <div class="lg:w-1/2 space-y-6">
                <x-panel>
                    <h2 class="text-xl font-bold mb-4">Search & Discovery</h2>
                    <p class="text-sm text-white/50 mb-6">Find and add existing cards to this wordbox.</p>

                    <div id="searchContainer" class="w-full">
                        <x-forms.input-search id="ajaxSearchInput" name="searchTerm" placeholder="Type to search cards..." class="w-full"></x-forms.input-search>
                    </div>

                    <div id="searchResults" class="mt-6 space-y-3 max-h-[600px] overflow-y-auto pr-2">
                        <p class="text-center text-white/30 py-10">Start typing to find cards...</p>
                    </div>
                </x-panel>
            </div>
        </div>
    </div>

    <script>
        let cards = [];

        function createArray() {
            cards = @json($cards);
        }

        function refreshCards() {
            const cardList = document.getElementById('cardList');
            const cardCount = document.getElementById('cardCount');
            cardList.innerHTML = '';
            cardCount.textContent = cards.length;

            if (cards.length === 0) {
                cardList.innerHTML = '<p class="text-center text-white/30 py-10">No cards in this wordbox yet.</p>';
                return;
            }

            cards.forEach(card => {
                const cardDiv = document.createElement('div');
                cardDiv.className = 'bg-white/5 border border-white/10 p-3 rounded-lg flex justify-between items-center group hover:border-red-500/50 transition-colors';

                const infoDiv = document.createElement('div');
                infoDiv.className = 'min-w-0 flex-grow';
                infoDiv.innerHTML = `
                    <div class="flex items-center gap-2">
                        <span class="font-bold truncate">${card.phrase}</span>
                        <span class="text-white/30">|</span>
                        <span class="text-white/70 truncate text-sm">${card.translation}</span>
                    </div>
                `;

                const removeBtn = document.createElement('button');
                removeBtn.className = 'text-white/30 hover:text-red-500 p-2 transition-colors';
                removeBtn.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                `;
                removeBtn.onclick = function () {
                    deleteCard(card.id);
                };

                cardDiv.appendChild(infoDiv);
                cardDiv.appendChild(removeBtn);
                cardList.appendChild(cardDiv);
            });

            // Update search results if they are visible
            updateSearchButtons();
        }

        function updateSearchButtons() {
            const resultsButtons = document.querySelectorAll('#searchResults button');
            resultsButtons.forEach(btn => {
                const cardId = parseInt(btn.id.replace('card-', ''));
                const isAlreadyIn = cards.some(c => c.id === cardId);

                if (isAlreadyIn) {
                    btn.textContent = 'Added';
                    btn.disabled = true;
                    btn.className = 'bg-gray-600 text-white text-xs font-bold rounded-full px-4 py-2 cursor-not-allowed transition-colors uppercase';
                } else {
                    btn.textContent = 'Add';
                    btn.disabled = false;
                    btn.className = 'bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-full px-4 py-2 transition-colors uppercase';
                }
            });
        }

        document.addEventListener("DOMContentLoaded", function () {
            const searchInput = document.getElementById('ajaxSearchInput');
            const searchResults = document.getElementById('searchResults');

            // Handle card action events from x-card-wordbox component (if used via AJAX)
            document.addEventListener('card-action', function(e) {
                const { id, phrase, translation } = e.detail;
                addCard(id, phrase, translation);
            });

            searchInput.addEventListener('input', function() {
                const query = this.value;
                if (query.length < 2) {
                    searchResults.innerHTML = '<p class="text-center text-white/30 py-10">Start typing to find cards...</p>';
                    return;
                }

                $.ajax({
                    url: "{{ route('seachWordbox', $wordbox->id) }}",
                    method: 'GET',
                    data: { searchTerm: query },
                    success: function(response) {
                        searchResults.innerHTML = '';
                        if (response.cards.length === 0) {
                            searchResults.innerHTML = '<p class="text-center text-white/30 py-10">No results found</p>';
                            return;
                        }

                        response.cards.forEach(card => {
                            const isAlreadyIn = cards.some(c => c.id === card.id);

                            const cardWrapper = document.createElement('div');
                            cardWrapper.className = 'w-full';

                            // We'll build the HTML similar to x-card-wordbox component
                            cardWrapper.innerHTML = `
                                <div class="bg-white/5 rounded-xl border border-transparent hover:border-orange-800 p-2 group transition-colors duration-300 w-full">
                                    <div class="flex gap-3 justify-between items-center w-full">
                                        <div class="flex-grow min-w-0">
                                            <a href="/cards/${card.id}" class="flex gap-2 text-lg font-bold hover:text-blue-400 transition-colors">
                                                <p class="truncate">${card.phrase}</p>
                                                <p class="text-white/30">|</p>
                                                <p class="truncate text-white/70 font-normal text-sm">${card.translation}</p>
                                            </a>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <button
                                                id="card-${card.id}"
                                                onclick="addCard(${card.id}, '${card.phrase.replace(/'/g, "\\'")}', '${card.translation.replace(/'/g, "\\'")}')"
                                                ${isAlreadyIn ? 'disabled' : ''}
                                                class="${isAlreadyIn ? 'bg-gray-600 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-500'} text-white text-xs font-bold rounded-full px-4 py-2 transition-colors uppercase">
                                                ${isAlreadyIn ? 'Added' : 'Add'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                            searchResults.appendChild(cardWrapper);
                        });
                    }
                });
            });
        });

        function addCard(id, phrase, translation) {
            if (cards.some(c => c.id === id)) {
                return;
            }

            cards.push({
                id: id,
                phrase: phrase,
                translation: translation
            });

            refreshCards();
            toastr.success('Card added to wordbox');
        }

        function deleteCard(id) {
            const index = cards.findIndex(card => card.id === id);
            if (index !== -1) {
                cards.splice(index, 1);
            }
            refreshCards();
            toastr.info('Card removed from list (unsaved)');
        }

        document.getElementById('saveCardsBtn').addEventListener('click', function () {
            saveAndExit();
        });

        function saveAndExit() {
            document.getElementById('cardsInput').value = JSON.stringify(cards);
            document.getElementById('cardsForm').submit();
        }

        createArray();
        refreshCards();
    </script>
</x-html-layout>
