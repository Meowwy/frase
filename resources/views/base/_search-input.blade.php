{{-- A column header that is a live search input, styled like the /cards list's. --}}
<th class="px-6 py-3 text-left">
    <div class="flex items-center gap-2 border-b border-white/60">
        <svg class="w-4 h-4 shrink-0 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
        <input type="text" name="{{ $name }}" placeholder="{{ $placeholder }}" autocomplete="off" value="{{ $value }}"
               class="js-base-filter w-full bg-transparent py-1 text-xs font-medium text-gray-300 placeholder-gray-300 uppercase tracking-wider focus:outline-none focus:text-white focus:placeholder-gray-500">
    </div>
</th>
