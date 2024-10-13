<x-html-layout>
    <div class="mb-4">
        <p class="text-center text-4xl">"{{$searchTerm}}"</p>
    </div>

    @if(count($cards) == 0)
        <p class="text-center">No results</p>
    @endif
    <div class="mt-4 space-y-2">
        @foreach($cards as $card)
            <x-card :card="$card"></x-card>
        @endforeach

    </div>
</x-html-layout>
