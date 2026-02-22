<x-html-layout>
    <div class="container mx-auto p-6 flex flex-col items-center justify-center min-h-[50vh]"
         x-data="{
            status: '{{ $exercise->status }}',
            poll() {
                fetch('{{ route('gap-fill.status', $exercise) }}')
                    .then(res => res.json())
                    .then(data => {
                        this.status = data.status;
                        if (data.status === 'completed') {
                            window.location.href = data.url;
                        } else if (data.status === 'failed') {
                            // Handle failure
                        } else {
                            setTimeout(() => this.poll(), 2000);
                        }
                    });
            }
         }"
         x-init="poll()">

        <div class="bg-white/10 p-8 rounded-2xl border border-white/10 text-center max-w-md w-full">
            <div class="mb-6">
                <svg class="animate-spin h-12 w-12 text-blue-500 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>

            <h1 class="text-2xl font-bold mb-2">Generating Exercise</h1>
            <p class="text-white/60 mb-6">Our AI is crafting a coherent story using your words. This usually takes a few seconds...</p>

            <div class="w-full bg-white/5 rounded-full h-2 mb-6">
                <div class="bg-blue-500 h-2 rounded-full transition-all duration-500"
                     :style="status === 'processing' ? 'width: 60%' : 'width: 20%'"></div>
            </div>

            <a href="{{ route('wordbox.show', $exercise->wordbox_id) }}" class="text-sm text-blue-400 hover:text-blue-300">
                &larr; Back to Wordbox
            </a>
        </div>
    </div>
</x-html-layout>
