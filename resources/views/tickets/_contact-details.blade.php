{{-- Persistent text, not a hover target: staff can select/copy it with mouse or keyboard. --}}
@if($entity)
    <div class="ticket-contact-details small mt-2" data-ticket-contact="{{ $person ? 'person' : 'client' }}">
        @if($person)
            <a href="{{ route('people.show', $entity) }}" class="d-inline-block mb-1">View {{ $entity->full_name }}</a>
        @endif
        @php
            $phone = $entity->phone_display ?: $entity->phone;
            $mobile = $person ? $entity->mobile_display : null;
        @endphp
        @if($phone)
            <div><span class="text-muted">Phone:</span> <span class="user-select-text">{{ $phone }}</span></div>
        @endif
        @if($mobile)
            <div><span class="text-muted">Mobile:</span> <span class="user-select-text">{{ $mobile }}</span></div>
        @endif
        @if($entity->email)
            <div><span class="text-muted">Email:</span> <span class="user-select-text">{{ $entity->email }}</span></div>
        @endif
        @if(!$phone && !$mobile && !$entity->email)
            <div class="text-muted">No phone or email recorded.</div>
        @endif
    </div>
@endif
