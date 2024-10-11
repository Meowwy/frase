<x-html-layout>
    <p>Search results for "{{$searchTerm}}"</p>
    <div class="mt-4 space-y-2">
        @foreach($cards as $card)
            <x-card :card="$card"></x-card>
        @endforeach

    </div>
</x-html-layout>
