@extends('layouts.frontend')

@section('title', 'Profile')
@section('page-title', 'Profile')

@section('content')
@include('partials.apple-styles')
<style>
    .pf-choices { display: flex; flex-wrap: wrap; gap: 8px; }
    .pf-choice { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 14px; border: 1px solid var(--ui-border); border-radius: var(--ui-r); font-size: 1rem; color: var(--ui-text); cursor: pointer; }
    .pf-choice:has(input:checked) { border-color: var(--ui-blue); background: var(--ui-blue-tint); color: var(--ui-blue-text); }
    .pf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
    .pf-cert { margin-top: 12px; padding: 14px; border: 1px solid var(--ui-sep); border-radius: var(--ui-r); }
    .pf-cert[hidden] { display: none; }
    .pf-staff-phone { display: flex; gap: 8px; }
    .pf-staff-phone .ap-input { flex: 1; min-width: 0; }
    .pf-staff-phone .ap-btn { flex: none; width: 44px; padding: 0; }
    [data-staff-remove][hidden] { display: none; }
    .pf-crafts { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.9375rem; }
    .pf-crafts td { padding: 10px 8px 10px 0; border-bottom: 1px solid var(--ui-sep); vertical-align: top; }
    .pf-crafts form { display: inline; }
    .pf-ticks { display: grid; gap: 4px; margin-top: 6px; }
    @media (max-width: 640px) { .pf-grid { grid-template-columns: 1fr; } .pf-crafts td { display: block; border: 0; padding: 4px 0; } .pf-crafts tr { display: block; padding: 8px 0; border-bottom: 1px solid var(--ui-sep); } }
</style>

<div class="ap-page ap-dash">
    <div class="ap-page-header">
        <div>
            <h2 class="ap-page-title">Profile</h2>
            <p class="ap-page-subtitle">Fill in your company once. Every request to join an event starts from it.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="ap-alert ap-alert-success" role="status">
            <i class="bi bi-check-circle ap-alert-icon" aria-hidden="true"></i><div>{{ session('success') }}</div>
        </div>
    @endif
    @if(session('error'))
        <div class="ap-alert ap-alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle ap-alert-icon" aria-hidden="true"></i><div>{{ session('error') }}</div>
        </div>
    @endif
    @if($company && $missing)
        <div class="ap-alert ap-alert-warning" role="status" data-profile-missing>
            <i class="bi bi-info-circle ap-alert-icon" aria-hidden="true"></i>
            <div>Still needed before you can join an event: {{ implode(', ', $missing) }}.</div>
        </div>
    @endif

    <div class="ap-profile-stack">
        <section class="ap-card ap-card-padded">
            <h2 class="ap-section-title">Your account</h2>
            <dl class="ap-facts">
                <dt>Name</dt>
                <dd>{{ trim(($user->salutation ? $user->salutation.' ' : '').$user->name) }}</dd>
                <dt>Community ID</dt>
                {{-- With the IC below, what they sign in with. --}}
                <dd class="ap-tabular">{{ $user->community_id ?: '—' }}</dd>
                <dt>Mobile phone</dt>
                <dd class="ap-tabular">{{ $user->phone ?: '—' }}</dd>
                <dt>IC number</dt>
                {{-- In full: it is the participant's own, on their own page. --}}
                <dd class="ap-tabular">{{ $user->ic_number ?: '—' }}</dd>
                <dt>Company address</dt>
                {{-- Line breaks kept as the office typed them. --}}
                <dd style="white-space:pre-line;">{{ $user->address ?: '—' }}</dd>
                <dt>Postcode</dt>
                <dd class="ap-tabular">{{ $user->postcode ?: '—' }}</dd>
                <dt>District</dt>
                <dd>{{ $user->district ?: '—' }}</dd>
                @if($company)
                    <dt>Company</dt>
                    <dd>{{ $company->name }}</dd>
                    <dt>Registration no.</dt>
                    <dd class="ap-tabular">{{ $company->registration_no ?: '—' }}</dd>
                    <dt>Zone</dt>
                    <dd>{{ $company->state ?: '—' }}</dd>
                    <dt>Usual booth size</dt>
                    <dd class="ap-tabular">{{ $company->plot_size ?: '3x3' }}</dd>
                @endif
            </dl>
            <p class="ap-side-note mb-0">The office keeps these. You sign in with your Community ID and IC, so if any is wrong, contact the Kraftangan office to change it.</p>
        </section>

        @if(! $company)
            <section class="ap-card ap-card-padded">
                <p class="ap-side-note mb-0">Your account has no company yet.</p>
            </section>
        @else
            {{-- Two forms, each card saved on its own. --}}
            <div class="ap-profile-stack" data-profile-form>
            <form method="POST" action="{{ route('profile.contact') }}" enctype="multipart/form-data" data-inline-errors>
                @csrf
                @method('PUT')

                <section class="ap-card ap-card-padded">
                    <h2 class="ap-section-title">General information</h2>

                    <div class="ap-field">
                        <label class="ap-label" for="entrepreneur_category">Entrepreneur category</label>
                        <select class="ap-select" id="entrepreneur_category" name="entrepreneur_category" required>
                            <option value="">— Choose —</option>
                            @foreach(\App\Support\CompanyCredentials::ENTREPRENEUR_CATEGORIES as $category)
                                <option value="{{ $category }}" @selected(old('entrepreneur_category', $held['entrepreneur_category'] ?? null) === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                        @error('entrepreneur_category')<p class="ap-field-error">{{ $message }}</p>@enderror
                    </div>

                    <fieldset class="ap-field">
                        <legend class="ap-label">OKU status</legend>
                        <div class="pf-choices">
                            @foreach(\App\Support\CompanyCredentials::OKU as $key => $label)
                                <label class="pf-choice">
                                    <input type="radio" name="oku" value="{{ $key }}" required @checked(old('oku', $held['oku'] ?? null) === $key)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('oku')<p class="ap-field-error">{{ $message }}</p>@enderror

                        @php $okuOnFile = $held['oku_cert'] ?? null; @endphp
                        <div class="pf-cert" data-if="oku:yes">
                            <div class="ap-field">
                                <label class="ap-label" for="oku_number">OKU card number</label>
                                <input class="ap-input" id="oku_number" name="oku_number" maxlength="50" data-required
                                       value="{{ old('oku_number', $okuOnFile['number'] ?? '') }}">
                                @error('oku_number')<p class="ap-field-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="ap-field mb-0">
                                <label class="ap-label" for="oku_file">OKU card <span class="ap-muted">(PDF, JPG or PNG, 5 MB)</span></label>
                                <input type="file" id="oku_file" name="oku_file" accept=".pdf,.jpg,.jpeg,.png" @if(empty($okuOnFile['path'])) data-required @endif>
                                @if(! empty($okuOnFile['path']))
                                    <p class="ap-field-hint">On file: {{ $okuOnFile['original'] }}. Leave empty to keep it; a new card number needs a new copy.</p>
                                @endif
                                @error('oku_file')<p class="ap-field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </fieldset>

                    {{-- The staff the owner names for the office to reach. A blank row is dropped on save. --}}
                    <fieldset class="ap-field">
                        <legend class="ap-label">Staff to contact</legend>
                        <p class="ap-field-hint" style="margin-top:0">Add the staff who handle events for your company. The office calls them.</p>
                        @error('staff')<p class="ap-field-error">{{ $message }}</p>@enderror
                        <div data-staff-list>
                            @foreach(old('staff', $held['staff'] ?? []) ?: [['name' => '', 'phone' => '']] as $i => $s)
                                <div class="pf-grid" data-staff-row>
                                    <div class="ap-field">
                                        <label class="ap-label" for="staff_{{ $i }}_name">Staff name</label>
                                        <input class="ap-input" id="staff_{{ $i }}_name" name="staff[{{ $i }}][name]" maxlength="100" required value="{{ $s['name'] ?? '' }}">
                                        @error("staff.$i.name")<p class="ap-field-error">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="ap-field">
                                        <label class="ap-label" for="staff_{{ $i }}_phone">Mobile</label>
                                        <div class="pf-staff-phone">
                                            <input class="ap-input" type="tel" id="staff_{{ $i }}_phone" name="staff[{{ $i }}][phone]" maxlength="20" placeholder="012-345 6789" required value="{{ $s['phone'] ?? '' }}">
                                            <button type="button" class="ap-btn ap-btn-secondary" data-staff-add aria-label="Add staff" title="Add staff"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                                            <button type="button" class="ap-btn ap-btn-secondary" data-staff-remove aria-label="Remove this staff member" title="Remove"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </div>
                                        @error("staff.$i.phone")<p class="ap-field-error">{{ $message }}</p>@enderror
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="ap-field">
                        <label class="ap-label" for="workshop_address">Workshop / factory address <span class="ap-muted">(optional)</span></label>
                        <textarea class="ap-textarea" id="workshop_address" name="workshop_address" rows="3" maxlength="1000">{{ old('workshop_address', $company->workshop_address) }}</textarea>
                        @error('workshop_address')<p class="ap-field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="pf-grid">
                        @foreach(['office_phone' => ['tel', '03-1234 5678'], 'fax' => ['tel', '03-1234 5678'], 'facebook' => ['url', 'https://www.facebook.com/…'], 'instagram' => ['url', 'https://www.instagram.com/…'], 'website' => ['url', 'https://…']] as $field => [$type, $hint])
                            <div class="ap-field">
                                <label class="ap-label" for="{{ $field }}">{{ \App\Models\Company::CONTACT_FIELDS[$field] }} <span class="ap-muted">(optional)</span></label>
                                <input class="ap-input" type="{{ $type === 'url' ? 'text' : $type }}" id="{{ $field }}" name="{{ $field }}"
                                       maxlength="{{ $type === 'tel' ? 30 : 255 }}" placeholder="{{ $hint }}"
                                       value="{{ old($field, $company->$field) }}">
                                @error($field)<p class="ap-field-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>

                    <button type="submit" class="ap-btn ap-btn-primary">Save information</button>
                </section>
            </form>

            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" data-inline-errors>
                @csrf
                @method('PUT')

                <section class="ap-card ap-card-padded">
                    <h2 class="ap-section-title">Recognition and certificates</h2>

                    <fieldset class="ap-field">
                        <legend class="ap-label">Recognition you have received</legend>
                        <div class="pf-choices">
                            @foreach(\App\Models\Company::RECOGNITIONS as $key => $label)
                                <label class="pf-choice">
                                    <input type="radio" name="recognition" value="{{ $key }}" required @checked(old('recognition', $held['recognition'] ?? null) === $key)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('recognition')<p class="ap-field-error">{{ $message }}</p>@enderror

                        {{-- One file input per recognition; the hidden one is disabled, so only one is sent. --}}
                        @php $recOnFile = $held['recognition_cert'] ?? null; @endphp
                        @foreach(\App\Support\CompanyCredentials::RECOGNISED as $key)
                            @php $kept = ! empty($recOnFile['path']) && ($held['recognition'] ?? null) === $key; @endphp
                            <div class="pf-cert" data-if="recognition:{{ $key }}">
                                <div class="ap-field mb-0">
                                    <label class="ap-label" for="recognition_file_{{ $key }}">Proof of recognition <span class="ap-muted">(PDF, JPG or PNG, 5 MB)</span></label>
                                    <input type="file" id="recognition_file_{{ $key }}" name="recognition_file" accept=".pdf,.jpg,.jpeg,.png" @unless($kept) data-required @endunless>
                                    @if($kept)
                                        <p class="ap-field-hint">On file: {{ $recOnFile['original'] }}. Leave empty to keep it.</p>
                                    @endif
                                    @error('recognition_file')<p class="ap-field-error">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        @endforeach
                    </fieldset>

                    {{-- Each certificate: a yes/no (or standard/none), then its
                         number, dates and file while the answer is yes. --}}
                    @php
                        $certs = [['classification', 'Kraftangan Malaysia classification certificate (Standard 1:2022)', ['yes' => 'Yes, I hold one', 'no' => 'No']]];
                        if ($standard) {
                            $certs[] = ['quality', 'Quality compliance', [$standard => \App\Models\Company::QUALITY_STANDARDS[$standard], 'none' => 'Not applicable']];
                        }
                    @endphp
                    @foreach($certs as [$field, $question, $choices])
                        @php $onFile = $held[$field.'_cert'] ?? null; @endphp
                        <fieldset class="ap-field">
                            <legend class="ap-label">{{ $question }}</legend>
                            <div class="pf-choices">
                                @foreach($choices as $key => $label)
                                    <label class="pf-choice">
                                        <input type="radio" name="{{ $field }}" value="{{ $key }}" required @checked(old($field, $held[$field] ?? null) === $key)>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            @error($field)<p class="ap-field-error">{{ $message }}</p>@enderror

                            <div class="pf-cert" data-if="{{ $field }}:{{ array_key_first($choices) }}">
                                <div class="ap-field">
                                    <label class="ap-label" for="{{ $field }}_number">Certificate number</label>
                                    <input class="ap-input" id="{{ $field }}_number" name="{{ $field }}_number" maxlength="100" data-required
                                           value="{{ old($field.'_number', $onFile['number'] ?? '') }}">
                                    @error($field.'_number')<p class="ap-field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="pf-grid">
                                    <div class="ap-field">
                                        <label class="ap-label" for="{{ $field }}_start">Valid from</label>
                                        <input class="ap-input" type="date" id="{{ $field }}_start" name="{{ $field }}_start" data-required
                                               value="{{ old($field.'_start', $onFile['start'] ?? '') }}">
                                        @error($field.'_start')<p class="ap-field-error">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="ap-field">
                                        <label class="ap-label" for="{{ $field }}_end">Valid until</label>
                                        <input class="ap-input" type="date" id="{{ $field }}_end" name="{{ $field }}_end" min="{{ now()->toDateString() }}" data-required
                                               value="{{ old($field.'_end', $onFile['end'] ?? '') }}">
                                        @error($field.'_end')<p class="ap-field-error">{{ $message }}</p>@enderror
                                    </div>
                                </div>
                                <div class="ap-field mb-0">
                                    <label class="ap-label" for="{{ $field }}_file">Certificate file <span class="ap-muted">(PDF, JPG or PNG, 5 MB)</span></label>
                                    <input type="file" id="{{ $field }}_file" name="{{ $field }}_file" accept=".pdf,.jpg,.jpeg,.png" @if(empty($onFile['path'])) data-required @endif>
                                    @if(! empty($onFile['path']))
                                        <p class="ap-field-hint">On file: {{ $onFile['original'] }}. Leave empty to keep it; a renewed certificate needs its new file.</p>
                                    @endif
                                    @error($field.'_file')<p class="ap-field-error">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </fieldset>
                    @endforeach

                    <button type="submit" class="ap-btn ap-btn-primary">Save details</button>
                </section>
            </form>
            </div>

            <section class="ap-card ap-card-padded" id="crafts" style="scroll-margin-top: 80px">
                <h2 class="ap-section-title">What you make</h2>
                <p class="ap-side-note">Your craft categories, with the classifications and products you sell. The first one added is your main craft.</p>

                @if($picker['selections']->isNotEmpty())
                    <table class="pf-crafts">
                        <tbody>
                            @foreach($picker['selections'] as $selection)
                                <tr>
                                    <td>
                                        <strong>{{ $selection->bidang?->name ?? '—' }}</strong> · {{ $selection->subBidang?->name ?? '—' }}
                                        @if($selection->is_primary)<span class="ap-badge ap-badge-blue ms-1">Main</span>@endif
                                    </td>
                                    <td>{{ $selection->pengelasan->pluck('name')->join(', ') ?: '—' }}</td>
                                    <td>{{ $selection->produks->pluck('name')->join(', ') ?: '—' }}</td>
                                    <td class="text-nowrap">
                                        @unless($selection->is_primary)
                                            <form method="POST" action="{{ route('profile.kraf.primary', $selection) }}">
                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="ap-btn ap-btn-secondary ap-btn-sm">Make main</button>
                                            </form>
                                        @endunless
                                        <form method="POST" action="{{ route('profile.kraf.destroy', $selection) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ap-btn ap-btn-outline ap-btn-sm" aria-label="Remove {{ $selection->subBidang?->name }}">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <form method="POST" action="{{ route('profile.kraf.store') }}">
                    @csrf
                    <div class="pf-grid">
                        <div class="ap-field">
                            <label class="ap-label" for="bidang_id">Category</label>
                            <select id="bidang_id" name="bidang_id" class="ap-select" required
                                    data-multi="{{ \App\Support\KrafPicker::MULTI_SUB_CATEGORY }}">
                                <option value="">— Pick a category —</option>
                                @foreach($picker['bidangs'] as $bidang)
                                    <option value="{{ $bidang->id }}" data-name="{{ $bidang->name }}">{{ $bidang->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ap-field">
                            <label class="ap-label" for="sub_bidang_id">Sub-category</label>
                            <select id="sub_bidang_id" name="sub_bidang_id[]" class="ap-select" required>
                                <option value="">— Pick a category first —</option>
                            </select>
                        </div>
                    </div>
                    <fieldset class="ap-field" id="kraf-pengelasan" hidden>
                        <legend class="ap-label">Classification <span class="ap-muted">(optional)</span></legend>
                        <div class="kraf-ticks pf-ticks"></div>
                    </fieldset>
                    <fieldset class="ap-field" id="kraf-produk" hidden>
                        <legend class="ap-label">Products <span class="ap-muted">(optional)</span></legend>
                        <div class="kraf-ticks pf-ticks"></div>
                    </fieldset>
                    <button type="submit" class="ap-btn ap-btn-secondary">Add craft</button>
                </form>
            </section>
        @endif
    </div>
</div>

@if($company)
    @include('partials.kraf-picker-script', ['picker' => $picker])
    @include('partials.inline-errors', ['class' => 'ap-field-error'])
    <script>
    // A certificate's fields show only while its answer is yes (or the
    // standard). Hidden, they are switched off: neither checked nor sent.
    (function () {
        var form = document.querySelector('[data-profile-form]');
        if (! form) return;
        function toggle() {
            form.querySelectorAll('[data-if]').forEach(function (block) {
                var rule = block.dataset.if.split(':');
                var picked = form.querySelector('input[name="' + rule[0] + '"]:checked');
                var on = !! picked && picked.value === rule[1];
                block.hidden = ! on;
                block.querySelectorAll('input').forEach(function (input) {
                    input.disabled = ! on;
                    input.required = on && input.hasAttribute('data-required');
                });
            });
        }
        form.addEventListener('change', toggle);
        toggle();

        // Staff rows: + copies its row empty; the bin hides while one row is left.
        var list = form.querySelector('[data-staff-list]');
        var next = list.children.length;
        function syncRemove() {
            var rows = list.querySelectorAll('[data-staff-row]');
            rows.forEach(function (row) { row.querySelector('[data-staff-remove]').hidden = rows.length === 1; });
        }
        function addRow(after) {
            var row = after.cloneNode(true), n = next++;
            row.querySelectorAll('.ap-field-error').forEach(function (e) { e.remove(); });
            row.querySelectorAll('input').forEach(function (input) {
                var key = input.name.match(/\[(\w+)\]$/)[1];
                input.name = 'staff[' + n + '][' + key + ']';
                input.id = 'staff_' + n + '_' + key;
                input.value = '';
            });
            row.querySelectorAll('label').forEach(function (label) { label.htmlFor = label.htmlFor.replace(/staff_\d+_/, 'staff_' + n + '_'); });
            after.after(row);
            syncRemove();
            row.querySelector('input').focus();
        }
        // + adds a row under its own; the bin removes its own.
        list.addEventListener('click', function (e) {
            var row = e.target.closest('[data-staff-row]');
            if (e.target.closest('[data-staff-add]')) addRow(row);
            if (e.target.closest('[data-staff-remove]')) { row.remove(); syncRemove(); }
        });
        syncRemove();
    })();
    </script>
@endif
@endsection
