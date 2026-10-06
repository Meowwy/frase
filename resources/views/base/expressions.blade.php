<x-html-layout>
    @include('base._header', ['tab' => 'expressions'])

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        <div class="min-w-0 flex-1">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700 bg-white/5">
                    <thead>
                    <tr>
                        @include('base._search-input', ['name' => 'search', 'placeholder' => 'Expression', 'value' => $search])
                        @include('base._search-input', ['name' => 'translation', 'placeholder' => 'Translation', 'value' => $translation])
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Cards</th>
                    </tr>
                    </thead>
                    <tbody id="baseRows" class="divide-y divide-gray-700">
                    @include('base._expression-rows')
                    </tbody>
                </table>
            </div>

            <div id="basePagination" class="mt-4">{{ $expressions->links() }}</div>
        </div>

        @include('base._panel', ['tab' => 'expressions'])
    </div>
</x-html-layout>
