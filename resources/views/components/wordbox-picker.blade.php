@props([
    'targetLanguages',
    'wordboxesByLanguage',
    'activeLanguageId',
    'heading' => null,
])

{{-- Shared language switcher + wordbox picker. Self-contained: it manages its own
     selection/overflow state and exposes the current selection two ways:
       - window.WordboxPicker.current() → { languageId, wordbox, label }
       - a 'wordboxpicker:change' DOM event (same detail) on every change.
     Pages decide what to do with the selection (build links, fetch a list, …). --}}

@if($targetLanguages->count() > 1)
    <div id="langSwitcher" class="flex gap-2 mb-2">
        @foreach($targetLanguages as $lang)
            <button type="button"
                    class="lang-tab flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-lg border border-white/10 hover:bg-white/10 transition-colors {{ $lang->id == $activeLanguageId ? 'bg-blue-600/30 ring-1 ring-blue-500' : 'bg-white/5' }}"
                    data-language-id="{{ $lang->id }}">
                <span>{{ $lang->flag }} {{ $lang->name }}</span>
            </button>
        @endforeach
    </div>
@endif

@if($heading)
    <h3 class="font-bold text-lg mt-6 mb-2">{{ $heading }}</h3>
@endif

<div id="wordboxPicker">
    @foreach($targetLanguages as $lang)
        @php $boxes = $wordboxesByLanguage[$lang->id] ?? collect(); @endphp
        <div class="lang-group {{ $lang->id == $activeLanguageId ? '' : 'hidden' }}"
             data-language-id="{{ $lang->id }}" data-language-name="{{ $lang->name }}">
            <div class="tag-row flex items-center gap-2">
                <div class="tag-inline flex flex-nowrap items-center gap-2 overflow-hidden grow">
                    <button type="button"
                            class="save-node tag pinned flex items-center gap-1.5 text-xs px-3 py-1 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap bg-white/5"
                            data-wordbox="general">
                        <span>general vocabulary</span>
                        <span class="cross hidden ml-1 text-white/60 hover:text-white">✕</span>
                    </button>
                    @if($boxes->count())
                        <span class="pinned shrink-0 w-px h-5 bg-white/30"></span>
                        @foreach($boxes as $box)
                            <button type="button"
                                    class="save-node tag flex items-center gap-1.5 text-xs px-3 py-1 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap bg-white/5"
                                    data-order="{{ $loop->index }}" data-wordbox="{{ $box->id }}">
                                <span>{{ $box->name }}</span>
                                <span class="cross hidden ml-1 text-white/60 hover:text-white">✕</span>
                            </button>
                        @endforeach
                    @endif
                </div>
                @if($boxes->count())
                    <div class="more-wrap relative shrink-0 hidden">
                        <button type="button" class="more-btn flex items-center gap-1 text-xs px-3 py-1 bg-white/5 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap">More <span class="text-[10px]">▾</span></button>
                        <div class="more-list hidden absolute right-0 mt-1 z-30 min-w-[12rem] max-h-64 overflow-auto p-2 rounded-xl bg-neutral-900 border border-white/10 shadow-xl flex flex-col gap-1"></div>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

<script>
    // Shared wordbox picker: selection state, overflow layout, and change notifications.
    $(document).ready(function () {
        const SELECTED = 'bg-blue-600/30 ring-1 ring-blue-500';

        const state = {
            languageId: '{{ $activeLanguageId }}',
            wordbox: 'all', // 'all' | 'general' | <wordbox id>
        };

        function $activeGroup() {
            return $('.lang-group[data-language-id="' + state.languageId + '"]');
        }

        // Human-readable label for the current selection: the wordbox name,
        // 'general vocabulary', or null when nothing specific is selected ('all').
        function currentLabel() {
            if (state.wordbox === 'all') { return null; }
            if (state.wordbox === 'general') { return 'general vocabulary'; }
            const $node = $activeGroup().find('.save-node[data-wordbox="' + state.wordbox + '"]');
            return $node.length ? $node.find('span').first().text().trim() : null;
        }

        function current() {
            return { languageId: state.languageId, wordbox: state.wordbox, label: currentLabel() };
        }

        // Let pages read the selection on init without depending on event timing.
        window.WordboxPicker = { current: current };

        function emit() {
            document.dispatchEvent(new CustomEvent('wordboxpicker:change', { detail: current() }));
        }

        // Reflect the wordbox selection within the active language group.
        function refreshWordboxSelection() {
            const $group = $activeGroup();
            $group.find('.save-node').each(function () {
                const sel = String($(this).data('wordbox')) === String(state.wordbox);
                $(this).toggleClass(SELECTED, sel).toggleClass('bg-white/5', !sel);
                $(this).find('.cross').toggleClass('hidden', !sel);
            });
            $group.find('.more-wrap').each(function () {
                const has = $(this).find('.save-node.ring-blue-500').length > 0;
                $(this).find('.more-btn').toggleClass('ring-1 ring-blue-500', has);
            });
        }

        // Keep wordbox tags on one line; overflow goes into the "More" dropdown.
        // "general vocabulary" and the pipe divider are pinned and never overflow;
        // the selected wordbox tag is always kept visible.
        function layoutTagRow(row) {
            const inline = row.querySelector('.tag-inline');
            const moreWrap = row.querySelector('.more-wrap');
            if (!inline || !moreWrap) { return; }
            const moreList = moreWrap.querySelector('.more-list');
            const gap = 8;

            const tags = Array.from(row.querySelectorAll('.tag:not(.pinned)'))
                .sort((a, b) => (+a.dataset.order) - (+b.dataset.order));
            tags.forEach(t => inline.appendChild(t));
            moreWrap.classList.add('hidden');
            if (!tags.length) { return; }

            const pinned = Array.from(inline.querySelectorAll('.pinned'));
            let pinnedW = 0;
            pinned.forEach(p => { pinnedW += p.offsetWidth + gap; });

            const avail = row.clientWidth;
            let total = pinnedW;
            tags.forEach(t => { total += t.offsetWidth + gap; });
            if (total <= avail) { return; }

            moreWrap.classList.remove('hidden');
            const limit = avail - (moreWrap.offsetWidth + gap);
            const visible = [], overflow = [];
            let used = pinnedW, full = false;
            tags.forEach(t => {
                const w = t.offsetWidth + gap;
                if (!full && used + w <= limit) { used += w; visible.push(t); }
                else { full = true; overflow.push(t); }
            });

            const selId = String(state.wordbox || '');
            if (selId !== 'all' && selId !== 'general') {
                const sel = overflow.find(t => String(t.dataset.wordbox) === selId);
                if (sel) {
                    overflow.splice(overflow.indexOf(sel), 1);
                    for (let n = 0; n < 2 && visible.length; n++) {
                        const demoted = visible.pop();
                        overflow.unshift(demoted);
                        used -= demoted.offsetWidth + gap;
                        if (used + sel.offsetWidth + gap <= limit) { break; }
                    }
                    visible.push(sel);
                }
            }

            visible.forEach(t => inline.appendChild(t));
            overflow.forEach(t => moreList.appendChild(t));
        }

        function layoutActiveGroup() {
            const group = $activeGroup().get(0);
            if (!group) { return; }
            group.querySelectorAll('.tag-row').forEach(layoutTagRow);
        }

        // Language switch (JS only, no DB call): show that language's wordboxes.
        $('#langSwitcher').on('click', '.lang-tab', function () {
            state.languageId = String($(this).data('language-id'));
            state.wordbox = 'all';
            $('#langSwitcher .lang-tab').each(function () {
                const sel = String($(this).data('language-id')) === state.languageId;
                $(this).toggleClass(SELECTED, sel).toggleClass('bg-white/5', !sel);
            });
            $('.lang-group').addClass('hidden');
            $activeGroup().removeClass('hidden');
            layoutActiveGroup();
            refreshWordboxSelection();
            emit();
        });

        // Select a wordbox / general vocabulary.
        $('#wordboxPicker').on('click', '.save-node', function () {
            state.wordbox = String($(this).data('wordbox'));
            refreshWordboxSelection();
            layoutActiveGroup();
            emit();
        });

        // Unselect → back to "all terms".
        $('#wordboxPicker').on('click', '.cross', function (e) {
            e.stopPropagation();
            state.wordbox = 'all';
            refreshWordboxSelection();
            layoutActiveGroup();
            emit();
        });

        // Overlay "More" dropdown for overflowing wordbox tags.
        $('#wordboxPicker').on('click', '.more-btn', function (e) {
            e.stopPropagation();
            const $list = $(this).siblings('.more-list');
            const isOpen = !$list.hasClass('hidden');
            $('#wordboxPicker .more-list').addClass('hidden');
            if (!isOpen) { $list.removeClass('hidden'); }
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.more-wrap').length) {
                $('#wordboxPicker .more-list').addClass('hidden');
            }
        });

        layoutActiveGroup();
        refreshWordboxSelection();

        let resizeTimer;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(layoutActiveGroup, 150);
        });
    });
</script>
