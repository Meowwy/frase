<x-html-layout maxWidth="max-w-[2100px]">
    <div class="container mx-auto p-6">
        <div class="flex gap-6">
            <!-- Left Column -->
            <div class="w-1/3 space-y-6">
                <!-- New Card Input -->
                <x-panel>
                    <h2 class="text-lg font-bold mb-2">Add New Card</h2>
                    <x-forms.form action="{{ url('captureWordAjax') }}" method="post" id="addWord">
                        <x-forms.input :label="false" name="capturedWord" id="captureWord"
                                       placeholder="Word or phrase in English" class="flex-grow w-full min-w-[300px]"></x-forms.input>
                        <x-forms.input :label="false" name="context" id="context"
                                       placeholder="(Optional) Add context, like a sentence or brief description of the term..."></x-forms.input>
                        <div class="flex items-center space-x-3 mt-4">
                            <x-forms.button id="btnAdd">Add</x-forms.button>
                            <p id="info_creatingCard" class="hidden ml-3 font-bold">Creating card... Please wait</p>
                        </div>
                        <x-forms.input type="hidden" :label="false" name="wordbox_id" value="{{ $wordboxId }}" />
                    </x-forms.form>
                </x-panel>

                <!-- Wordbox Summary -->
                <x-panel>
                    <h2 class="text-lg font-bold mb-2">Wordbox Summary</h2>
                    <p class="w-full p-3 border border-gray-300 rounded-lg" placeholder="Enter summary of your wordbox..."></p>
                </x-panel>

                <!-- AI Tasks -->
                <x-panel>
                    <h2 class="text-lg font-bold mb-2">AI Tasks</h2>
                    <div class="flex space-x-3">
                        <x-forms.button>Task 1</x-forms.button>
                        <x-forms.button>Task 2</x-forms.button>
                    </div>
                </x-panel>

                <!-- Learning Options -->
                <h2>Learn through...</h2>
                <div class="flex flex-wrap justify-center gap-2">
                    <x-panel class="w-48">
                        <div class="py-2">
                            <h3 class="group-hover:text-blue-600 text-xl font-bold transition-colors duration-100">
                                <a href="/startLearning/{{ $wordbox->id }}/sentences">Sentences</a>
                            </h3>
                            <p class="text-sm mt-2">Recall the word from an English sentence with a blank.</p>
                        </div>
                    </x-panel>
                    <x-panel class="w-48">
                        <div class="py-2">
                            <h3 class="group-hover:text-blue-600 text-xl font-bold transition-colors duration-100">
                                <a href="/startLearning/{{ $wordbox->id }}/questions">Questions</a>
                            </h3>
                            <p class="text-sm mt-2">Recall the word when asked about it.</p>
                        </div>
                    </x-panel>
                    <x-panel class="w-48">
                        <div class="py-2">
                            <h3 class="group-hover:text-blue-600 text-xl font-bold transition-colors duration-100">
                                <a href="/startLearning/{{ $wordbox->id }}/words">Words</a>
                            </h3>
                            <p class="text-sm mt-2">Recall the English translation from the Czech word.</p>
                        </div>
                    </x-panel>
                    <x-panel class="w-48">
                        <div class="py-2">
                            <h3 class="group-hover:text-blue-600 text-xl font-bold transition-colors duration-100">
                                <a href="/startLearning/{{ $wordbox->id }}/definitions">Definitions</a>
                            </h3>
                            <p class="text-sm mt-2">Recall the English translation from an English definition.</p>
                        </div>
                    </x-panel>
                </div>
            </div>

            <!-- Right Column: Cards Table -->
            <div class="w-2/3 space-y-4">
                <x-panel>
                    <div class="flex justify-between w-full">
                        <h1 class="text-3xl">{{$wordbox->name}}</h1>
                        <x-forms.button-small>
                            Edit wordbox
                        </x-forms.button-small>
                    </div>

                </x-panel>
                <x-panel>
                    <table class="min-w-full divide-y divide-gray-700 bg-white/5">
                        <thead>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Term</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Definition</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Translation</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                        @foreach ($cards as $card)
                            <tr class="hover:bg-white/10 cursor-pointer" onclick="window.location='/cards/{{ $card->id }}'">
                                <td class="px-6 py-2 whitespace-nowrap text-sm font-medium text-white">{{ $card->phrase }}</td>
                                <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300">{{ $card->definition }}</td>
                                <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300">{{ $card->translation }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                </x-panel>
            </div>
        </div>
    </div>
</x-html-layout>

