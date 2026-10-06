{{-- Shared by both /base tabs: the side panel. Empty until a row is clicked, then it shows the
     Terms of the cards that word or expression is part of. Each row carries its own list in a
     template (the cards are eager-loaded with the page), so a click costs no request. --}}
<aside id="basePanel" class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm lg:sticky lg:top-6 lg:w-72 lg:shrink-0">
    <p class="text-white/40">Select a row to see the cards it is part of.</p>
</aside>

<script>
    $(function () {
        $('.js-base-row').on('click', function () {
            $('.js-base-row').removeClass('bg-blue-600/20');
            $(this).addClass('bg-blue-600/20');
            $('#basePanel').html($(this).find('.js-row-cards').html());
        });
    });
</script>
