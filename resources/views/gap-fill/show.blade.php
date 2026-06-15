<x-html-layout>
    <div class="container mx-auto p-6 max-w-4xl"
         x-data="{
            answers: {{ json_encode($exercise->correct_answers ?? []) }},
            status: '{{ $exercise->status }}',
            feedback: {},
            showHistory: false,
            lastInput: null,
            deleteUrl: null,
            deleteTitle: '',
            poll() {
                fetch('{{ route('gap-fill.status', $exercise) }}')
                    .then(res => res.json())
                    .then(data => {
                        this.status = data.status;
                        if (data.status === 'completed') {
                            window.location.reload();
                        } else if (data.status !== 'failed') {
                            setTimeout(() => this.poll(), 2000);
                        }
                    })
                    .catch(() => setTimeout(() => this.poll(), 3000));
            },
            fill(word) {
                let target = this.lastInput;
                if (!target || target.value.trim() !== '') {
                    target = [...document.querySelectorAll('input[data-index]')].find(i => i.value.trim() === '');
                }
                if (target) { target.value = word; this.feedback[target.dataset.index] = null; }
            },
            check() {
                document.querySelectorAll('input[data-index]').forEach(i => {
                    let idx = i.dataset.index;
                    this.feedback[idx] = this.answers[idx].toLowerCase().trim() === i.value.toLowerCase().trim() ? 'correct' : 'wrong';
                });
            },
            reset() {
                this.feedback = {};
                document.querySelectorAll('input[data-index]').forEach(i => i.value = '');
            }
         }"
         x-init="if (status !== 'completed' && status !== 'failed') poll()">

        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold">{{ $exercise->title ?? 'Gap-Fill #'.$exercise->id }}</h1>
                <p class="text-sm text-white/50 mt-1">Gap-Fill &middot; {{ $exercise->wordbox->name }}</p>
            </div>
            <a href="{{ route('wordbox.show', $exercise->wordbox_id) }}" class="text-blue-400 hover:text-blue-300">
                &larr; Back to Wordbox
            </a>
        </div>

        <div class="bg-white/10 p-8 rounded-2xl border border-white/10 mb-8 leading-relaxed text-xl">
            @if($exercise->status === 'completed')
                {!! preg_replace_callback('/\[(\d+)\]/', function($matches) {
                    return '<input type="text" data-index="'.$matches[1].'"
                            @focus="lastInput = $event.target"
                            @input="feedback['.$matches[1].'] = null"
                            class="bg-transparent border-b-2 border-white/20 px-2 py-0 w-40 focus:outline-none focus:border-blue-500 transition-colors mx-1 text-blue-400 font-medium"
                            :class="feedback['.$matches[1].'] === \'correct\' ? \'!border-green-500 !text-green-500\' : (feedback['.$matches[1].'] === \'wrong\' ? \'!border-red-500 !text-red-500\' : \'\')"
                            placeholder="'.$matches[1].'">';
                }, $exercise->text_with_gaps) !!}
            @elseif($exercise->status === 'failed')
                <div class="flex items-center gap-3 text-red-400 text-base">
                    <svg class="w-6 h-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Generation failed. Please go back and try generating the exercise again.
                </div>
            @else
                <div class="flex items-center gap-3 text-white/50 text-base animate-pulse">
                    <svg class="animate-spin h-6 w-6 text-blue-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Exercise is still being generated&hellip;
                </div>
            @endif
        </div>

        <div class="p-6 bg-white/5 rounded-2xl border border-white/10 mb-8">
            <h2 class="text-sm font-bold mb-4 text-white/50 uppercase tracking-widest text-center">Words to use</h2>
            <div class="flex flex-wrap justify-center gap-3">
                @if($exercise->status === 'completed')
                    @foreach(collect($exercise->correct_answers)->values()->shuffle() as $word)
                        <button type="button" @click="fill(@js($word))"
                                class="bg-white/5 hover:bg-blue-600/30 text-blue-300 font-medium px-4 py-2 rounded-lg border border-white/10 hover:border-blue-500/50 transition-all">
                            {{ $word }}
                        </button>
                    @endforeach
                @else
                    @foreach(range(1, 6) as $i)
                        <div class="h-10 w-24 rounded-lg bg-white/5 border border-white/10 animate-pulse"></div>
                    @endforeach
                @endif
            </div>
        </div>

        @if($exercise->status === 'completed')
            <div class="flex flex-col items-center gap-6">
                <div class="flex gap-4">
                    <button @click="check()" class="bg-blue-600 hover:bg-blue-500 text-white px-8 py-3 rounded-xl font-bold transition-all shadow-lg hover:shadow-blue-500/20">
                        Check Answers
                    </button>
                    <button @click="reset()" class="bg-white/10 hover:bg-white/20 text-white px-8 py-3 rounded-xl font-bold transition-all border border-white/10">
                        Clear All
                    </button>
                </div>
            </div>
        @endif

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
                                <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $item->title ?? 'Exercise #'.$item->id }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $item->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <div class="flex items-center justify-end gap-4">
                                        @if($exercise->id !== $item->id)
                                            <a href="{{ route('gap-fill.show', $item) }}" class="text-blue-400 hover:text-blue-300">View &rarr;</a>
                                        @else
                                            <span class="text-white/30 italic">Current</span>
                                        @endif
                                        <button type="button"
                                                @click="deleteUrl = '{{ route('gap-fill.destroy', $item) }}'; deleteTitle = @js($item->title ?? 'Exercise #'.$item->id)"
                                                class="text-red-500 hover:text-red-400 font-medium">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Delete confirmation modal -->
        <div x-show="deleteUrl !== null" style="display: none;"
             @keydown.escape.window="deleteUrl = null"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
            <div @click.outside="deleteUrl = null" class="bg-neutral-900 border border-white/10 rounded-2xl p-6 max-w-md w-full shadow-xl">
                <h3 class="text-xl font-bold mb-2">Delete exercise?</h3>
                <p class="text-white/60 mb-6">
                    This will permanently delete &ldquo;<span class="text-white font-medium" x-text="deleteTitle"></span>&rdquo;. This action cannot be undone.
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteUrl = null"
                            class="px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all">
                        Cancel
                    </button>
                    <form :action="deleteUrl" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="px-5 py-2 rounded-xl font-bold bg-red-600 hover:bg-red-500 text-white transition-all">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-html-layout>
