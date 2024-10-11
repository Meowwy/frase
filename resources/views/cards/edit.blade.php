<x-html-layout>
    <x-page-heading>{{$card->phrase}}</x-page-heading>

    <x-forms.form method="post" action="/cards/{{$card->id}}">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.input value="{{$card->phrase}}" label="Term" name="phrase"/>
        <x-forms.input value="{{$card->definition}}" label="Definition" name="definition"/>
        <x-forms.input value="{{$card->translation}}" label="Translation" name="translation"/>
        <x-forms.input value="{{$card->example_sentence}}" label="Example sentence" name="example_sentence"/>
        <x-forms.input value="{{$card->question}}" label="Question" name="question"/>

        <x-forms.select label="Theme" name="theme_id">
            <x-forms.option value="-1">No theme chosen</x-forms.option>
            @foreach($themes as $theme)
                @if($theme['id'] === $card->theme_id)
                    <x-forms.option selected value="{{$theme['id']}}">{{$theme['name']}}</x-forms.option>
                @else
                    <x-forms.option value="{{$theme['id']}}">{{$theme['name']}}</x-forms.option>
                @endif
            @endforeach
        </x-forms.select>
        <x-forms.divider></x-forms.divider>
        <div class="flex justify-between">
            <x-forms.button>Save</x-forms.button>
            <x-forms.form method="post" action="/cards/{{$card->id}}/delete">
                <input hidden value="{{$card->id}}" name="id"/>
                <x-forms.button-delete>Delete term</x-forms.button-delete>
            </x-forms.form>
        </div>
    </x-forms.form>
    <a href="/cards/{{$card->id}}">
        <x-forms.button-small>Back to card list</x-forms.button-small>
    </a>


</x-html-layout>
