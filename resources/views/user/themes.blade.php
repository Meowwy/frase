@props(['themes'])
<x-html-layout>
    <section>
        <div class="container mx-auto p-6">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-3xl font-bold">Manage Themes</h2>
                    <p>Define themes that will be automatically assigned to cards.</p>
                </div>

                <button class="bg-blue-500 text-white rounded-full px-4 py-2 hover:bg-blue-600" id="addThemeBtn">Add New
                    Theme
                </button>
            </div>

            <!-- Themes List -->
            <ul id="themeList" class="space-y-4">

            </ul>
            <x-forms.form id="themesForm" method="POST" action="/saveThemes">
                <input id="themesInput" type="hidden" name="themes">
            </x-forms.form>
            <div class="mt-3 space-x-2">
                <x-forms.button id="saveThemesBtn">Save themes</x-forms.button>
                <a href="/profile">
                    <x-forms.button-small>Back to profile</x-forms.button-small>
                </a>
            </div>


            {{--<x-forms.form id="generateThemesForm" method="POST" action="/generateThemes">
                <x-forms.button id="saveThemesBtn">Generate themes with AI</x-forms.button>
            </x-forms.form>--}}

        </div>
        <p class="bg-yellow-100 text-yellow-800 border border-yellow-300 p-4 rounded-md">
            Keep in mind that changing themes won't affect existing cards.
        </p>

    </section>

    <script>
        let themes = [];

        function createArray() {
            themes = @json($themes);
        }

        function refreshThemes() {
            const themeList = document.getElementById('themeList');
            themeList.innerHTML = ''; // Clear existing items
            themes.forEach(theme => {
                // Create list item
                const li = document.createElement('li');
                li.className = 'flex flex-col bg-white/10 border border-white/10 p-2 rounded-lg';

                // Create div for text and button
                const div = document.createElement('div');
                div.className = 'flex justify-between items-center';

                // Create a text input element for the theme name
                const input = document.createElement('input');
                input.type = 'text';
                input.value = theme.name;
                input.className = 'text-black border-2 rounded p-1';  // Style the input as needed

                // Update the theme name in the array when the input value changes
                input.oninput = function () {
                    theme.name = input.value;
                };

                // Create delete button
                const button = document.createElement('button');
                button.className = 'bg-red-500 text-white rounded-full px-4 py-2 hover:bg-red-600 ml-4';
                button.textContent = 'Delete';
                button.onclick = function () {
                    deleteTheme(theme.id);
                };

                // Append input and button to div
                div.appendChild(input);
                div.appendChild(button);

                // Append div to list item
                li.appendChild(div);

                // Append list item to the list
                themeList.appendChild(li);
            });


        }

        document.getElementById('addThemeBtn').addEventListener('click', function () {
            // Add a new theme object with a temporary id and empty name
            themes.push({
                id: null,
                name: ''
            });

            // Refresh the list to include the new theme
            refreshThemes();

            console.log('theme added');
        });

        document.getElementById('saveThemesBtn').addEventListener('click', function () {
            saveThemes();
        });

        function deleteTheme(id) {
            // Find the index of the theme with the given id
            const index = themes.findIndex(theme => theme.id === id);

            // If the theme is found, remove it from the array
            if (index !== -1) {
                themes.splice(index, 1);
            }

            // Refresh the list to reflect changes
            refreshThemes();
        }


        function saveThemes() {
            console.log('saveTheme started');
            /*let themesToSave = [];
            const themeInputs = document.querySelectorAll('#themeList input[type="text"]');
            themeInputs.forEach(function (input) {
                themesToSave.push( input.value);
            });*/
            document.getElementById('themesInput').value = JSON.stringify(themes);
            document.getElementById('themesForm').submit();
        }

        createArray();
        refreshThemes();

    </script>
</x-html-layout>
