{{-- The answers each request to join starts from: the company's profile.
     Changed on the profile; product photos with each request.
     Takes $company (may be null). --}}
@php
    $held = $company?->credentials ?? [];
    // A certificate on record, and how near its end is.
    $certState = function (?array $cert) {
        if (empty($cert['end'])) {
            return null;
        }
        $end = \Illuminate\Support\Carbon::parse($cert['end'])->endOfDay();

        return $end->isPast() ? ['ap-badge-red', 'Expired'] : ($end->lte(now()->addMonths(2)) ? ['ap-badge-orange', 'Expires soon'] : null);
    };
@endphp
<section class="ap-card ap-card-padded">
    <h2 class="ap-section-title">Your company on record</h2>
    @if($held)
        <dl class="ap-facts">
            <dt>Entrepreneur category</dt>
            <dd>{{ $held['entrepreneur_category'] ?? '—' }}</dd>
            <dt>OKU status</dt>
            <dd>{{ \App\Support\CompanyCredentials::OKU[$held['oku'] ?? ''] ?? '—' }}@if(! empty($held['oku_cert']['number'])), card {{ $held['oku_cert']['number'] }}@endif</dd>
            <dt>Staff to contact</dt>
            <dd>{{ \App\Support\CompanyCredentials::staffLine($held) }}</dd>
            <dt>Recognition</dt>
            <dd>{{ \App\Models\Company::RECOGNITIONS[$held['recognition'] ?? ''] ?? '—' }}</dd>
            @foreach(['classification_cert' => 'Classification certificate', 'quality_cert' => 'Quality compliance'] as $key => $name)
                @continue($key === 'quality_cert' && empty($held['quality']))
                <dt>{{ $name }}</dt>
                <dd>
                    @if(! empty($held[$key]['number']))
                        <span class="ap-tabular">{{ $held[$key]['number'] }}</span>@if(! empty($held[$key]['end'])), valid until <span class="ap-tabular">{{ \Illuminate\Support\Carbon::parse($held[$key]['end'])->format('d M Y') }}</span>@endif
                        @if($state = $certState($held[$key]))
                            <span class="ap-badge {{ $state[0] }} ms-1">{{ $state[1] }}</span>
                        @endif
                    @else
                        {{ $key === 'quality_cert' ? 'Not applicable' : 'None' }}
                    @endif
                </dd>
            @endforeach
            <dt>Product photos</dt>
            <dd>{{ count($held['product_images'] ?? []) ? count($held['product_images']).' on file' : 'None yet' }}</dd>
        </dl>
        <p class="ap-side-note mb-0">Each request to join starts from these answers. <a href="{{ route('profile') }}">Change them on your profile.</a></p>
    @else
        <p class="ap-side-note mb-0">Nothing on record yet. <a href="{{ route('profile') }}">Fill in your profile</a>; every request to join starts from it.</p>
    @endif
</section>
