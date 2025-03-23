@props(['card'])
<x-panel outline="orange">
    <div class="flex gap-3 justify-between items-center w-full">
        <div class="flex-col">
            <a href="/cards/{{$card->id}}" class="flex gap-2 text-xl">
                <p>{{$card->phrase}}</p>
                <p>|</p>
                <p>{{$card->translation}}</p>
            </a>
        </div>
        <div class="">
            <button
                id="card-{{$card->id}}"
                data-phrase="{{$card->phrase}}"
                data-translation="{{$card->translation}}"
                class="bg-blue-500 text-white rounded-full px-4 py-2 hover:bg-blue-600 ml-4">
                ADD
            </button>
        </div>
    </div>

</x-panel>
