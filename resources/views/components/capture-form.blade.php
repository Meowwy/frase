@props(['autofocus' => false])

{{-- CAPTURE. The whole of it: a term, optionally the context it was met in, and nothing
     else — no language, no wordbox. The language is detected by CALL 1 and corrected in
     staging, which is why the save-destination picker that used to sit here is gone.

     Self-contained, like <x-wordbox-picker>: it posts over AJAX and returns immediately,
     so the learner is never left waiting on the AI. The instant feedback is a note beside
     the staging link plus the nav's staging badge; the proposal itself resolves in staging. --}}

<x-forms.form :action="route('capture')" method="post" id="captureForm" class="max-w-2xl space-y-3">
    <x-forms.input :label="false" name="capturedWord" id="captureWord" autocomplete="off"
                   placeholder="Word or phrase to learn" class="w-full min-w-[300px]" />

    <x-forms.input :label="false" name="context" id="captureContext" autocomplete="off"
                   placeholder="(Optional) Add context, like a sentence or brief description of the term..." />

    <div class="flex items-center gap-3">
        <x-forms.button id="captureBtn">Capture</x-forms.button>
        <a href="{{ route('staging') }}" class="text-sm text-white/60 hover:text-white transition-colors">
            Review staging
        </a>
        <span id="captureNote" class="hidden text-sm text-green-400"></span>
    </div>
</x-forms.form>

<script>
    $(function () {
        const $form = $('#captureForm');
        const $term = $('#captureWord');
        const $btn = $('#captureBtn');

        @if($autofocus)
            $term.trigger('focus');
        @endif

        $form.on('submit', function (e) {
            e.preventDefault();

            if (! $term.val().trim()) { return; }

            $btn.prop('disabled', true);

            $.post($form.attr('action'), $form.serialize()).done(function (data) {
                $('#captureNote').text('✓ "' + $term.val().trim() + '" added').removeClass('hidden');
                $term.val('').trigger('focus');
                $('#captureContext').val('');

                // The badge is the persistent half of the feedback: the note is replaced by
                // the next capture, the count stays until the proposal is dealt with.
                $('.js-staged-count').text(data.staged_count).removeClass('hidden');

                // On the staging page itself, show the skeleton row straight away and let
                // the list poll until CALL 1 has resolved it.
                if (typeof window.stagingRefresh === 'function') { window.stagingRefresh(); }
            }).fail(function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not capture that term.';
                if (window.toastr) { toastr.error(msg); }
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
</script>
