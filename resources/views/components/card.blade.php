@props(['card'])
<x-panel outline="orange">
        <a href="/" class="flex gap-3 justify-between items-center w-full">
            <div class="flex-col">
                <div class="flex gap-2 text-xl">
                    <p>{{$card->phrase}}</p>
                    <p>|</p>
                    <p>{{$card->translation}}</p>
                </div>
            </div>
            <div class="">
                <p>{{$card->example_sentence}}</p>
            </div>
            <div class="flex space-x-3">
                <p>Last studied</p>
                <p>Will be studied</p>
            </div>
        </a>

</x-panel>
