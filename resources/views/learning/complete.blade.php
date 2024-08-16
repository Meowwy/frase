<x-html-layout>
    <div>
        <p>Set complete!</p>
    </div>
    <div>
        @if(session('more_cards_available'))
            <a href="/startLearning/{{session('learning_mode')}}">Continue learning another set of cards</a>
            <a href="/setLearning">Switch learning mode</a>
        @endif
        <a href="/">Back to home</a>
    </div>

</x-html-layout>
