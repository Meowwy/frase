<x-html-layout>
    {{--@guest
    <div class="flex justify-center">
        <img width="800px" src="{{Vite::asset('resources/images/logo_guestScreen.jpg')}}" alt="Improve your language skills with Frase!">
    </div>
    @endguest--}}

    <section>
        <x-section-heading>Capture</x-section-heading>
        <x-forms.form action="{{url('captureWordAjax')}}" method="post" id="addWord" class="mt-6">
            <x-forms.input :label="false" name="capturedWord" placeholder="Write a word or phrase in English"></x-forms.input>
            <x-forms.button>Save</x-forms.button>
        </x-forms.form>
    </section>

    <section>
        <x-section-heading>Due</x-section-heading>
            <p class="mt-4 text-center">16 cards to learn</p>
            <div>
                <x-forms.form action="/captureWord" class="text-center">
                    <x-forms.button-confirm>Learn all due cards</x-forms.button-confirm>
                </x-forms.form>
            </div>
    </section>

    <section>
        <x-section-heading>Themes</x-section-heading>
        <div class="grid lg:grid-cols-3 gap-8 mt-6">
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>

        </div>
    </section>

    <script type="text/javascript">
        $(document).ready(function (){
            $('#addpost').on('submit',function (event){
                event.preventDefault();
                jQuery.ajax({
                    url:"{{url('captureWordAjax')}}",
                    data:jQuery('#addWord').serialize(),
                    type:post,

                    success:function (result){
                        toastr.success("captured");
                        $('#addWord')[0].reset();
                    },
                    error: function (xhr) {
                        alert('An error occurred: ' + xhr.responseJSON.error);
                    }

                })
            })
        });
    </script>
</x-html-layout>
