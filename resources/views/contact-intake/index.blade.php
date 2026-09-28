@extends('layouts.app')
@section('title', 'Contact intake')
@section('content')
<h1 class="section-title">Contact intake</h1>
<p>Unverified claimed-sender inquiries. Matching and replay do not verify identity.</p>
<p>Oldest pending or quarantined: {{ $oldest ? \Illuminate\Support\Carbon::parse($oldest)->toAppTz()->format('Y-m-d H:i T') : 'None' }}</p>
@foreach($counts as $state => $total)<span class="badge bg-secondary">{{ $state }}: {{ $total }}</span> @endforeach
@php($rec = $reconciliation)
@if($rec['balanced'])
<p class="mt-2" data-reconciliation="balanced"><span class="badge bg-success">Balanced</span> Accepted {{ $rec['accepted'] }} = pending + processing + processed + quarantined {{ $rec['ledger'] }}.</p>
@else
<div class="alert alert-danger mt-2" role="alert" data-reconciliation="mismatch"><strong>Does not reconcile.</strong>
Accepted {{ $rec['accepted'] ?? 'unknown (counter row missing)' }}; pending + processing + processed + quarantined {{ $rec['ledger'] }}.
@if($rec['unknown'] !== [])<br>Rows in an unrecognised state: @foreach($rec['unknown'] as $state => $total){{ $state }}: {{ $total }}@if(! $loop->last), @endif @endforeach.@endif
@if($rec['processing'] > 0)<br>{{ $rec['processing'] }} row(s) committed in processing; the processor only sets that state inside its own transaction.@endif
</div>
@endif
<table class="table mt-3"><thead class="thead-brand"><tr><th>Receipt</th><th>State</th><th>Ticket</th><th>Received</th></tr></thead><tbody>
@foreach($rows as $row)
<tr><td><a href="{{ route('contact-intake.show', $row->id) }}">{{ $row->receipt }}</a></td><td>{{ $row->state }}</td><td>{{ $row->ticket_id ?? 'None' }}</td><td>{{ $row->created_at->toAppTz()->format('Y-m-d H:i T') }}</td></tr>
@endforeach
</tbody></table>{{ $rows->links() }}
@endsection
