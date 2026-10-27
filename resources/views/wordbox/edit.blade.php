<x-html-layout>
    <div class="container mx-auto p-6 space-y-8 max-lg:p-0 max-lg:space-y-4">
        <!-- Top Section: Wordbox Settings -->
        <x-panel>
            <h2 class="text-xl font-bold mb-4">Wordbox Settings</h2>
            <x-forms.form method="post" action="/wordbox/{{$wordbox->id}}" class="max-w-full">
                @csrf
                @method('PATCH')
                <input hidden value="{{$wordbox->id}}" name="id"/>
                <x-forms.input value="{{$wordbox->name}}" label="Name" name="name"/>
                <div class="flex justify-end mt-4">
                    <x-forms.button class="max-lg:w-full max-lg:min-h-12">Save Settings</x-forms.button>
                </div>
            </x-forms.form>
        </x-panel>

        <!-- Search & Discovery -->
        <x-panel>
            <h2 class="text-xl font-bold mb-4">Search & Discovery</h2>
            <p class="text-sm text-white/50 mb-6">Find words in your vocabulary base and add them to this wordbox.</p>

            <div id="searchContainer" class="w-full">
                <x-forms.input-search id="ajaxSearchInput" name="searchTerm" placeholder="Type to search words..." class="w-full"></x-forms.input-search>
            </div>

            <div id="searchResults" class="mt-6 space-y-3 max-h-[600px] overflow-y-auto pr-2 max-lg:max-h-[50vh]">
                <p class="text-center text-white/30 py-10">Start typing to find words...</p>
            </div>
        </x-panel>

        <!-- Inventory (Full Width) -->
        <x-panel>
            <div class="flex flex-wrap justify-between items-center gap-3 mb-6">
                <div>
                    <h2 class="text-xl font-bold">Inventory</h2>
                    <p class="text-sm text-white/50">Words currently in this wordbox.</p>
                </div>
                <a href="/wordbox/{{$wordbox->id}}" class="max-lg:py-2">
                    <x-forms.button-small>Back to wordbox</x-forms.button-small>
                </a>
            </div>

            <!-- Word List -->
            <div id="wordList" class="space-y-3 max-h-[600px] overflow-y-auto pr-2 max-lg:max-h-[50vh]">
                <!-- Inventory words will appear here -->
            </div>

            <x-forms.form id="wordsForm" method="POST" action="{{ route('wordbox.words', $wordbox->id) }}" class="hidden">
                <div id="wordsInputs"></div>
            </x-forms.form>

            <div class="mt-6 pt-6 border-t border-white/10 flex justify-between items-center">
                <p class="text-sm text-white/50"><span id="wordCount">0</span> words</p>
                <x-forms.button id="saveWordsBtn" class="max-lg:min-h-12">Save Changes</x-forms.button>
            </div>
        </x-panel>

        <x-panel>
            <h2 class="text-xl font-bold mb-2">Delete wordbox</h2>
            <p class="text-sm text-white/50 mb-4">Removes the wordbox and its gap-fill exercises. The words stay in your vocabulary base.</p>
            <x-forms.button-delete type="button" onclick="openModal('confirm-delete-wordbox')" class="max-lg:w-full">Delete wordbox</x-forms.button-delete>
        </x-panel>
    </div>

    <x-modal name="confirm-delete-wordbox" title="Delete this wordbox?">
        <p class="mb-6 text-white/60">
            <span class="font-bold text-white">{{ $wordbox->name }}</span> and its gap-fill exercises will be deleted. The words stay in the base.
        </p>
        <x-forms.form method="post" action="/wordbox/{{$wordbox->id}}" class="max-w-full">
            @csrf
            @method('DELETE')
            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" onclick="closeModal('confirm-delete-wordbox')"
                        class="px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all max-lg:min-h-12 max-lg:flex-1">
                    Cancel
                </button>
                <button type="submit"
                        class="px-5 py-2 rounded-xl font-bold bg-red-600 hover:bg-red-500 text-white transition-all max-lg:min-h-12 max-lg:flex-1">
                    Delete
                </button>
            </div>
        </x-forms.form>
    </x-modal>

    <script>
        // The wordbox's words as {id, form, translation}; saved as a whole list.
        const words = @json($words);

        // Built with createElement/textContent rather than innerHTML template interpolation —
        // forms and translations trace back to AI-generated (user-influenced) text and must
        // never be parsed as markup.
        function wordInfo(word) {
            const info = document.createElement('div');
            info.className = 'min-w-0 flex-grow flex items-center gap-2';

            const form = document.createElement('span');
            form.className = 'font-bold truncate';
            form.textContent = word.form;

            const sep = document.createElement('span');
            sep.className = 'text-white/30';
            sep.textContent = '|';

            const translation = document.createElement('span');
            translation.className = 'text-white/70 truncate text-sm';
            translation.textContent = word.translation;

            info.append(form, sep, translation);

            return info;
        }

        function refreshWords() {
            const wordList = document.getElementById('wordList');
            wordList.innerHTML = '';
            document.getElementById('wordCount').textContent = words.length;

            if (words.length === 0) {
                wordList.innerHTML = '<p class="text-center text-white/30 py-10">No words in this wordbox yet.</p>';
                return;
            }

            words.forEach(word => {
                const row = document.createElement('div');
                row.className = 'bg-white/5 border border-white/10 p-3 rounded-lg flex justify-between items-center group hover:border-red-500/50 transition-colors';

                const removeBtn = document.createElement('button');
                removeBtn.className = 'text-white/30 hover:text-red-500 p-2 transition-colors max-lg:p-3';
                removeBtn.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                `;
                removeBtn.onclick = () => removeWord(word.id);

                row.append(wordInfo(word), removeBtn);
                wordList.appendChild(row);
            });

            updateSearchButtons();
        }

        function setAddButton(btn, isAlreadyIn) {
            btn.textContent = isAlreadyIn ? 'Added' : 'Add';
            btn.disabled = isAlreadyIn;
            btn.className = (isAlreadyIn ? 'bg-gray-600 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-500') + ' text-white text-xs font-bold rounded-full px-4 py-2 transition-colors uppercase max-lg:min-h-11';
        }

        function updateSearchButtons() {
            document.querySelectorAll('#searchResults button').forEach(btn => {
                setAddButton(btn, words.some(w => w.id === Number(btn.dataset.wordId)));
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const searchResults = document.getElementById('searchResults');

            document.getElementById('ajaxSearchInput').addEventListener('input', function () {
                const query = this.value;
                if (query.length < 2) {
                    searchResults.innerHTML = '<p class="text-center text-white/30 py-10">Start typing to find words...</p>';
                    return;
                }

                $.ajax({
                    url: "{{ route('wordbox.search', $wordbox->id) }}",
                    method: 'GET',
                    data: { searchTerm: query },
                    success: function (response) {
                        searchResults.innerHTML = '';
                        if (response.words.length === 0) {
                            searchResults.innerHTML = '<p class="text-center text-white/30 py-10">No results found</p>';
                            return;
                        }

                        response.words.forEach(word => {
                            const row = document.createElement('div');
                            row.className = 'bg-white/5 rounded-xl border border-transparent hover:border-orange-800 p-2 flex gap-3 justify-between items-center w-full transition-colors duration-300';

                            const addBtn = document.createElement('button');
                            addBtn.dataset.wordId = word.id;
                            setAddButton(addBtn, words.some(w => w.id === word.id));
                            addBtn.addEventListener('click', () => addWord(word));

                            row.append(wordInfo(word), addBtn);
                            searchResults.appendChild(row);
                        });
                    }
                });
            });
        });

        function addWord(word) {
            if (words.some(w => w.id === word.id)) {
                return;
            }

            words.push(word);
            refreshWords();
            toastr.success('Word added to wordbox');
        }

        function removeWord(id) {
            const index = words.findIndex(w => w.id === id);
            if (index !== -1) {
                words.splice(index, 1);
            }
            refreshWords();
            toastr.info('Word removed from list (unsaved)');
        }

        document.getElementById('saveWordsBtn').addEventListener('click', function () {
            const inputs = document.getElementById('wordsInputs');
            words.forEach(word => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = word.id;
                inputs.appendChild(input);
            });
            document.getElementById('wordsForm').submit();
        });

        refreshWords();
    </script>
</x-html-layout>
