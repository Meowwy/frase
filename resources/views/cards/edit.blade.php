<x-html-layout>
    <x-page-heading>{{$card->term}}</x-page-heading>

    <x-forms.form method="post" action="/cards/{{$card->id}}">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.input value="{{$card->term}}" label="Term" name="term"/>
        {{-- Term type is derived from the card's shape; correcting it here writes a shape
             back — see CardController::update(). --}}
        <x-forms.select label="Term type" name="term_type">
            @foreach(\App\Models\Card::TERM_TYPES as $termType)
                <x-forms.option value="{{$termType}}" :selected="$card->termType() === $termType">{{ucfirst($termType)}}</x-forms.option>
            @endforeach
        </x-forms.select>
        <x-forms.input value="{{$card->definition}}" label="Definition" name="definition"/>
        <x-forms.input value="{{$card->translation}}" label="Translation" name="translation"/>
        {{-- A textarea, not a single-line input: an expression's sentence can be long and
             may carry a line break the AI meant to keep. --}}
        <x-forms.textarea label="Example sentence" name="example_sentence">{{$card->example_sentence}}</x-forms.textarea>

        {{-- The anchor phrase is word-shape only, so it is rendered only for one. --}}
        @if($card->card_shape === \App\Models\Card::SHAPE_WORD)
            <x-forms.input value="{{$card->anchor}}" label="Anchor phrase" name="anchor"/>
            <x-forms.input value="{{$card->anchor_translation}}" label="Anchor translation" name="anchor_translation"/>
        @endif

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
