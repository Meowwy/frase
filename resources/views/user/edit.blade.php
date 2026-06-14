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
            <p class="text-sm text-white/60 mb-3">Pick up to 5 languages. Each word you save belongs to one of these.</p>

            <div id="targetLangs" class="space-y-2"></div>

            <button type="button" id="addLangBtn"
                    class="mt-3 inline-flex items-center gap-1 text-sm px-4 py-2 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 transition-colors">
                + Add language
            </button>
            <p id="langLimitHint" class="text-sm text-orange-400 mt-2 hidden">You can select at most 5 languages.</p>
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
            const MAX = 5;

            const wrap = document.getElementById('targetLangs');
            const addBtn = document.getElementById('addLangBtn');
            const hint = document.getElementById('langLimitHint');

            const nativeTrigger = document.getElementById('nativeTrigger');
            const nativeMenu = document.getElementById('nativeMenu');
            const nativeLabel = document.getElementById('nativeLabel');
            const nativeInput = document.getElementById('nativeInput');

            let nativeId = nativeInput.value || null;
            let rows = PRESELECTED.map(id => ({ id: id }));
            if (rows.length === 0) { rows = [{ id: null }]; }

            const langById = id => LANGS.find(l => String(l.id) === String(id));
            const esc = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

            function usedIds(exceptIdx) {
                return rows.filter((r, i) => i !== exceptIdx && r.id).map(r => String(r.id));
            }

            function availableFor(idx) {
                const used = usedIds(idx);
                return LANGS.filter(l => String(l.id) !== String(nativeId) && ! used.includes(String(l.id)));
            }

            function closeMenus() {
                wrap.querySelectorAll('.combo-menu').forEach(m => m.classList.add('hidden'));
                nativeMenu.classList.add('hidden');
            }

            // Native-language combo (custom overlay to match the target-language pickers).
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
                        closeMenus();
                        renderNative();
                        rows = rows.filter(r => String(r.id) !== String(nativeId));
                        if (rows.length === 0) { rows = [{ id: null }]; }
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

            function render() {
                wrap.innerHTML = '';
                rows.forEach((row, idx) => {
                    const lang = row.id ? langById(row.id) : null;
                    const count = row.id ? (COUNTS[row.id] || 0) : 0;

                    const el = document.createElement('div');
                    el.className = 'flex items-center gap-2';
                    el.innerHTML = `
                        <div class="combo relative grow">
                            <button type="button" class="combo-trigger w-full flex items-center justify-between rounded-xl bg-white/10 border border-white/10 px-4 py-2 hover:border-blue-500 transition-colors">
                                <span>${lang ? esc(lang.flag + ' ' + lang.name) : '<span class="text-white/40">Select a language</span>'}</span>
                                <span class="text-white/50 text-xs">▾</span>
                            </button>
                            <div class="combo-menu hidden absolute z-30 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-xl bg-neutral-900 border border-white/10 shadow-xl py-1"></div>
                        </div>
                        ${count > 0 ? `<span class="text-xs text-white/50 whitespace-nowrap">${count} term${count > 1 ? 's' : ''}</span>` : ''}
                        <button type="button" class="remove-row text-xs px-3 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap">${count > 0 ? 'Hide' : '✕'}</button>
                        ${row.id ? `<input type="hidden" name="target_language_ids[]" value="${row.id}">` : ''}
                    `;

                    const menu = el.querySelector('.combo-menu');
                    availableFor(idx).forEach(l => {
                        const o = document.createElement('button');
                        o.type = 'button';
                        o.className = 'combo-option w-full text-left text-sm px-3 py-1 hover:bg-blue-600/30 transition-colors';
                        o.textContent = l.flag + ' ' + l.name;
                        o.addEventListener('click', () => { row.id = l.id; closeMenus(); render(); });
                        menu.appendChild(o);
                    });

                    el.querySelector('.combo-trigger').addEventListener('click', e => {
                        e.stopPropagation();
                        const isOpen = ! menu.classList.contains('hidden');
                        closeMenus();
                        if (! isOpen) { menu.classList.remove('hidden'); }
                    });

                    el.querySelector('.remove-row').addEventListener('click', () => {
                        if (count > 0) {
                            const msg = 'Hide ' + (lang ? lang.name : 'this language') + '?\n\n'
                                + 'Your ' + count + ' saved card' + (count > 1 ? 's' : '')
                                + ' will be kept. If you add this language again later, all its content will be restored.';
                            if (! confirm(msg)) { return; }
                        }
                        rows.splice(idx, 1);
                        render();
                    });

                    wrap.appendChild(el);
                });
                updateControls();
            }

            function updateControls() {
                const disabled = rows.length >= MAX || availableFor(-1).length === 0;
                addBtn.disabled = disabled;
                addBtn.classList.toggle('opacity-50', disabled);
                addBtn.classList.toggle('cursor-not-allowed', disabled);
                hint.classList.toggle('hidden', rows.length < MAX);
            }

            addBtn.addEventListener('click', () => {
                if (rows.length < MAX) { rows.push({ id: null }); render(); }
            });

            document.addEventListener('click', closeMenus);

            renderNative();
            render();
        })();
    </script>
</x-html-layout>
