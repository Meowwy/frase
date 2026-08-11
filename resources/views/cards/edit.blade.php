<x-html-layout>
    <x-page-heading>{{$card->phrase}}</x-page-heading>

    <x-forms.form method="post" action="/cards/{{$card->id}}">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.input value="{{$card->phrase}}" label="Term" name="phrase"/>
        {{-- The type is AI-assigned at capture; let the user correct a misclassification. --}}
        <x-forms.select label="Term type" name="term_type">
            @foreach(\App\Models\Card::TERM_TYPES as $termType)
                <x-forms.option value="{{$termType}}" :selected="$card->term_type === $termType">{{ucfirst($termType)}}</x-forms.option>
            @endforeach
        </x-forms.select>
        <x-forms.input value="{{$card->definition}}" label="Definition" name="definition"/>
        <x-forms.input value="{{$card->translation}}" label="Translation" name="translation"/>
        <x-forms.textarea label="Example sentence" name="example_sentence">{{$card->example_sentence}}</x-forms.textarea>
        {{-- Example phrases are lexical-only; hidden (not removed, so they still submit)
             when the card is an expression, which is illustrated by its sentence alone. --}}
        <div id="examplePhrases" @class(['hidden' => $card->term_type === \App\Models\Card::TYPE_EXPRESSION])>
            <x-forms.input value="{{$card->example_1}}" label="Example phrase 1" name="example_1"/>
            <x-forms.input value="{{$card->example_2}}" label="Example phrase 2" name="example_2"/>
            <x-forms.input value="{{$card->example_3}}" label="Example phrase 3" name="example_3"/>
        </div>
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

    <script>
        // Follow the type picker: example phrases only apply to a lexical card.
        $(document).ready(function () {
            $('select[name="term_type"]').on('change', function () {
                $('#examplePhrases').toggleClass('hidden', $(this).val() === '{{ \App\Models\Card::TYPE_EXPRESSION }}');
            });
        });
    </script>
</x-html-layout>
