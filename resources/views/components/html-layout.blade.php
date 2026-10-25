{{-- `tabBar` false: a full-screen task (a flashcard session, a chat) that brings its own
     bottom bar on mobile, so the mobile tab bar steps aside, as a native app hides its tabs
     on a pushed screen. The top bar and its menu stay, so there is always a way out. --}}
@props(['maxWidth' => 'max-w-[1140px]', 'tabBar' => true])
@php
    $showTabBar = $tabBar && auth()->check();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    {{-- Pinch-zoom stays allowed (accessibility). viewport-fit=cover is what makes the
         env(safe-area-inset-*) values the fixed mobile bars pad themselves with non-zero. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
{{-- Below lg the body clears the fixed tab bar and the home indicator under it. --}}
<body @class([
    'bg-black text-white font-lato lg:pb-20',
    'pb-[calc(5rem_+_env(safe-area-inset-bottom))]' => $showTabBar,
    'pb-[calc(1.5rem_+_env(safe-area-inset-bottom))]' => ! $showTabBar,
])>
@auth
    {{-- Grouped by module: CAPTURE, then ORGANIZE, then LEARN (see CONTEXT.md).
         Staging carries a persistent count badge, because a captured term now
         waits there instead of becoming a card on its own. Both navs render one
         (.js-staged-count); every script updates all of them. --}}
    @php $stagedCount = Auth::user()->proposals()->count(); @endphp
@endauth

{{-- Mobile chrome (below lg): a slim fixed top bar (logo, search, menu) and a fixed bottom
     tab bar for the five main destinations, in thumb reach. The rest of the desktop nav
     (Frammenti, Conversation, the account) sits in the top bar's menu. --}}
<div x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false" class="lg:hidden">
    <header class="fixed inset-x-0 top-0 z-50 border-b border-white/10 bg-black pt-[env(safe-area-inset-top)]">
        <div class="flex h-14 items-center gap-3 px-[max(1rem,env(safe-area-inset-left))]">
            <a href="/" class="flex min-h-11 shrink-0 items-center font-bold">Frase</a>

            @auth
                <form action="/search" method="get" class="min-w-0 flex-1">
                    <label class="flex h-10 items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 focus-within:border-white/30">
                        <svg class="h-4 w-4 shrink-0 text-white/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="search" name="searchTerm" placeholder="Search for a term" autocomplete="off" enterkeyhint="search"
                               class="w-full min-w-0 bg-transparent text-base placeholder-white/40 focus:outline-none">
                    </label>
                </form>

                <button type="button" @click="menuOpen = ! menuOpen" :aria-expanded="menuOpen" aria-label="Menu"
                        class="-mr-2 flex h-11 w-11 shrink-0 items-center justify-center text-white/80">
                    <svg x-show="! menuOpen" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"></path>
                    </svg>
                    <svg x-show="menuOpen" x-cloak class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            @endauth

            @guest
                <div class="ml-auto flex items-center font-bold">
                    <a href="/register" class="flex min-h-11 items-center px-3">Sign Up</a>
                    <a href="/login" class="-mr-3 flex min-h-11 items-center px-3">Log In</a>
                </div>
            @endguest
        </div>
    </header>

    @auth
        @if($showTabBar)
            @php
                $tabs = [
                    ['href' => '/', 'label' => 'Home', 'active' => request()->is('/'),
                     'icon' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
                    ['href' => '/staging', 'label' => 'Staging', 'active' => request()->is('staging*'), 'badge' => true,
                     'icon' => 'M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z'],
                    ['href' => '/cards', 'label' => 'Cards', 'active' => request()->is('cards*', 'add'),
                     'icon' => 'M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3A2.25 2.25 0 0119.5 9v.878m0 0a2.246 2.246 0 00-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0121 12v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6c0-.98.626-1.813 1.5-2.122'],
                    ['href' => '/base', 'label' => 'Base', 'active' => request()->is('base*'),
                     'icon' => 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25'],
                    ['href' => '/filterCardsForLearning/due', 'label' => 'Learn', 'active' => request()->is('filterCardsForLearning*', 'setLearning*', 'startLearning*', 'completeLearning*'),
                     'icon' => 'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5'],
                ];
            @endphp
            <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-black pb-[env(safe-area-inset-bottom)]">
                <div class="grid grid-cols-5 px-[env(safe-area-inset-left)]">
                    @foreach($tabs as $tab)
                        <a href="{{ $tab['href'] }}" @if($tab['active']) aria-current="page" @endif
                           @class([
                               'flex h-16 flex-col items-center justify-center gap-1 transition-colors',
                               'text-blue-400' => $tab['active'],
                               'text-white/60 active:text-white' => ! $tab['active'],
                           ])>
                            <span class="relative">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}"></path>
                                </svg>
                                @if($tab['badge'] ?? false)
                                    <span @class(['js-staged-count absolute -right-3 -top-1.5 min-w-[1.25rem] rounded-full bg-orange-700 px-1 text-center text-[10px] font-bold leading-5 text-white', 'hidden' => $stagedCount === 0])>{{ $stagedCount }}</span>
                                @endif
                            </span>
                            <span class="text-[11px] font-bold leading-none">{{ $tab['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>
        @endif

        <div x-show="menuOpen" x-cloak x-transition.opacity @click="menuOpen = false"
             class="fixed inset-0 z-40 bg-black/70"></div>
        <nav x-show="menuOpen" x-cloak x-transition.origin.top
             class="fixed inset-x-0 top-[calc(3.5rem_+_env(safe-area-inset-top))] z-50 border-b border-white/10 bg-[#111] px-[max(1rem,env(safe-area-inset-left))] py-2 font-bold shadow-2xl">
            <a href="/frammenti" @class(['flex min-h-12 items-center justify-between', 'text-blue-400' => request()->is('frammenti*')])>
                Frammenti <span class="text-sm font-normal text-white/40">Daily active-vocabulary drill</span>
            </a>
            <a href="/conversation" @class(['flex min-h-12 items-center', 'text-blue-400' => request()->is('conversation*')])>Conversation</a>
            <div class="my-2 h-px bg-white/10"></div>
            <a href="/profile" class="flex min-h-12 items-center">{{ Auth::user()->username }}'s Account Settings</a>
            <form method="post" action="/logout">
                @csrf
                @method('delete')
                <button class="flex min-h-12 w-full items-center text-left text-white/70">Log Out</button>
            </form>
        </nav>
    @endauth
</div>

{{-- Below lg the wrapper clears the fixed top bar; the side padding follows the notch in
     landscape (viewport-fit=cover). --}}
<div class="px-[max(1rem,env(safe-area-inset-left))] pt-[calc(3.5rem_+_env(safe-area-inset-top))] lg:px-10 lg:pt-0">
    {{-- Sticky, so it stays reachable on long pages. It bleeds over the wrapper's padding
         (-mx-10 px-10) so content scrolling underneath never shows at its edges. Desktop
         only: below lg the mobile chrome above replaces it. --}}
    <nav class="sticky top-0 z-50 -mx-10 px-10 bg-black flex items-center py-4 border-b border-white/10 gap-6 max-lg:hidden">
        <div class="flex-1">
            <a href="/">
                <p class="font-bold">Frase</p>
            </a>
        </div>

        @auth
            <div class="flex items-center gap-8 font-bold">
                <a href="/" class="hover:text-blue-400 transition-colors">Home</a>
                <a href="/staging" class="hover:text-blue-400 transition-colors">
                    Staging
                    <span @class(['js-staged-count ml-1 rounded-full bg-orange-700 px-2 text-xs', 'hidden' => $stagedCount === 0])>{{ $stagedCount }}</span>
                </a>
                <a href="/cards" class="hover:text-blue-400 transition-colors">Cards</a>
                <a href="/base" class="hover:text-blue-400 transition-colors">Base</a>
                <a href="/filterCardsForLearning/due" class="hover:text-blue-400 transition-colors">Learn</a>
                <a href="/frammenti" class="hover:text-blue-400 transition-colors" title="Daily active-vocabulary drill">Frammenti</a>
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

    <main class="mt-4 lg:mt-10 mx-auto {{ $maxWidth }}">
        {{$slot}}
    </main>
</div>

@auth
    @if(! Auth::user()->timezone)
        {{-- Frammenti's days follow the learner's: record the browser's timezone once. --}}
        <script>
            $.post('/profile/timezone', { _token: '{{ csrf_token() }}', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone });
        </script>
    @endif
@endauth

</body>
</html>
