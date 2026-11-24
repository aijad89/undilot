{{--
    What the participant keeps on their own profile, read-only here: the General
    information and the Recognition and certificates cards, files linked. The
    crafts are the section below, which both sides edit.

    Expects:
      $company — the company being edited
--}}
@php
    $held = $company->profile();
    $missing = \App\Support\CompanyCredentials::missing($company);
    $standards = $company->qualityStandards();
    $date = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    $file = fn (string $key) => ! empty($held["{$key}_cert"]['path'])
        ? '<a href="'.e(route('companies.certificate', [$company, $key])).'" target="_blank" rel="noopener"><i class="bi bi-file-earmark" aria-hidden="true"></i> '.e($held["{$key}_cert"]['original'] ?? 'File').'</a>'
        : '<span class="mac-muted">no file</span>';
    // Certificates held before, newest first, each with its file.
    $history = fn (string $key) => collect($held["{$key}_history"] ?? [])->map(fn ($c) => implode(' · ', array_filter([
        $key === 'quality' ? e(\App\Models\Company::QUALITY_STANDARDS[$c['standard'] ?? ''] ?? ($c['standard'] ?? '')) : null,
        e($c['number'] ?? '—'),
        $date($c['start'] ?? null).' – '.$date($c['end'] ?? null),
        ! empty($c['path'])
            ? '<a href="'.e(route('companies.certificate', [$company, $key, 'history' => $c['id']])).'" target="_blank" rel="noopener"><i class="bi bi-file-earmark" aria-hidden="true"></i> '.e($c['original'] ?? 'File').'</a>'
            : '<span class="mac-muted">no file</span>',
    ])));
@endphp
<style>
    .pc-facts { display: grid; grid-template-columns: minmax(150px, 28%) 1fr; gap: 6px 16px; margin: 0; font-size: 0.875rem; }
    .pc-facts dt { font-weight: 600; color: var(--ui-muted); }
    .pc-facts dd { margin: 0; overflow-wrap: anywhere; }
    .pc-head { grid-column: 1 / -1; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--ui-border); font-size: 0.75rem; letter-spacing: 0.04em; text-transform: uppercase; }
    .pc-history-head { margin: 8px 0 0; font-size: 0.75rem; font-weight: 600; color: var(--ui-muted); }
    .pc-history { margin: 2px 0 0; padding: 0; list-style: none; font-size: 0.8125rem; color: var(--ui-muted); }
    .pc-history li + li { margin-top: 2px; }
    .pc-head:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
    @media (max-width: 640px) { .pc-facts { grid-template-columns: 1fr; gap: 0; } .pc-facts dd { margin-bottom: 8px; } }
</style>
<div class="mac-card mac-card-padded mb-3" id="participant-profile" style="scroll-margin-top: 80px">
    <h3 class="mac-card-title">
        Participant's profile
        <span class="mac-badge mac-badge-gray ms-1">Kept by the participant</span>
        @if($missing)
            <span class="mac-badge mac-badge-orange ms-1">Incomplete</span>
        @else
            <span class="mac-badge mac-badge-green ms-1">Complete</span>
        @endif
    </h3>
    <p class="mac-muted mac-text-sm">
        What the participant sees and changes on their own profile; every request to join copies it.
        @if($missing) Still missing before they can join: {{ implode(', ', $missing) }}.@endif
    </p>

    <dl class="pc-facts">
        <dt class="pc-head">General information</dt>
        <dt>Entrepreneur category</dt><dd>{{ $held['entrepreneur_category'] ?? '—' }}</dd>
        <dt>OKU status</dt><dd>{{ \App\Support\CompanyCredentials::OKU[$held['oku'] ?? ''] ?? '—' }}@if(! empty($held['oku_cert']['number'])), card {{ $held['oku_cert']['number'] }}@endif @if(! empty($held['oku_cert']['path']))· {!! $file('oku') !!}@endif</dd>
        <dt>Staff to contact</dt>
        <dd>
            @forelse($held['staff'] ?? [] as $s)
                {{ $s['name'] }} ({{ $s['phone'] }})@unless($loop->last)<br>@endunless
            @empty
                —
            @endforelse
        </dd>
        <dt>Workshop / factory address</dt>
        <dd style="white-space:pre-line">{{ $company->workshop_address ?: '—' }}</dd>
        @foreach(['office_phone', 'fax', 'facebook', 'instagram', 'website'] as $field)
            <dt>{{ \App\Models\Company::CONTACT_FIELDS[$field] }}</dt>
            <dd>{{ $company->$field ?: '—' }}</dd>
        @endforeach

        <dt class="pc-head">Recognition and certificates</dt>
        <dt>Recognition</dt>
        <dd>
            {{ \App\Models\Company::RECOGNITIONS[$held['recognition'] ?? ''] ?? '—' }}
            @if(in_array($held['recognition'] ?? null, \App\Support\CompanyCredentials::RECOGNISED, true)) · {!! $file('recognition') !!}@endif
        </dd>
        <dt>Classification certificate</dt>
        <dd>
            @if(($held['classification'] ?? null) === 'yes')
                {{ $held['classification_cert']['number'] ?? '—' }} · {{ $date($held['classification_cert']['start'] ?? null) }} – {{ $date($held['classification_cert']['end'] ?? null) }} · {!! $file('classification') !!}
            @else
                {{ ($held['classification'] ?? null) === 'no' ? 'None' : '—' }}
            @endif
            @if($history('classification')->isNotEmpty())
                <p class="pc-history-head">Previous certificates</p>
                <ul class="pc-history">
                    @foreach($history('classification') as $line)<li>{!! $line !!}</li>@endforeach
                </ul>
            @endif
        </dd>
        {{-- Asked while the main craft has a standard; shown too while there is a history to see. --}}
        @if($standards || ! empty($held['quality_history']))
            <dt>Quality compliance</dt>
            <dd>
                @if(in_array($held['quality'] ?? null, $standards, true))
                    {{ \App\Models\Company::QUALITY_STANDARDS[$held['quality']] }} · {{ $held['quality_cert']['number'] ?? '—' }} · {{ $date($held['quality_cert']['start'] ?? null) }} – {{ $date($held['quality_cert']['end'] ?? null) }} · {!! $file('quality') !!}
                @else
                    {{ ($held['quality'] ?? null) === 'none' ? 'Not applicable' : '—' }}
                @endif
            @if($history('quality')->isNotEmpty())
                <p class="pc-history-head">Previous certificates</p>
                <ul class="pc-history">
                    @foreach($history('quality') as $line)<li>{!! $line !!}</li>@endforeach
                </ul>
            @endif
            </dd>
        @endif
    </dl>
</div>
