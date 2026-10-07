<x-html-layout>
    <x-page-heading>{{$card->term}}</x-page-heading>

    <x-forms.form method="post" action="/cards/{{$card->id}}">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.input value="{{$card->term}}" label="Term" name="term"/>
        <x-forms.input value="{{$card->definition}}" label="Definition" name="definition"/>
        <x-forms.input value="{{$card->translation}}" label="Translation" name="translation"/>
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
