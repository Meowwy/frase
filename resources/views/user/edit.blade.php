<x-html-layout>
    <x-forms.form method="post" action="/profile/edit">
        <x-forms.input value="{{Auth::user()->username}}" label="Username" name="username"/>
        <x-forms.input value="{{Auth::user()->native_language}}" label="Native language" name="native_language"/>
        <x-forms.input value="{{Auth::user()->target_language}}" label="Target language" name="target_language"/>
        <x-forms.button>Save</x-forms.button>
    </x-forms.form>
</x-html-layout>
