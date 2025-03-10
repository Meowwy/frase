<x-html-layout>
    <x-page-heading>Register</x-page-heading>

    <p class="bg-yellow-100 text-yellow-800 border border-yellow-300 p-4 rounded-md">
        This app doesn't work for general public. Send e-mail to koleckar551@gmail.com to get access.
    </p>

    <x-forms.form method="post" action="/register" enctype="multipart/form-data">
        <x-forms.input label="Insert the secret code here" name="code"/>
        <x-forms.input label="Username" name="username"/>
        <x-forms.input label="Email" name="email" type="email"/>
        <x-forms.select label="What language do you want to improve?" name="targetLanguage">
            <x-forms.option>English</x-forms.option>
            <x-forms.option>German</x-forms.option>
            <x-forms.option>Spanish</x-forms.option>
            <x-forms.option>Czech</x-forms.option>
            <x-forms.option>Finish</x-forms.option>
        </x-forms.select>
        <x-forms.input label="What is your native language? (or a language, that you are fluent enough)" name="nativeLanguage" placeholder="For example: Czech"/>
        <x-forms.input label="Password" name="password" type="password"/>
        <x-forms.input label="Password Confirmation" name="password_confirmation" type="password"/>

        <x-forms.divider></x-forms.divider>
        <x-forms.button>Create account</x-forms.button>
    </x-forms.form>
</x-html-layout>
