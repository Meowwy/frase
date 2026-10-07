@props(['maxWidth' => 'max-w-[1140px]'])
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Frase</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    {{-- After jQuery: toastr binds to window.jQuery as it loads, and every call throws without it. --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        // Toastr 2.x defaults escapeHtml to FALSE, so a toast body is parsed as markup.
        // Most toasts in this app quote something the user typed or the AI generated — a
        // captured term, a card's Term, an error message — so the default is an XSS hole:
        // capturing `<img src=x onerror=…>` would execute it. Escaping globally is the safe
        // default; the two or three toasts that deliberately carry a link (staging's undo)
        // opt out per call with their own, entirely static, markup.
        if (window.toastr) { toastr.options.escapeHtml = true; }
    </script>
</head>
<body class="bg-black text-white font-lato pb-20">
<div class="px-10">
    {{-- Sticky, so it stays reachable on long pages. It bleeds over the wrapper's padding
         (-mx-10 px-10) so content scrolling underneath never shows at its edges. --}}
    <nav class="sticky top-0 z-50 -mx-10 px-10 bg-black flex items-center py-4 border-b border-white/10 gap-6">
        <div class="flex-1">
            <a href="/">
                <p class="font-bold">Frase</p>
            </a>
        </div>

        @auth
            {{-- Grouped by module: CAPTURE, then ORGANIZE, then LEARN (see CONTEXT.md).
                 Staging carries a persistent count badge, because a captured term now
                 waits there instead of becoming a card on its own. --}}
            @php $stagedCount = Auth::user()->proposals()->count(); @endphp
            <div class="flex items-center gap-8 font-bold">
                <a href="/" class="hover:text-blue-400 transition-colors">Home</a>
                <a href="/staging" class="hover:text-blue-400 transition-colors">
                    Staging
                    <span @class(['js-staged-count ml-1 rounded-full bg-orange-700 px-2 text-xs', 'hidden' => $stagedCount === 0])>{{ $stagedCount }}</span>
                </a>
                <a href="/cards" class="hover:text-blue-400 transition-colors">Cards</a>
                <a href="/base" class="hover:text-blue-400 transition-colors">Base</a>
                <a href="/filterCardsForLearning/due" class="hover:text-blue-400 transition-colors">Learn</a>
                <a href="/conversation" class="hover:text-blue-400 transition-colors">Conversation</a>
            </div>
        @endauth

        <div class="flex flex-1 justify-end items-center gap-6">
            @auth
                <form action="/search" method="get" id="searchForm">
                    <x-forms.input-search name="searchTerm"
                                   placeholder="Search for a term"></x-forms.input-search>
                </form>
                <div class="space-x-6 font-bold flex items-center">
                    <a href="/profile">{{Auth::user()->username}}'s Account Settings</a>
                    <form method="post" action="/logout">
                        @csrf
                        @method('delete')
                        <button>Log Out</button>
                    </form>
                </div>
            @endauth

            @guest()
                <div class="space-x-6 font-bold">
                    <a href="/register">Sign Up</a>
                    <a href="/login">Log In</a>
                </div>
            @endguest
        </div>

    </nav>

    <main class="mt-10 mx-auto {{ $maxWidth }}">
        {{$slot}}
    </main>
</div>

</body>
</html>
