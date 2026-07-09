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

            <label class="flex items-start gap-2 mt-3 max-w-md cursor-pointer">
                <input type="checkbox" name="native_save" id="nativeSave" value="1"
                       class="mt-0.5 h-4 w-4 shrink-0 accent-blue-500 disabled:opacity-40 disabled:cursor-not-allowed"
                       @checked($nativeSaveEnabled)>
                <span class="text-sm text-white/70">Also save words in my native language — build vocabulary in your own language, learned in context.</span>
            </label>
        </div>

        <div class="mt-2">
            <div class="inline-flex items-center gap-x-2">
                <span class="w-2 h-2 bg-white inline-block"></span>
                <span class="font-bold">Languages you are learning</span>
            </div>
            <p class="text-sm text-white/60 mb-3">Pick up to 5 languages and set your proficiency for each. Each word you save belongs to one of these.</p>

            <table class="table-fixed w-full max-w-3xl text-left text-white bg-white/5 rounded-xl">
                <thead class="text-white/60 text-sm">
                    <tr class="border-b border-gray-700">
                        <th class="w-48 px-4 py-2 font-medium">Language</th>
                        <th class="w-56 px-4 py-2 font-medium">Proficiency</th>
                        <th class="w-24 px-4 py-2 font-medium">Terms</th>
                        <th class="w-10 px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody id="targetLangs" class="divide-y divide-gray-700"></tbody>
            </table>

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

    {{-- Confirm dialog for Hide / Remove, reusing the shared modal (replaces the browser confirm). --}}
    <x-modal name="confirm-language-action" title="Are you sure?">
        <p class="text-white/60 mb-6"><span id="langActionMsg"></span></p>
        <div class="flex justify-end gap-3">
            <button type="button" onclick="closeModal('confirm-language-action')"
                    class="px-5 py-2 rounded-xl font-bold bg-white/10 hover:bg-white/20 border border-white/10 transition-all">
                Cancel
            </button>
            <button type="button" id="langActionConfirm"
                    class="px-5 py-2 rounded-xl font-bold bg-red-600 hover:bg-red-500 text-white transition-all">
                Confirm
            </button>
        </div>
    </x-modal>

    <script>
        (function () {
            const LANGS = @json($languages->map(fn ($l) => ['id' => $l->id, 'flag' => $l->flag, 'name' => $l->name])->values());
            const COUNTS = @json($termCounts);
            const PRESELECTED = @json($selectedTargetIds);
            const HIDDEN_IDS = @json($hiddenLanguageIds);
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
            const nativeSave = document.getElementById('nativeSave');

            // Confirm modal (shared component) — a pending callback is run when confirmed.
            const langActionMsg = document.getElementById('langActionMsg');
            const langActionConfirm = document.getElementById('langActionConfirm');
            let pendingLangAction = null;

            function askConfirm(message, onConfirm) {
                langActionMsg.textContent = message;
                pendingLangAction = onConfirm;
                closeMenus();
                openModal('confirm-language-action');
            }

            langActionConfirm.addEventListener('click', () => {
                const action = pendingLangAction;
                pendingLangAction = null;
                closeModal('confirm-language-action');
                if (action) { action(); }
            });

            let nativeId = nativeInput.value || null;

            // The "save in native language" toggle only makes sense once a native language
            // is chosen; disable (and clear) it otherwise.
            function syncNativeSave() {
                const has = ! ! nativeId;
                nativeSave.disabled = ! has;
                if (! has) { nativeSave.checked = false; }
            }
            // Each row is a chosen language; `hidden` keeps it listed (greyed, unhideable) but out of the saved set.
            // Active targets come first, then languages the user has cards in but no longer learns (hidden).
            let rows = PRESELECTED.map(id => ({ id: id, level: PRELEVELS[id] || DEFAULT_LEVEL, hidden: false }));
            HIDDEN_IDS.forEach(id => rows.push({ id: id, level: DEFAULT_LEVEL, hidden: true }));

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
                wrap.querySelectorAll('.row-menu').forEach(m => m.classList.add('hidden'));
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
                        syncNativeSave();
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

                if (rows.length === 0) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td colspan="4" class="px-4 py-4 text-sm text-white/40">No languages yet — add one below.</td>`;
                    wrap.appendChild(tr);
                    updateControls();
                    return;
                }

                rows.forEach((row, idx) => {
                    const lang = langById(row.id);
                    const count = COUNTS[row.id] || 0;
                    const level = row.level || DEFAULT_LEVEL;

                    // The 3-dot menu shows the single action that fits this row's state.
                    let menuItem;
                    if (row.hidden) {
                        menuItem = `<button type="button" class="menu-unhide block w-full px-4 py-2 text-sm text-left hover:bg-white/10">Unhide</button>`;
                    } else if (count > 0) {
                        menuItem = `<button type="button" class="menu-hide block w-full px-4 py-2 text-sm text-left hover:bg-white/10">Hide</button>`;
                    } else {
                        menuItem = `<button type="button" class="menu-remove block w-full px-4 py-2 text-sm text-left text-red-500 hover:bg-white/10">Remove</button>`;
                    }

                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-white/10' + (row.hidden ? ' opacity-50' : '');
                    tr.innerHTML = `
                        <td class="px-4 py-2 truncate">
                            ${lang ? esc(lang.flag + ' ' + lang.name) : ''}
                            ${row.hidden ? `<span class="text-xs text-white/30 italic ml-1">(hidden)</span>` : ''}
                        </td>
                        <td class="px-4 py-2">
                            <div class="combo relative w-full">
                                <button type="button" class="level-trigger combo-trigger w-full flex items-center justify-between rounded-lg bg-white/10 border border-white/10 px-3 py-1.5 text-sm hover:border-blue-500 transition-colors" ${row.hidden ? 'disabled' : ''}>
                                    <span class="truncate">${esc(levelLabel(level))}</span>
                                    <span class="text-white/50 text-xs shrink-0 ml-1">▾</span>
                                </button>
                                <div class="combo-menu hidden absolute z-30 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-xl bg-neutral-900 border border-white/10 shadow-xl py-1"></div>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-sm text-white/50 whitespace-nowrap">${count > 0 ? count + ' term' + (count > 1 ? 's' : '') : '—'}</td>
                        <td class="px-4 py-2 text-right relative">
                            <button type="button" class="row-menu-btn text-gray-400 hover:text-white" aria-label="Row options">
                                <svg class="w-5 h-5 inline" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 6a2 2 0 110-4 2 2 0 010 4zm0 8a2 2 0 110-4 2 2 0 010 4zm0 8a2 2 0 110-4 2 2 0 010 4z"></path>
                                </svg>
                            </button>
                            <div class="row-menu hidden absolute right-4 top-10 z-40 w-32 rounded-lg border border-white/10 bg-[#111] py-1 text-left shadow-xl">
                                ${menuItem}
                            </div>
                            ${! row.hidden ? `<input type="hidden" name="target_language_ids[]" value="${row.id}">
                            <input type="hidden" name="target_language_levels[${row.id}]" value="${level}">` : ''}
                        </td>
                    `;

                    // Proficiency combo (same look as the language pickers).
                    const menu = tr.querySelector('.combo-menu');
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

                    const levelTrigger = tr.querySelector('.level-trigger');
                    levelTrigger.addEventListener('click', e => {
                        e.stopPropagation();
                        if (row.hidden) { return; }
                        const isOpen = ! menu.classList.contains('hidden');
                        closeMenus();
                        if (! isOpen) { menu.classList.remove('hidden'); }
                    });

                    // 3-dot row menu toggle.
                    const rowMenu = tr.querySelector('.row-menu');
                    tr.querySelector('.row-menu-btn').addEventListener('click', e => {
                        e.stopPropagation();
                        const isOpen = ! rowMenu.classList.contains('hidden');
                        closeMenus();
                        if (! isOpen) { rowMenu.classList.remove('hidden'); }
                    });

                    const hideBtn = tr.querySelector('.menu-hide');
                    if (hideBtn) {
                        hideBtn.addEventListener('click', () => {
                            askConfirm(
                                'Hide ' + (lang ? lang.name : 'this language') + '? Your ' + count + ' saved card'
                                + (count > 1 ? 's' : '') + ' will be kept — you can unhide it any time to resume learning it.',
                                () => { row.hidden = true; render(); }
                            );
                        });
                    }

                    const unhideBtn = tr.querySelector('.menu-unhide');
                    if (unhideBtn) {
                        unhideBtn.addEventListener('click', () => {
                            closeMenus();
                            if (activeCount() >= MAX) { hint.classList.remove('hidden'); return; }
                            row.hidden = false;
                            render();
                        });
                    }

                    const removeBtn = tr.querySelector('.menu-remove');
                    if (removeBtn) {
                        removeBtn.addEventListener('click', () => {
                            askConfirm(
                                'Remove ' + (lang ? lang.name : 'this language') + ' from your languages?',
                                () => { rows.splice(idx, 1); render(); }
                            );
                        });
                    }

                    wrap.appendChild(tr);
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

            syncNativeSave();
            renderNative();
            render();
        })();
    </script>
</x-html-layout>
