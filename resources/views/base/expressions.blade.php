<x-html-layout>
    @include('base._header', ['tab' => 'expressions'])

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 bg-white/5">
            <thead>
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Expression</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Translation</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Cards</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Last recalled</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
            @forelse($expressions as $expression)
                <tr class="hover:bg-white/10">
                    <td class="px-6 py-2 text-sm text-white">{{ $expression->form }}</td>
                    <td class="px-6 py-2 text-sm text-gray-300">{{ $expression->translation }}</td>
                    <td class="px-6 py-2 text-sm text-gray-400">
                        @foreach($expression->cards as $card)
                            <a href="/cards/{{ $card->id }}" class="block hover:text-white">{{ $card->term }}</a>
                        @endforeach
                    </td>
                    <td class="px-6 py-2 text-sm text-gray-400">
                        {{ $expression->last_recalled_at?->diffForHumans() ?? 'never' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-6 text-center text-sm text-gray-400">
                        No fixed expressions yet — they are picked out of the terms you capture.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $expressions->links() }}</div>
</x-html-layout>
