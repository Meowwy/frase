@props(['name', 'title'])

<div
    id="modal-{{ $name }}"
    style="display: none;"
    class="fixed inset-0 z-50 overflow-y-auto"
>
    <!-- Overlay -->
    <div class="fixed inset-0 bg-black/80 transition-opacity" onclick="closeModal('{{ $name }}')"></div>

    <!-- Modal Content. Below lg it is a bottom sheet: in thumb reach, clear of the home indicator. -->
    <div class="flex items-center justify-center min-h-screen p-4 text-center max-lg:min-h-[100dvh] max-lg:items-end max-lg:p-0">
        <div
            class="bg-[#111] border border-white/10 rounded-xl overflow-hidden shadow-xl transform transition-all w-full max-w-lg relative z-10 max-lg:max-w-none max-lg:rounded-b-none max-lg:border-x-0 max-lg:border-b-0 max-lg:pb-[env(safe-area-inset-bottom)]"
        >
            <div class="px-6 py-4 border-b border-white/10 flex justify-between items-center max-lg:px-4 max-lg:py-2">
                <h3 class="text-xl font-bold">{{ $title }}</h3>
                <button type="button" onclick="closeModal('{{ $name }}')" aria-label="Close" class="text-white/50 hover:text-white max-lg:-mr-2.5 max-lg:p-2.5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="p-6 max-lg:p-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>

<script>
    if (typeof window.openModal !== 'function') {
        window.openModal = function(name) {
            const modal = document.getElementById('modal-' + name);
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        }

        window.closeModal = function(name) {
            const modal = document.getElementById('modal-' + name);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('[id^="modal-"]');
                modals.forEach(m => {
                    m.style.display = 'none';
                });
                document.body.style.overflow = 'auto';
            }
        });
    }
</script>
