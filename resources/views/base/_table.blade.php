{{-- The vocabulary base's table and its side panel (see base._panel), shared by /base and a
     wordbox's page (ListsBaseEntries). --}}
<div class="flex flex-col gap-6 lg:flex-row lg:items-start">
    <div class="min-w-0 flex-1">
        {{-- Below lg the table stacks into a list (see base._rows) and the header's filters
             wrap into a compact block above it. --}}
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-700 bg-white/5 max-lg:block max-lg:rounded-xl">
                <thead class="max-lg:block">
                <tr class="max-lg:flex max-lg:flex-wrap max-lg:gap-x-4 max-lg:px-4 max-lg:py-2">
                    {{-- Leftmost checkbox column (hover-revealed per row). --}}
                    <th class="w-10 px-4 py-3 max-lg:hidden"></th>
                    @include('base._search-input', ['name' => 'search', 'placeholder' => 'Word', 'value' => $search])
                    <th class="px-6 py-3 text-left max-lg:w-32 max-lg:p-0 max-lg:py-1">
                        <select name="part_of_speech"
                                class="js-base-filter w-full border-0 border-b border-white/60 bg-transparent py-1 pl-0 text-xs font-medium text-gray-300 uppercase tracking-wider focus:outline-none focus:ring-0 max-lg:py-2 max-lg:text-base max-lg:normal-case max-lg:tracking-normal">
                            <option value="" class="bg-[#111]">All</option>
                            @foreach($partsOfSpeech as $option)
                                <option value="{{ $option }}" class="bg-[#111]" @selected($partOfSpeech === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </th>
                    @include('base._search-input', ['name' => 'translation', 'placeholder' => 'Translation', 'value' => $translation])
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider max-lg:hidden">Cards</th>
                </tr>
                </thead>
                <tbody id="baseRows" class="divide-y divide-gray-700 max-lg:block">
                @include('base._rows')
                </tbody>
            </table>
        </div>

        <div id="basePagination" class="mt-4">{{ $entries->links() }}</div>
        {{-- Below lg, room for the action bar while rows are checked (see base._panel). --}}
        <div id="baseActionSpacer" class="hidden h-16 lg:hidden" aria-hidden="true"></div>
    </div>

    @include('base._panel')
</div>
