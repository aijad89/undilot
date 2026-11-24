{{-- A join request's answers as the company gave them: read by the office
     and by the participant, so one wording serves both. An expired
     certificate does not stop a request (owner, 2026-11-24); it is marked so
     the office can weigh it. --}}
@php $expired = fn (?string $end) => $end && \Illuminate\Support\Carbon::parse($end)->endOfDay()->isPast() ? ' (expired)' : ''; @endphp
<div>Entrepreneur category: {{ $a['entrepreneur_category'] ?? '—' }}</div>
<div>OKU status: {{ \App\Support\CompanyCredentials::OKU[$a['oku'] ?? ''] ?? '—' }}@if(! empty($a['oku_cert']['number'])), card {{ $a['oku_cert']['number'] }}@endif</div>
<div>Staff to contact: {{ \App\Support\CompanyCredentials::staffLine($a) }}</div>
<div>Recognition: {{ \App\Models\Company::RECOGNITIONS[$a['recognition'] ?? ''] ?? '—' }}</div>
<div>Classification certificate:
    @if(! empty($a['classification_cert']['number']))
        {{ $a['classification_cert']['number'] }}@if(! empty($a['classification_cert']['end'])), valid until {{ \Illuminate\Support\Carbon::parse($a['classification_cert']['end'])->format('d M Y') }}{{ $expired($a['classification_cert']['end']) }}@endif
    @else
        none
    @endif
</div>
@if(! empty($a['quality']))
    <div>Quality compliance:
        @if(! empty($a['quality_cert']['number']))
            {{ \App\Models\Company::QUALITY_STANDARDS[$a['quality']] ?? $a['quality'] }}, {{ $a['quality_cert']['number'] }}@if(! empty($a['quality_cert']['end'])), valid until {{ \Illuminate\Support\Carbon::parse($a['quality_cert']['end'])->format('d M Y') }}{{ $expired($a['quality_cert']['end']) }}@endif
        @else
            not applicable
        @endif
    </div>
@endif
