<x-html-layout>
    <x-page-heading>Register</x-page-heading>

    <x-forms.form method="post" action="/register" enctype="multipart/form-data">
        <x-forms.input label="Username" name="name"/>
        <x-forms.input label="Email" name="email" type="email"/>
        <x-forms.input label="Password" name="password" type="password"/>
        <x-forms.input label="Password Confirmation" name="password_confirmation" type="password"/>

        <x-forms.divider></x-forms.divider>
        <x-forms.button>Create account</x-forms.button>
    </x-forms.form>
</x-html-layout>
