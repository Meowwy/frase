@props(['card'])
<x-panel outline="orange">
        <a href="/cards/{{$card->id}}" class="flex gap-3 justify-between items-center w-full">
            <div class="flex-col">
                <div class="flex gap-2 text-xl">
                    <p>{{$card->phrase}}</p>
                    <p>|</p>
                    <p>{{$card->translation}}</p>
                </div>
            </div>
            <div class="">
                <p>{!! $card->example_sentence !!}</p>
            </div>
        </a>

</x-panel>
