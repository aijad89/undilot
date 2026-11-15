@extends('layouts.admin')

@section('title', 'Add Companies')

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    @include('partials.admin-alerts')

    <div class="mac-breadcrumb mb-2">
        <a href="{{ route('admin.events.index') }}">Events</a>
        <span class="sep" aria-hidden="true">›</span>
        <a href="{{ route('admin.events.registrations.index', $event) }}">{{ $event->title }} — Draw List</a>
        <span class="sep" aria-hidden="true">›</span> Add companies
    </div>

    <div class="mac-header mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2>Add Companies</h2>
            <p class="mac-muted mb-0">
                Every company in {{ $isGlobal ? 'every zone' : 'your zone' }}, in or out of {{ $event->title }}.
                Add puts it on the draw list with its own craft code and booth size.
                @if($isHq)
                    Added by HQ, it is verified at once and the company is told.
                @else
                    HQ verifies it before the company is told.
                @endif
            </p>
        </div>

        <a href="{{ route('admin.events.registrations.index', $event) }}" class="mac-btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to the draw list
        </a>
    </div>

    @if($zoneLocked)
        <div class="mac-card mac-card-padded mb-3">
            <p class="mac-muted mb-0">The draw has started: only HQ can add companies now.</p>
        </div>
    @endif

    <form method="GET" class="mac-filter-bar mb-3">
        <input type="search" name="search" value="{{ request('search') }}"
               class="mac-input" placeholder="Company name" aria-label="Search companies">

        <select name="status" class="mac-select" aria-label="Filter by draw">
            <option value="">In or out</option>
            <option value="out" @selected(request('status') === 'out')>Not in the draw</option>
            <option value="in" @selected(request('status') === 'in')>In the draw</option>
        </select>

        @if($isGlobal)
            <select name="zone" class="mac-select" aria-label="Filter by zone">
                <option value="">Every zone</option>
                @foreach($zones as $zone)
                    <option value="{{ $zone }}" @selected(request('zone') === $zone)>{{ $zone }}</option>
                @endforeach
            </select>
        @endif

        <button type="submit" class="mac-filter-btn">Filter</button>

        @if(request()->hasAny(['search', 'status', 'zone']))
            <a href="{{ route('admin.events.registrations.companies', $event) }}" class="mac-clear-btn">Clear</a>
        @endif
    </form>

    {{-- The ticked rows post through this form; the checkboxes in the table
         name it with form="add-selected", so no form is nested in another. --}}
    @unless($zoneLocked)
        <form method="POST" action="{{ route('admin.events.registrations.store', $event) }}"
              id="add-selected" class="mac-card mac-card-padded mb-3">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="mac-label" for="code">Craft code for the ticked companies</label>
                    <select id="code" name="code" class="mac-select">
                        <option value="">— Each company's own code —</option>
                        @foreach($codes as $code)
                            <option value="{{ $code->code }}">{{ $code->code }} — {{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="mac-label" for="plot_size">Booth size</label>
                    <select id="plot_size" name="plot_size" class="mac-select">
                        <option value="">— Each company's own size —</option>
                        @foreach($plotSizes as $size)
                            <option value="{{ $size }}">{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="mac-btn-primary w-100">Add selected</button>
                </div>
            </div>
        </form>
    @endunless

    <div class="mac-card">
        <div class="table-responsive">
            <table class="mac-table">
                <thead>
                    <tr>
                        <th style="width:32px;">
                            @unless($zoneLocked)
                                <input type="checkbox" id="tick-all" aria-label="Tick every company not in the draw">
                            @endunless
                        </th>
                        <th>Company</th>
                        <th>Zone</th>
                        <th>Craft code</th>
                        <th>Booth</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($companies as $company)
                        @php($registration = $company->eventRegistrations->first())
                        <tr>
                            <td>
                                @if(! $registration && ! $zoneLocked && $company->is_active)
                                    <input type="checkbox" class="tick-row" name="company_id[]" value="{{ $company->id }}"
                                           form="add-selected" aria-label="Tick {{ $company->name }}">
                                @endif
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $company->name }}</div>
                                <div class="mac-muted mac-text-sm">{{ $company->user?->name }}</div>
                            </td>
                            <td class="mac-text-sm">{{ $company->state }}</td>
                            <td>{{ ($registration ? $registration->code : $company->kraf_code) ?: '—' }}</td>
                            <td>{{ ($registration ? $registration->plot_size : $company->plot_size) ?: '—' }}</td>
                            <td>
                                @if(! $registration)
                                    <span class="mac-badge mac-badge-gray">Not in the draw</span>
                                    @unless($company->is_active)
                                        <span class="mac-badge mac-badge-gray">Switched off</span>
                                    @endunless
                                @else
                                    <span class="mac-badge mac-badge-green">In the draw</span>
                                    @if($registration->hq_note)
                                        <span class="mac-badge mac-badge-orange">Returned by HQ</span>
                                    @elseif(! $registration->verified_at)
                                        <span class="mac-badge mac-badge-orange">Waiting for HQ</span>
                                    @endif
                                @endif
                            </td>
                            <td class="text-end">
                                @if(! $registration)
                                    @if(! $zoneLocked && $company->is_active)
                                        <form method="POST" action="{{ route('admin.events.registrations.store', $event) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="company_id[]" value="{{ $company->id }}">
                                            <button type="submit" class="mac-action-btn" title="Add {{ $company->name }} to the draw">
                                                <i class="bi bi-plus-lg"></i> Add
                                            </button>
                                        </form>
                                    @endif
                                @else
                                    @unless($codeFrozen)
                                        <details class="mac-pop d-inline-block text-start mb-1">
                                            <summary class="mac-text-sm">Edit</summary>
                                            <form method="POST" action="{{ route('admin.events.registrations.update', [$event, $registration]) }}" class="d-flex gap-1 mt-1">
                                                @csrf
                                                @method('PUT')
                                                <select name="code" class="mac-select mac-action-btn-field" style="min-width: 110px" aria-label="Craft code for {{ $company->name }}">
                                                    <option value="">— None —</option>
                                                    @foreach($codes as $code)
                                                        <option value="{{ $code->code }}" @selected($registration->code === $code->code)>{{ $code->code }}</option>
                                                    @endforeach
                                                    @if($registration->code && ! $codes->contains('code', $registration->code))
                                                        {{-- Withdrawn since it was set: kept, or the save would blank it. --}}
                                                        <option value="{{ $registration->code }}" selected>{{ $registration->code }} (retired)</option>
                                                    @endif
                                                </select>
                                                <select name="plot_size" class="mac-select mac-action-btn-field" style="min-width: 90px" aria-label="Booth for {{ $company->name }}">
                                                    @foreach($plotSizes as $size)
                                                        <option value="{{ $size }}" @selected($registration->plot_size === $size)>{{ $size }}</option>
                                                    @endforeach
                                                    @if($registration->plot_size && ! in_array($registration->plot_size, $plotSizes, true))
                                                        <option value="{{ $registration->plot_size }}" selected>{{ $registration->plot_size }}</option>
                                                    @endif
                                                </select>
                                                <button type="submit" class="mac-action-btn" title="{{ $isHq ? 'Save and verify' : 'Save — HQ verifies it again' }}">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        </details>
                                    @endunless

                                    {{-- Told it is in: only HQ takes it out, and says why. --}}
                                    @if($registration->first_verified_at && $isHq)
                                        <details class="mac-pop d-inline-block text-start">
                                            <summary class="mac-text-sm">Remove</summary>
                                            <form method="POST" action="{{ route('admin.events.registrations.destroy', [$event, $registration]) }}" class="d-flex gap-1 mt-1">
                                                @csrf
                                                @method('DELETE')
                                                <input type="text" name="removal_reason" class="mac-form-control mac-action-btn-field" style="min-width: 140px" placeholder="Reason, sent to the company" aria-label="Why {{ $company->name }} is removed" required maxlength="1000">
                                                <button type="submit" class="mac-action-btn mac-action-btn-danger" title="Remove from the draw list">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </details>
                                    @elseif(! $registration->first_verified_at)
                                        <form method="POST" action="{{ route('admin.events.registrations.destroy', [$event, $registration]) }}" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="mac-action-btn mac-action-btn-danger"
                                                    title="Remove from the draw list"
                                                    data-confirm="Remove {{ $company->name }} from this draw?">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @else
                                        <span class="mac-muted mac-text-sm">Only HQ can remove</span>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="mac-empty">
                                    <div class="mac-empty-icon"><i class="bi bi-building"></i></div>
                                    <p class="mac-empty-text">No company matches.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $companies->links() }}</div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const all = document.getElementById('tick-all');

    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.tick-row').forEach(box => { box.checked = all.checked; });
        });
    }
});
</script>
@endpush
@endsection
