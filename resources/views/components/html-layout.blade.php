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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    {{-- Twemoji: Windows has no flag-emoji font, so render emoji as inline SVG images. --}}
    <script src="https://cdn.jsdelivr.net/npm/@twemoji/api@17.0.3/dist/twemoji.min.js" crossorigin="anonymous"></script>
    <style>
        img.emoji {
            height: 1em;
            width: 1em;
            margin: 0 .05em 0 .1em;
            vertical-align: -0.1em;
            display: inline;
        }
    </style>
    <script>
        (function () {
            if (typeof twemoji === 'undefined') { return; }

            const opts = { folder: 'svg', ext: '.svg', className: 'emoji' };
            const parse = (node) => { try { twemoji.parse(node, opts); } catch (e) {} };

            document.addEventListener('DOMContentLoaded', function () {
                parse(document.body);

                // Language pickers/combos are built by JS after load, so re-parse new subtrees.
                const observer = new MutationObserver(function (mutations) {
                    for (const m of mutations) {
                        m.addedNodes.forEach(function (n) {
                            if (n.nodeType === 1 && n.tagName !== 'IMG') { parse(n); }
                        });
                    }
                });
                observer.observe(document.body, { childList: true, subtree: true });
            });
        })();
    </script>
</head>
<body class="bg-black text-white font-lato pb-20">
<div class="px-10">
    <nav class="flex relative items-center py-4 border-b border-white/10">
        <div class="flex-1">
            <a href="/">
                <p>Frase</p>
            </a>
        </div>

        <div class="absolute left-1/2 transform -translate-x-1/2">
            @auth
            <form action="/search" method="get" id="searchForm">
                <x-forms.input-search name="searchTerm"
                               placeholder="Search for a term"></x-forms.input-search>
            </form>
            @endauth
        </div>

        <div class="flex flex-1 justify-end">
            @auth
                <div class="space-x-6 font-bold flex">
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
