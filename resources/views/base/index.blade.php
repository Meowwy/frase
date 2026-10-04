<x-html-layout>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold">Vocabulary base</h1>
            <p class="text-sm text-white/50">
                Every word you have met in this language, one entry per lemma and part of
                speech. It carries no review schedule — that lives on your cards.
            </p>
        </div>
        <a href="{{ route('refresher', ['language_id' => $activeLanguageId]) }}">
            <x-forms.button>Refresher</x-forms.button>
        </a>
    </div>

    @if($targetLanguages->count() > 1)
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($targetLanguages as $language)
                <a href="{{ route('base', ['language_id' => $language->id]) }}"
                   @class([
                       'rounded-lg border border-white/10 px-3 py-1 text-sm transition-colors',
                       'bg-blue-600/30 ring-1 ring-blue-500' => (int) $activeLanguageId === $language->id,
                       'bg-white/5 hover:bg-white/10' => (int) $activeLanguageId !== $language->id,
                   ])>
                    {{ $language->flag }} {{ $language->name }}
                </a>
            @endforeach
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 bg-white/5">
            <thead>
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Word</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Part of speech</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Translation</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Cards</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Last recalled</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
            @forelse($baseWords as $baseWord)
                <tr class="hover:bg-white/10">
                    {{-- The display form, not the bare lemma: a Swedish noun's article and a
                         Swedish verb's dictionary suffix are part of what the learner is
                         expected to learn. --}}
                    <td class="px-6 py-2 text-sm text-white">{{ $baseWord->displayForm() }}</td>
                    <td class="px-6 py-2 text-sm text-gray-400">{{ $baseWord->part_of_speech }}</td>
                    <td class="px-6 py-2 text-sm text-gray-300">{{ $baseWord->translation }}</td>
                    {{-- Coverage: a word used across many phrases is owned by no single
                         card's review, which is half of why the base exists. --}}
                    <td class="px-6 py-2 text-sm text-gray-400">{{ $baseWord->cards_count }}</td>
                    <td class="px-6 py-2 text-sm text-gray-400">
                        {{ $baseWord->last_recalled_at?->diffForHumans() ?? 'never' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-6 text-center text-sm text-gray-400">
                        No words yet — approve a proposal in staging and its words land here.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $baseWords->links() }}</div>
</x-html-layout>
