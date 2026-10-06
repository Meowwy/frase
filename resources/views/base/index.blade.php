<x-html-layout>
    @include('base._header', ['tab' => 'words'])

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
