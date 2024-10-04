<x-html-layout>
    <x-page-heading>Add new term</x-page-heading>

    <x-forms.form method="post" action="/cards/new">
        <x-forms.input label="Term" name="phrase"/>
        <x-forms.input label="Definition" name="definition"/>
        <x-forms.input label="Translation" name="translation"/>
        <x-forms.input label="Example sentence" name="example_sentence"/>
        <x-forms.input label="Question" name="question"/>

        <x-forms.select label="Theme" name="theme_id">
            <x-forms.option value="-1">No theme chosen</x-forms.option>
            @foreach($themes as $theme)
                    <x-forms.option value="{{$theme['id']}}">{{$theme['name']}}</x-forms.option>
            @endforeach
        </x-forms.select>
        <x-forms.divider></x-forms.divider>
        <x-forms.button>Save term</x-forms.button>
    </x-forms.form>
</x-html-layout>
