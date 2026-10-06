<x-html-layout>
    @include('base._header', ['tab' => 'words'])

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        <div class="min-w-0 flex-1">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700 bg-white/5">
                    <thead>
                    <tr>
                        @include('base._search-input', ['name' => 'search', 'placeholder' => 'Word', 'value' => $search])
                        <th class="px-6 py-3 text-left">
                            <select name="part_of_speech"
                                    class="js-base-filter w-full border-0 border-b border-white/60 bg-transparent py-1 pl-0 text-xs font-medium text-gray-300 uppercase tracking-wider focus:outline-none focus:ring-0">
                                <option value="" class="bg-[#111]">Part of speech</option>
                                @foreach($partsOfSpeech as $option)
                                    <option value="{{ $option }}" class="bg-[#111]" @selected($partOfSpeech === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </th>
                        @include('base._search-input', ['name' => 'translation', 'placeholder' => 'Translation', 'value' => $translation])
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Cards</th>
                    </tr>
                    </thead>
                    <tbody id="baseRows" class="divide-y divide-gray-700">
                    @include('base._word-rows')
                    </tbody>
                </table>
            </div>

            <div id="basePagination" class="mt-4">{{ $baseWords->links() }}</div>
        </div>

        @include('base._panel', ['tab' => 'words'])
    </div>
</x-html-layout>
