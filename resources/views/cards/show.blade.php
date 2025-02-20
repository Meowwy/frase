@props(["card", "theme"])
<x-html-layout>
    <div class="max-w-4xl mx-auto p-6 shadow-lg rounded-lg">
        <!-- Main Term Section -->
        <div class="mb-6 space-x-3">
            <span class="text-4xl font-bold">{{$card->phrase}}</span>
            <span class="text-xl italic">{{$card->translation}}</span>
        </div>

        <!-- Definition Section -->
        <div class="mb-6">
            @if(!is_null($theme))
                <span class="capitalize text-sm mr-1 font-bold bg-orange-700 text-white rounded-full px-3 py-1">{{$theme}}</span>
            @endif
            <span class="">{{$card->definition}}</span>
        </div>

        <!-- Example Sentence Section -->

        <div class="mb-8">
            <ul class="list-disc list-outside pl-5">
                <li class="ml-4">
                    <p class="text-gray-400 font-medium">
                        {!! $card->example_sentence !!}
                    </p>
                </li>
                <li class="ml-4">
                    <p class="text-gray-400 font-medium">
                        {{$card->question}} {{$card->phraseCaps}}.
                    </p>
                </li>
            </ul>


        </div>

        <table class="mb-4 table-auto text-left text-white max-w-2xl divide-gray-700 bg-white/5">
            <thead class="text-white">
            <tr>
                <th class="px-4 py-3 text-xl">Last studied</th>
                <th class="px-4 py-3 text-xl">Level</th>
                <th class="px-4 py-3 text-xl">Next study scheduled on</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
                <tr class="">
                    <td class="px-4 py-2 text-center">
                        @if(is_null($card->last_studied))
                            <span class="text-xl">Not studied</span>
                        @elseif($card->last_studied_days == 0)
                            <span class="text-xl">Today</span>
                        @elseif($card->last_studied_days == 1)
                            <span class="text-xl">{{$card->last_studied_days}} day ago</span>
                            <span class="text-sm"> ({{$card->last_studied}})</span>
                        @else
                            <span class="text-xl">{{$card->last_studied_days}} days ago</span>
                            <span class="text-sm"> ({{$card->last_studied}})</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-center text-xl">{{$card->level}}</td>
                    <td class="px-4 py-2 text-center text-xl">{{$card->next_study_at}}</td>
                </tr>
            </tbody>
        </table>
        <div class="flex flex-col space-y-4">
            <a href="/cards/edit/{{$card->id}}">
                <x-forms.button-confirm>Edit card</x-forms.button-confirm>
            </a>
            <a href="/cards">
                <x-forms.button-small>Back to card list</x-forms.button-small>
            </a>
        </div>

    </div>

</x-html-layout>
