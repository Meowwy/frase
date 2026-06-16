@props(["card", "theme", "wordbox", "synonyms", "relatedTerms"])
<x-html-layout>
    <div class="max-w-4xl mx-auto p-6 shadow-lg rounded-lg">
        <a href="/cards" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors mb-6">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            <span>back</span>
        </a>

        <!-- Main Term Section -->
        <div class="mb-6 flex items-baseline justify-between gap-3">
            <div class="space-x-3">
                <span class="text-4xl font-bold">{{$card->phrase}}</span>
                <span class="text-xl italic">{{$card->translation}}</span>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                @if($card->language)
                    <span class="text-xl leading-none">{{$card->language->flag}}</span>
                @endif
                <a href="/cards/edit/{{$card->id}}" title="Edit card"
                   class="text-white/70 hover:text-white transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </a>
            </div>
        </div>

        <!-- Definition Section -->
        <div class="mb-6">
            @if(!is_null($wordbox))
                <a href="{{ route('wordbox.show', $wordbox->id) }}"
                   class="capitalize text-sm mr-1 font-bold bg-orange-700 hover:bg-orange-600 text-white rounded-full px-3 py-1">{{$wordbox->name}}</a>
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

        @if($synonyms->isNotEmpty() || $relatedTerms->isNotEmpty())
            <div class="mb-8 space-y-4">
                @if($synonyms->isNotEmpty())
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Synonyms</h3>
                        <ul class="space-y-1">
                            @foreach($synonyms as $synonym)
                                <li>
                                    <a href="/cards/{{ $synonym->synonymCard->id }}"
                                       class="text-orange-400 hover:text-orange-300 hover:underline">
                                        {{ $synonym->synonymCard->phrase }}
                                        <span class="text-gray-500 text-sm ml-1">{{ $synonym->synonymCard->translation }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($relatedTerms->isNotEmpty())
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Related terms</h3>
                        <ul class="space-y-1">
                            @foreach($relatedTerms as $related)
                                <li>
                                    <a href="/cards/{{ $related->relatedCard->id }}"
                                       class="text-orange-400 hover:text-orange-300 hover:underline">
                                        {{ $related->relatedCard->phrase }}
                                        <span class="text-gray-500 text-sm ml-1">{{ $related->relatedCard->translation }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

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
    </div>

</x-html-layout>
