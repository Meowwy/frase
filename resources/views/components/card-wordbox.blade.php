@props(['card', 'isAdded' => false])
<x-panel outline="orange" class="w-full">
    <div class="flex gap-3 justify-between items-center w-full">
        <div class="flex-grow min-w-0">
            <a href="/cards/{{$card->id}}" class="flex gap-2 text-lg font-bold hover:text-blue-400 transition-colors">
                <p class="truncate">{{$card->phrase}}</p>
                <p class="text-white/30">|</p>
                <p class="truncate text-white/70 font-normal">{{$card->translation}}</p>
            </a>
        </div>
        <div class="flex-shrink-0">
            <button
                id="card-{{$card->id}}"
                data-phrase="{{$card->phrase}}"
                data-translation="{{$card->translation}}"
                onclick="this.dispatchEvent(new CustomEvent('card-action', { bubbles: true, detail: { id: {{ $card->id }}, phrase: '{{ addslashes($card->phrase) }}', translation: '{{ addslashes($card->translation) }}' } }))"
                @disabled($isAdded)
                class="{{ $isAdded ? 'bg-gray-600 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-500' }} text-white text-xs font-bold rounded-full px-4 py-2 transition-colors uppercase">
                {{ $isAdded ? 'Added' : 'Add' }}
            </button>
        </div>
    </div>
</x-panel>
