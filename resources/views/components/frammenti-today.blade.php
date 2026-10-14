{{-- Today's Frammenti in one language, as one line: not open yet, done, in progress, the
     plan before it's started, or nothing to plan. --}}
@props(['enough', 'session', 'plan'])

@if(! $enough)
    <span>Not open yet: needs {{ \App\Models\Frammenti::MIN_NOUNS_AND_VERBS }} nouns and {{ \App\Models\Frammenti::MIN_NOUNS_AND_VERBS }} verbs</span>
@elseif($session?->finished_at)
    <span class="text-green-400">✓ Done for today</span>
@elseif($session)
    <span>Batch {{ $session->batches_done + 1 }} of {{ $session->planned_batches }}</span>
@elseif($plan['batches'])
    <span>Today: {{ $plan['due'] }} due · {{ $plan['new'] }} new → {{ $plan['batches'] }} {{ Str::plural('batch', $plan['batches']) }} · ~{{ $plan['minutes'] }} min</span>
@else
    <span>Nothing due and nothing new today</span>
@endif
