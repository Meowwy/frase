<x-panel class="flex justify-between">
    <div class="flex flex-col h-full">
        <div class="group-hover:text-blue-600 text-xl text-bold transition-colors duration-100 self-start text-sm">
            <a href="/" target="_blank">
                Theme name
            </a>
        </div>
        <div class="flex justify-between mt-auto">
            <p class="mt-1">34 cards</p>
            <p class="mt-1">4 due</p>
        </div>
    </div>
    <div class="flex flex-col justify-end">
        <x-forms.form action="/listCards" class="">
            <x-forms.button-small>Show cards</x-forms.button-small>
        </x-forms.form>
        <x-forms.form action="/setLearning" class="">
            <x-forms.button-confirm>Learn due</x-forms.button-confirm>
        </x-forms.form>
    </div>

    {{--<div class="flex justify-between items-center mt-auto">
        <div>
            <x-tag size="small"/>
        </div>
    </div>--}}
</x-panel>
