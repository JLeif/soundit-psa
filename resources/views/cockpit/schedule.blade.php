@extends('layouts.app')
@section('title', 'Schedule approval')
@section('content')
<h1 class="h4">Schedule approval</h1>
<p>Approve this exact proposal to run once in a future window. Execution is not guaranteed: permissions, identity and safety checks are repeated before submission.</p>
<div class="card mb-3"><div class="card-body">
    <a href="{{ route('tickets.show', $run->ticket_id) }}">Ticket #{{ $run->ticket_id }}</a>
    <pre class="text-wrap mt-2">{{ $run->proposed_content }}</pre>
    <form method="POST" action="{{ route('cockpit.schedule.store', $run) }}">
        @csrf
        <input type="hidden" name="content_hash" value="{{ $run->content_hash }}">
        @foreach(['start' => 'Earliest execution', 'end' => 'Window closes'] as $field => $label)
            <label class="form-label" for="schedule-{{ $field }}">{{ $label }}</label>
            <input class="form-control mb-3" id="schedule-{{ $field }}" type="datetime-local" name="{{ $field }}" required>
        @endforeach
        <label class="form-label" for="schedule-zone">Time zone (IANA name)</label>
        <input class="form-control" id="schedule-zone" name="timezone" value="{{ \App\Support\AppTimezone::get() }}" required maxlength="100" aria-describedby="window-help">
        <p class="form-text" id="window-help">Start must be future and within seven days; the window may span at most 24 hours. Ambiguous and nonexistent daylight-saving times are rejected, never silently shifted.</p>
        @foreach(($run->proposed_meta['sensitive_inputs'] ?? []) as $field)
            @if(in_array($field, ['external_smtp', 'internal_message', 'external_message'], true))
                <label class="form-label" for="schedule-{{ $field }}">{{ str_replace('_', ' ', ucfirst($field)) }}</label>
                @if($field === 'external_smtp')
                    <input class="form-control mb-3" id="schedule-{{ $field }}" name="{{ $field }}" type="email" maxlength="254" required autocomplete="off">
                @else
                    <textarea class="form-control mb-3" id="schedule-{{ $field }}" name="{{ $field }}" maxlength="2000" rows="3" required autocomplete="off"></textarea>
                @endif
            @endif
        @endforeach
        <p class="small">Confirmation inputs are encrypted, retained until 30 days after the terminal result, then purged. Cancellation is possible only before dispatch intent wins. Unknown outcomes are marked uncertain and never automatically retried.</p>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm" id="schedule-confirm" value="1" required><label class="form-check-label" for="schedule-confirm">I approve this proposal and window for one scheduled submission.</label></div>
        <button class="btn btn-primary" type="submit">Approve schedule</button>
        <a class="btn btn-outline-secondary" href="{{ route('cockpit.index') }}">Back without scheduling</a>
    </form>
</div></div>
@endsection
