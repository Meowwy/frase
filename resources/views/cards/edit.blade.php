<x-html-layout>
    <x-page-heading>{{$card->phrase}}</x-page-heading>

    <x-forms.form method="post" action="/cards/{{$card->id}}">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.input value="{{$card->phrase}}" label="Term" name="phrase"/>
        <x-forms.input value="{{$card->definition}}" label="Definition" name="definition"/>
        <x-forms.input value="{{$card->translation}}" label="Translation" name="translation"/>
        <x-forms.input value="{{$card->example_sentence}}" label="Example sentence" name="example_sentence"/>
        <x-forms.input value="{{$card->example_1}}" label="Example phrase 1" name="example_1"/>
        <x-forms.input value="{{$card->example_2}}" label="Example phrase 2" name="example_2"/>
        <x-forms.input value="{{$card->example_3}}" label="Example phrase 3" name="example_3"/>
        <x-forms.textarea label="Note" name="note">{{$card->note}}</x-forms.textarea>
        <x-forms.divider></x-forms.divider>
        <div class="flex justify-between">
            <x-forms.button>Save</x-forms.button>
        </div>
    </x-forms.form>

    <x-forms.form method="post" action="/cards/{{$card->id}}/delete">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.button-delete>Delete term</x-forms.button-delete>
    </x-forms.form>
    <a href="/cards/{{$card->id}}">
        <x-forms.button-small>Back to card list</x-forms.button-small>
    </a>


</x-html-layout>
