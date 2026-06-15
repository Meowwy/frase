<x-html-layout>
    <x-forms.form method="post" action="/profile/edit">
        <x-forms.input value="{{ Auth::user()->username }}" label="Username" name="username"/>

        <div class="mb-6">
            <label class="block mb-1 text-white/70">Native language</label>
            <div id="nativeCombo" class="combo relative max-w-md">
                <button type="button" id="nativeTrigger"
                        class="combo-trigger w-full flex items-center justify-between rounded-xl bg-white/10 border border-white/10 px-4 py-2 hover:border-blue-500 transition-colors">
                    <span id="nativeLabel"><span class="text-white/40">Select your native language</span></span>
                    <span class="text-white/50 text-xs">▾</span>
                </button>
                <div id="nativeMenu" class="combo-menu hidden absolute z-30 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-xl bg-neutral-900 border border-white/10 shadow-xl py-1"></div>
            </div>
            <input type="hidden" name="native_language_id" id="nativeInput" value="{{ $nativeLanguageId }}">
        </div>

        <div class="mt-2">
            <div class="inline-flex items-center gap-x-2">
                <span class="w-2 h-2 bg-white inline-block"></span>
                <span class="font-bold">Languages you are learning</span>
            </div>
            <p class="text-sm text-white/60 mb-3">Pick up to 5 languages and set your proficiency for each. Each word you save belongs to one of these.</p>

            <div id="targetLangs" class="space-y-2"></div>

            <div id="addCombo" class="combo relative max-w-md mt-3">
                <button type="button" id="addTrigger"
                        class="combo-trigger w-full flex items-center justify-between rounded-xl bg-white/5 border border-white/10 px-4 py-2 hover:border-blue-500 transition-colors">
                    <span class="text-white/70">+ Add a language</span>
                    <span class="text-white/50 text-xs">▾</span>
                </button>
                <div id="addMenu" class="combo-menu hidden absolute z-30 left-0 right-0 mt-1 rounded-xl bg-neutral-900 border border-white/10 shadow-xl overflow-hidden">
                    <div class="p-2 border-b border-white/10">
                        <input type="text" id="addSearch" placeholder="Search languages…"
                               class="w-full rounded-lg bg-white/10 border border-white/10 px-3 py-1.5 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div id="addOptions" class="max-h-48 overflow-y-auto py-1"></div>
                </div>
            </div>
            <p id="langLimitHint" class="text-sm text-orange-400 mt-2 hidden">You can learn at most 5 languages at once.</p>
        </div>

        <x-forms.button class="mt-4">Save</x-forms.button>
    </x-forms.form>

    <div class="mt-4">
        <a href="/">
            <x-forms.button-small>&larr; Back to main page</x-forms.button-small>
        </a>
    </div>

    <script>
        (function () {
            const LANGS = @json($languages->map(fn ($l) => ['id' => $l->id, 'flag' => $l->flag, 'name' => $l->name])->values());
            const COUNTS = @json($termCounts);
            const PRESELECTED = @json($selectedTargetIds);
            const LEVELS = @json(array_keys($proficiencyLevels));
            const NAMES = @json((object) $proficiencyNames);
            const PRELEVELS = @json((object) $selectedLevels);
            const DEFAULT_LEVEL = @json($defaultLevel);
            const MAX = 5;

            const wrap = document.getElementById('targetLangs');
            const hint = document.getElementById('langLimitHint');

            const addTrigger = document.getElementById('addTrigger');
            const addMenu = document.getElementById('addMenu');
            const addSearch = document.getElementById('addSearch');
            const addOptions = document.getElementById('addOptions');

            const nativeTrigger = document.getElementById('nativeTrigger');
            const nativeMenu = document.getElementById('nativeMenu');
            const nativeLabel = document.getElementById('nativeLabel');
            const nativeInput = document.getElementById('nativeInput');

            let nativeId = nativeInput.value || null;
            // Each row is a chosen language; `hidden` keeps it listed (greyed, unhideable) but out of the saved set.
            let rows = PRESELECTED.map(id => ({ id: id, level: PRELEVELS[id] || DEFAULT_LEVEL, hidden: false }));

            const langById = id => LANGS.find(l => String(l.id) === String(id));
            const esc = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
            const levelLabel = lv => lv + (NAMES[lv] ? ' - ' + NAMES[lv] : '');
            const activeCount = () => rows.filter(r => ! r.hidden).length;

            // Languages still addable: not the native one, and not already listed (active or hidden).
            function availableLangs() {
                return LANGS.filter(l => String(l.id) !== String(nativeId) && ! rows.some(r => String(r.id) === String(l.id)));
            }

            function closeMenus() {
                wrap.querySelectorAll('.combo-menu').forEach(m => m.classList.add('hidden'));
                nativeMenu.classList.add('hidden');
                addMenu.classList.add('hidden');
            }

            // ---- Native-language combo ----
            function renderNative() {
                const lang = nativeId ? langById(nativeId) : null;
                nativeLabel.innerHTML = lang
                    ? esc(lang.flag + ' ' + lang.name)
                    : '<span class="text-white/40">Select your native language</span>';
                nativeMenu.innerHTML = '';
                LANGS.forEach(l => {
                    const o = document.createElement('button');
                    o.type = 'button';
                    o.className = 'combo-option w-full text-left text-sm px-3 py-2 hover:bg-blue-600/30 transition-colors';
                    o.textContent = l.flag + ' ' + l.name;
                    o.addEventListener('click', () => {
                        nativeId = l.id;
                        nativeInput.value = l.id;
                        rows = rows.filter(r => String(r.id) !== String(nativeId));
                        closeMenus();
                        renderNative();
                        render();
                    });
                    nativeMenu.appendChild(o);
                });
            }

            nativeTrigger.addEventListener('click', e => {
                e.stopPropagation();
                const isOpen = ! nativeMenu.classList.contains('hidden');
                closeMenus();
                if (! isOpen) { nativeMenu.classList.remove('hidden'); }
            });

            // ---- Add-language searchable combo ----
            function renderAddOptions() {
                const term = (addSearch.value || '').toLowerCase().trim();
                const list = availableLangs().filter(l => l.name.toLowerCase().includes(term));
                addOptions.innerHTML = '';
                if (list.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'px-3 py-2 text-sm text-white/40';
                    empty.textContent = 'No languages found';
                    addOptions.appendChild(empty);
                    return;
                }
                list.forEach(l => {
                    const o = document.createElement('button');
                    o.type = 'button';
                    o.className = 'combo-option w-full text-left text-sm px-3 py-2 hover:bg-blue-600/30 transition-colors';
                    o.textContent = l.flag + ' ' + l.name;
                    o.addEventListener('click', () => {
                        rows.push({ id: l.id, level: DEFAULT_LEVEL, hidden: false });
                        closeMenus();
                        render();
                    });
                    addOptions.appendChild(o);
                });
            }

            addTrigger.addEventListener('click', e => {
                e.stopPropagation();
                if (addTrigger.disabled) { return; }
                const isOpen = ! addMenu.classList.contains('hidden');
                closeMenus();
                if (! isOpen) {
                    addSearch.value = '';
                    renderAddOptions();
                    addMenu.classList.remove('hidden');
                    addSearch.focus();
                }
            });
            addMenu.addEventListener('click', e => e.stopPropagation());
            addSearch.addEventListener('input', renderAddOptions);

            // ---- Target-language rows ----
            function render() {
                wrap.innerHTML = '';
                rows.forEach((row, idx) => {
                    const lang = langById(row.id);
                    const count = COUNTS[row.id] || 0;
                    const level = row.level || DEFAULT_LEVEL;
                    const dim = row.hidden ? 'opacity-50' : '';

                    let btnHtml;
                    if (row.hidden) {
                        btnHtml = `<button type="button" class="unhide-row text-xs px-3 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap">Unhide</button>`;
                    } else if (count > 0) {
                        btnHtml = `<button type="button" class="hide-row text-xs px-3 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap">Hide</button>`;
                    } else {
                        btnHtml = `<button type="button" class="del-row text-xs px-3 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap">✕</button>`;
                    }

                    const el = document.createElement('div');
                    el.className = 'flex items-center gap-2';
                    el.innerHTML = `
                        <div class="grow flex items-center rounded-xl bg-white/10 border border-white/10 px-4 py-2 ${dim}">
                            <span>${lang ? esc(lang.flag + ' ' + lang.name) : ''}</span>
                        </div>
                        <div class="combo relative w-52 shrink-0 ${dim}">
                            <button type="button" class="level-trigger combo-trigger w-full flex items-center justify-between rounded-xl bg-white/10 border border-white/10 px-4 py-2 hover:border-blue-500 transition-colors" ${row.hidden ? 'disabled' : ''}>
                                <span>${esc(levelLabel(level))}</span>
                                <span class="text-white/50 text-xs">▾</span>
                            </button>
                            <div class="combo-menu hidden absolute z-30 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-xl bg-neutral-900 border border-white/10 shadow-xl py-1"></div>
                        </div>
                        ${count > 0 ? `<span class="text-xs text-white/50 whitespace-nowrap">${count} term${count > 1 ? 's' : ''}</span>` : ''}
                        ${row.hidden ? `<span class="text-xs text-white/30 italic whitespace-nowrap">hidden</span>` : ''}
                        ${btnHtml}
                        ${! row.hidden ? `<input type="hidden" name="target_language_ids[]" value="${row.id}">
                        <input type="hidden" name="target_language_levels[${row.id}]" value="${level}">` : ''}
                    `;

                    // Proficiency combo (same look as the language pickers).
                    const menu = el.querySelector('.combo-menu');
                    LEVELS.forEach(lv => {
                        const o = document.createElement('button');
                        o.type = 'button';
                        o.className = 'combo-option w-full text-left text-sm px-3 py-2 hover:bg-blue-600/30 transition-colors' + (lv === level ? ' text-blue-300' : '');
                        o.textContent = levelLabel(lv);
                        o.addEventListener('click', e => {
                            e.stopPropagation();
                            row.level = lv;
                            closeMenus();
                            render();
                        });
                        menu.appendChild(o);
                    });

                    const levelTrigger = el.querySelector('.level-trigger');
                    levelTrigger.addEventListener('click', e => {
                        e.stopPropagation();
                        if (row.hidden) { return; }
                        const isOpen = ! menu.classList.contains('hidden');
                        closeMenus();
                        if (! isOpen) { menu.classList.remove('hidden'); }
                    });

                    const hideBtn = el.querySelector('.hide-row');
                    if (hideBtn) {
                        hideBtn.addEventListener('click', () => {
                            const msg = 'Hide ' + (lang ? lang.name : 'this language') + '?\n\n'
                                + 'Your ' + count + ' saved card' + (count > 1 ? 's' : '')
                                + ' will be kept. You can unhide it any time to resume learning it.';
                            if (! confirm(msg)) { return; }
                            row.hidden = true;
                            render();
                        });
                    }

                    const unhideBtn = el.querySelector('.unhide-row');
                    if (unhideBtn) {
                        unhideBtn.addEventListener('click', () => {
                            if (activeCount() >= MAX) { hint.classList.remove('hidden'); return; }
                            row.hidden = false;
                            render();
                        });
                    }

                    const delBtn = el.querySelector('.del-row');
                    if (delBtn) {
                        delBtn.addEventListener('click', () => { rows.splice(idx, 1); render(); });
                    }

                    wrap.appendChild(el);
                });
                updateControls();
            }

            function updateControls() {
                const disabled = activeCount() >= MAX || availableLangs().length === 0;
                addTrigger.disabled = disabled;
                addTrigger.classList.toggle('opacity-50', disabled);
                addTrigger.classList.toggle('cursor-not-allowed', disabled);
                if (disabled) { addMenu.classList.add('hidden'); }
                hint.classList.toggle('hidden', activeCount() < MAX);
            }

            document.addEventListener('click', closeMenus);

            renderNative();
            render();
        })();
    </script>
</x-html-layout>
