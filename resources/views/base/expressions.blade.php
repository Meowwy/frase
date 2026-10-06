<x-html-layout>
    @include('base._header', ['tab' => 'expressions'])

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        <div class="min-w-0 flex-1">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700 bg-white/5">
                    <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Expression</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Translation</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Cards</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                    @forelse($expressions as $expression)
                        <tr class="js-base-row cursor-pointer hover:bg-white/10">
                            <td class="px-6 py-2 text-sm text-white">
                                {{ $expression->form }}
                                @include('base._row-cards', ['title' => $expression->form, 'cards' => $expression->cards])
                            </td>
                            <td class="px-6 py-2 text-sm text-gray-300">{{ $expression->translation }}</td>
                            <td class="px-6 py-2 text-sm text-gray-400">{{ $expression->cards->count() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-6 text-center text-sm text-gray-400">
                                No fixed expressions yet — they are picked out of the terms you capture.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $expressions->links() }}</div>
        </div>

        @include('base._panel')
    </div>
</x-html-layout>
