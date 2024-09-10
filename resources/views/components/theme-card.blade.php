@props(['theme'])
<x-panel class="flex justify-between">
    <div class="flex flex-col h-full">
        <div class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100 self-start text-sm">
            <p>
                {{$theme->name}}
            </p>
        </div>
        <div class="flex justify-between mt-auto">
            <p class="mt-1">{{$theme->total_cards_count}} cards</p>
            <p class="mt-1">{{$theme->due_cards_count}} due</p>
        </div>
    </div>
    <div class="flex flex-col justify-center items-center space-y-2">
        <form method="POST" action="/cards/themeFilter">
            @csrf
            <select class="hidden" id="themeSelect" name="themeSelect">
                <option value="{{$theme->name}}">{{$theme->name}}</option>
            </select>
            <x-forms.button-small>Show cards</x-forms.button-small>
        </form>

        <form method="get" action="/filterCardsForLearning/{{$theme->name}}" class="">
            <x-forms.button-confirm>Learn due</x-forms.button-confirm>
        </form>
    </div>

    {{--<div class="flex justify-between items-center mt-auto">
        <div>
            <x-tag size="small"/>
        </div>
    </div>--}}
</x-panel>
