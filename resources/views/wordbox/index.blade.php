<x-html-layout maxWidth="max-w-[2100px]">
    <div class="container mx-auto p-6">
        <div class="flex gap-6">
            <!-- Left Column -->
            <div class="w-1/3 space-y-6">
                <!-- Wordbox Info Panel -->
                <x-panel>
                    <div class="w-full">
                        <div class="flex justify-between items-start mb-4">
                            <h1 class="text-3xl font-bold">{{$wordbox->name}}</h1>
                            <a href="{{ $wordbox->id }}/edit">
                                <x-forms.button-small>Edit</x-forms.button-small>
                            </a>
                        </div>
                        <p class="text-white/70">{{ $wordbox->description }}</p>
                        <div class="mt-4 pt-4 border-t border-white/10 flex justify-between text-sm">
                            <span class="text-white/50">Total Cards</span>
                            <span class="font-bold text-blue-400">{{ $cards->count() }}</span>
                        </div>
                    </div>
                </x-panel>

                <!-- New Card Input -->
                <x-panel>
                    <h2 class="text-lg font-bold mb-4">Quick Add Card</h2>
                    <x-forms.form action="{{ url('captureWordAjax') }}" method="post" id="addWord">
                        <x-forms.input :label="false" name="capturedWord" id="captureWord"
                                       placeholder="Word or phrase in English" class="flex-grow w-full min-w-[300px]"></x-forms.input>
                        <x-forms.input :label="false" name="context" id="context"
                                       placeholder="(Optional) Add context, like a sentence or brief description of the term..."></x-forms.input>
                        <div class="flex items-center space-x-3 mt-4">
                            <x-forms.button id="btnAdd">Add</x-forms.button>
                            <p id="info_creatingCard" class="hidden ml-3 font-bold">Creating card... Please wait</p>
                        </div>
                        <x-forms.input type="hidden" :label="false" name="wordbox_id" value="{{ $wordbox->id }}" />
                    </x-forms.form>
                </x-panel>

                <!-- Wordbox Summary (Coming Soon) -->
                <x-panel class="opacity-50">
                    <h2 class="text-lg font-bold mb-2">Wordbox Summary</h2>
                    <p class="text-sm text-white/50 italic">AI-generated summary of this wordbox's content will appear here.</p>
                </x-panel>

                <!-- AI Tasks -->
                <x-panel>
                    <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                        <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        AI Learning Tools
                    </h2>

                    <div class="space-y-4">
                        <div class="p-4 bg-white/5 border border-white/10 rounded-xl hover:bg-white/10 transition-colors group">
                            <h3 class="font-bold text-lg mb-1">Gap-Fill Exercise</h3>
                            <p class="text-sm text-white/50 mb-4">Generate a dynamic text with blanks based on the cards in this wordbox.</p>
                            <a href="{{ route('gapfill.generate', ['wbid' => $wordbox->id]) }}" class="inline-block">
                                <x-forms.button class="text-sm px-4 py-2">Generate Now</x-forms.button>
                            </a>
                        </div>

                        <div class="p-4 bg-white/5 border border-white/10 rounded-xl opacity-50 cursor-not-allowed">
                            <h3 class="font-bold text-lg mb-1">Contextual Story</h3>
                            <p class="text-sm text-white/50 mb-2">Create a short story using all your words to see them in action.</p>
                            <span class="text-xs font-bold uppercase tracking-wider text-blue-400">Coming Soon</span>
                        </div>
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
                    <div class="w-full">
                        <table class="min-w-full divide-y divide-white/10">
                            <thead>
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-white/50 uppercase tracking-wider">Term</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-white/50 uppercase tracking-wider">Definition</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-white/50 uppercase tracking-wider">Translation</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                            @forelse ($cards as $card)
                                <tr class="hover:bg-white/5 cursor-pointer transition-colors" onclick="window.location='/cards/{{ $card->id }}'">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-white">{{ $card->phrase }}</td>
                                    <td class="px-6 py-4 text-sm text-white/70">{{ $card->definition }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-white/70">{{ $card->translation }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-10 text-center text-white/30">
                                        No cards found in this wordbox.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>
        </div>
    </div>
</x-html-layout>

