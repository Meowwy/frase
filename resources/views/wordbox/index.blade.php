<x-html-layout>
    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <x-panel>
            <div class="flex justify-between items-start mb-4">
                <h1 class="text-3xl font-bold">{{ $wordbox->name }}</h1>
                <a href="{{ route('wordbox.edit', $wordbox->id) }}" title="Edit wordbox"
                   class="text-white/70 hover:text-white transition-colors shrink-0 max-lg:-m-2.5 max-lg:p-2.5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </a>
            </div>
            <p class="text-white/70">{{ $wordbox->description }}</p>
            <div class="mt-4 pt-4 border-t border-white/10 space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-white/50">Words</span>
                    <span class="font-bold text-blue-400">{{ $words->count() }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-white/50">Language</span>
                    <span class="font-bold text-blue-400">{{ $wordbox->language->flag }} {{ $wordbox->language->name }}</span>
                </div>
            </div>
        </x-panel>

        {{-- A new exercise is generated from the ticked words: all of them, up to the 30 one
             story can carry. --}}
        <x-panel>
            <h2 class="text-xl font-bold mb-1">Gap-Fill Exercise</h2>
            <p class="text-sm text-white/50 mb-4">A short story with a gap for each ticked word, to fill in the form its sentence needs.</p>
            @if($wordbox->latestGapFillExercise)
                <a href="{{ route('gap-fill.show', $wordbox->latestGapFillExercise) }}" class="mb-4 block">
                    <x-forms.button class="w-full max-lg:min-h-12">See latest exercise</x-forms.button>
                </a>
            @endif
            @if($words->isEmpty())
                <p class="text-sm text-white/40">Add words to this wordbox to generate one.</p>
            @else
                <form action="{{ route('gapfill.generate', $wordbox->id) }}" method="GET" class="space-y-3">
                    <div class="max-h-48 overflow-y-auto rounded-lg border border-white/10 p-2">
                        @foreach($words as $word)
                            <label class="flex cursor-pointer items-baseline gap-2 px-1 py-0.5 text-sm max-lg:py-2 max-lg:text-base">
                                <input type="checkbox" name="base_word_ids[]" value="{{ $word->id }}" @checked($loop->index < 30)
                                       class="h-4 w-4 shrink-0 rounded border-white/30 bg-transparent">
                                <span>{{ $word->displayForm() }}</span>
                                <span class="truncate text-white/40">{{ $word->translation }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('base_word_ids')
                        <p class="text-sm text-red-500">{{ $message }}</p>
                    @enderror
                    <x-forms.input name="theme_preference" placeholder="Theme (optional, e.g. 'At the airport')" class="!py-2 !px-3 text-sm max-lg:text-base" />
                    <x-forms.button class="w-full max-lg:min-h-12">Generate</x-forms.button>
                </form>
            @endif
        </x-panel>
    </div>

    @include('base._table')
</x-html-layout>
