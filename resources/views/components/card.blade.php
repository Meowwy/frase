@props(['card'])
<x-panel outline="orange">
        <a href="/cards/{{$card->id}}" class="flex flex-wrap gap-3 justify-between items-center w-full">
            <div class="flex-col min-w-0">
                <div class="flex flex-wrap gap-2 text-xl">
                    <p class="break-words">{{$card->phrase}}</p>
                    <p>|</p>
                    <p>{{$card->translation}}</p>
                </div>
            </div>
            <div class="min-w-0">
                {{-- An expression's sentence is a two-line A:/B: exchange, so keep the breaks. --}}
                <p class="whitespace-pre-line">{!! $card->example_sentence !!}</p>
            </div>
        </a>

</x-panel>
