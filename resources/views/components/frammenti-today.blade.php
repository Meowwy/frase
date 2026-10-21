{{-- Frammenti in one language right now, as one line: not open yet, a session in progress,
     the plan for a new one, or nothing due. --}}
@props(['enough', 'session', 'plan'])

@if(! $enough)
    <span>Not open yet: needs {{ \App\Models\Frammenti::MIN_NOUNS_AND_VERBS }} nouns and {{ \App\Models\Frammenti::MIN_NOUNS_AND_VERBS }} verbs</span>
@elseif($session)
    <span>Batch {{ $session->batches_done + 1 }} of {{ $session->planned_batches }}</span>
@elseif($plan['batches'])
    <span>{{ $plan['due'] }} due → {{ $plan['batches'] }} {{ Str::plural('batch', $plan['batches']) }} · ~{{ $plan['minutes'] }} min</span>
@else
    <span class="text-green-400">✓ Nothing due, come back tomorrow</span>
@endif
