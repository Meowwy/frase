<x-html-layout>
    <x-page-heading>Register</x-page-heading>

    <x-forms.form method="post" action="/register" enctype="multipart/form-data">
        <x-forms.input label="Username" name="username"/>
        <x-forms.input label="Email" name="email" type="email"/>
        <x-forms.select label="What language do you want to improve?" name="targetLanguage">
            <x-forms.option>English</x-forms.option>
            <x-forms.option>German</x-forms.option>
            <x-forms.option>Spanish</x-forms.option>
            <x-forms.option>Czech</x-forms.option>
            <x-forms.option>Finish</x-forms.option>
        </x-forms.select>
        <x-forms.input label="What is your native language? (or a language, that are you fluent enough)" name="nativeLanguage" placeholder="For example: Czech"/>
        <x-forms.input label="Password" name="password" type="password"/>
        <x-forms.input label="Password Confirmation" name="password_confirmation" type="password"/>

        <x-forms.divider></x-forms.divider>
        <x-forms.button>Create account</x-forms.button>
    </x-forms.form>
</x-html-layout>
