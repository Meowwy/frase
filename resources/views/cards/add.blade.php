<x-html-layout>
    <x-page-heading>Add new term</x-page-heading>

    <x-forms.form method="post" action="/cards/new">
        <x-forms.input label="Term" name="term"/>

        {{-- Nothing analysed this Term, so the shape can't be derived — the form asks.
             It decides the derived term type and whether an anchor phrase is allowed. --}}
        <x-forms.select label="Card shape" name="card_shape" id="cardShape">
            @foreach(\App\Models\Card::SHAPES as $shape)
                <x-forms.option value="{{$shape}}">{{ucfirst($shape)}}</x-forms.option>
            @endforeach
        </x-forms.select>

        <x-forms.input label="Definition" name="definition"/>
        <x-forms.input label="Translation" name="translation"/>
        <x-forms.input label="Example sentence" name="example_sentence"/>

        {{-- Word shape only. Bracket the Term's own occurrence: "[collateral] damage". --}}
        <div id="anchorFields">
            <x-forms.input label="Anchor phrase" name="anchor"/>
            <x-forms.input label="Anchor translation" name="anchor_translation"/>
        </div>

        <x-forms.select label="Theme" name="theme_id">
            <x-forms.option value="-1">No theme chosen</x-forms.option>
            @foreach($themes as $theme)
                <x-forms.option value="{{$theme->id}}">{{$theme->name}}</x-forms.option>
            @endforeach
        </x-forms.select>
        <x-forms.divider></x-forms.divider>
        <x-forms.button>Save term</x-forms.button>
    </x-forms.form>

    <script>
        // Only a word card may carry an anchor phrase.
        $(function () {
            const $shape = $('#cardShape');
            const toggle = () => $('#anchorFields').toggleClass('hidden', $shape.val() !== '{{ \App\Models\Card::SHAPE_WORD }}');

            $shape.on('change', toggle);
            toggle();
        });
    </script>
</x-html-layout>
