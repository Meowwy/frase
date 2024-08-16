<x-html-layout>
    <div class="space-y-2">
        @foreach($cards as $card)
            <x-card :$card></x-card>
        @endforeach


    </div>

</x-html-layout>
