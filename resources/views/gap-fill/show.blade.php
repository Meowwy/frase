<x-html-layout>
    <div class="container mx-auto p-6 max-w-4xl"
         x-data="{
            answers: {{ json_encode($exercise->correct_answers) }},
            showFeedback: false,
            showHistory: false,
            check() {
                this.showFeedback = true;
            },
            reset() {
                this.showFeedback = false;
                document.querySelectorAll('input').forEach(i => i.value = '');
            }
         }">

        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">Gap-Fill: {{ $exercise->wordbox->name }}</h1>
            <a href="{{ route('wordbox.show', $exercise->wordbox_id) }}" class="text-blue-400 hover:text-blue-300">
                &larr; Back to Wordbox
            </a>
        </div>

        <div class="bg-white/10 p-8 rounded-2xl border border-white/10 mb-8 leading-relaxed text-xl">
            {!! preg_replace_callback('/\[(\d+)\]/', function($matches) {
                return '<input type="text" data-index="'.$matches[1].'"
                        class="bg-transparent border-b-2 border-white/20 px-2 py-0 w-40 focus:outline-none focus:border-blue-500 transition-colors mx-1 text-blue-400 font-medium"
                        :class="showFeedback ? (answers['.$matches[1].'].toLowerCase().trim() === $el.value.toLowerCase().trim() ? \'!border-green-500 !text-green-500\' : \'!border-red-500 !text-red-500\') : \'\'"
                        placeholder="'.$matches[1].'">';
            }, $exercise->text_with_gaps) !!}
        </div>

        <div class="flex flex-col items-center gap-6">
            <div class="flex gap-4">
                <button @click="check()" class="bg-blue-600 hover:bg-blue-500 text-white px-8 py-3 rounded-xl font-bold transition-all shadow-lg hover:shadow-blue-500/20">
                    Check Answers
                </button>
                <button @click="reset()" class="bg-white/10 hover:bg-white/20 text-white px-8 py-3 rounded-xl font-bold transition-all border border-white/10">
                    Clear All
                </button>
            </div>

            <div x-show="showFeedback" x-transition class="p-6 bg-white/5 rounded-2xl border border-white/10 w-full">
                <h2 class="text-lg font-bold mb-4 text-white/70 uppercase tracking-widest text-center">Answer Key</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($exercise->correct_answers as $index => $answer)
                        <div class="flex items-center gap-3 p-3 bg-white/5 rounded-lg border border-white/5">
                            <span class="bg-blue-500/20 text-blue-400 w-8 h-8 flex items-center justify-center rounded-full font-bold text-sm">
                                {{ $index }}
                            </span>
                            <span class="font-medium">{{ $answer }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="mt-12 pt-8 border-t border-white/10">
            <button @click="showHistory = !showHistory" class="flex items-center gap-2 text-white/50 hover:text-white transition-colors mx-auto uppercase tracking-widest text-sm font-bold">
                <svg class="w-4 h-4 transition-transform" :class="showHistory ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
                Show all exercises for this wordbox
            </button>

            <div x-show="showHistory" x-transition class="mt-8 bg-white/5 rounded-2xl border border-white/10 overflow-hidden">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-white/5">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold text-white/50 uppercase tracking-wider w-16">#</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-white/50 uppercase tracking-wider">Title (ID)</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-white/50 uppercase tracking-wider">Created At</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-white/50 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach($allExercises as $index => $item)
                            <tr class="transition-colors {{ $exercise->id === $item->id ? 'bg-blue-900 text-white font-bold' : 'hover:bg-white/5 text-white/70' }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $index + 1 }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">Exercise #{{ $item->id }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $item->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    @if($exercise->id !== $item->id)
                                        <a href="{{ route('gap-fill.show', $item) }}" class="text-blue-400 hover:text-blue-300">View &rarr;</a>
                                    @else
                                        <span class="text-white/30 italic">Current</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-html-layout>
