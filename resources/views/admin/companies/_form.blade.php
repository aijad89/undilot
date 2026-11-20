{{--
    The company form, shared by create and edit. It writes TWO rows: the company
    and the one account that signs in for it. The owner's name, phone, IC and
    community ID are columns on the USER — they are not duplicated on the company.

    Expects:
      $company  — the Company being edited, or a new one
      $zones    — the zones this admin may file a company under
--}}
@php
    $user = $company->user;
    $editing = $company->exists;
    $value = fn (string $field, $default = null) => old($field, $company->$field ?? $default);
    // The form field and the user column are not the same name — `owner_name` is
    // `users.name` — and `old('name')` is the COMPANY's. Both have to be said.
    $owner = fn (string $field, string $column) => old($field, $user?->$column);
@endphp

<div class="mac-card mac-card-padded mb-3">
    <h3 class="mac-card-title">The company</h3>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="mac-label" for="name">Name</label>
            <input type="text" id="name" name="name" class="mac-form-control"
                   value="{{ $value('name') }}" required maxlength="255">
        </div>

        <div class="col-md-6">
            <label class="mac-label" for="state">Zone</label>
            <select id="state" name="state" class="mac-select" required @disabled(count($zones) === 1)>
                @foreach($zones as $zone)
                    <option value="{{ $zone }}" @selected($value('state') === $zone)>{{ $zone }}</option>
                @endforeach
            </select>
            @if(count($zones) === 1)
                {{-- Disabled inputs are not posted, so the one zone still has to
                     travel. The rule on the far side accepts this list only. --}}
                <input type="hidden" name="state" value="{{ $zones[0] }}">
                <small class="mac-muted">Your zone. Only a super or HQ admin can file a company elsewhere.</small>
            @endif
        </div>

        <div class="col-md-6">
            <label class="mac-label" for="business_type">Business type</label>
            <select id="business_type" name="business_type" class="mac-select">
                <option value="">— None —</option>
                {{-- A value from the old data not on the list stays selectable. --}}
                @foreach(array_unique(array_filter([...\App\Support\BusinessTypes::all(), $company->business_type])) as $category)
                    <option value="{{ $category }}" @selected($value('business_type') === $category)>
                        {{ $category }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-md-6">
            {{-- The participant's own answer on the profile; the office may correct it. --}}
            <label class="mac-label" for="entrepreneur_category">Entrepreneur category</label>
            @php $entrepreneur = old('entrepreneur_category', $company->credentials['entrepreneur_category'] ?? null); @endphp
            <select id="entrepreneur_category" name="entrepreneur_category" class="mac-select">
                <option value="">— Not chosen —</option>
                @foreach(\App\Support\CompanyCredentials::ENTREPRENEUR_CATEGORIES as $category)
                    <option value="{{ $category }}" @selected($entrepreneur === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>

        @if($editing)
            {{-- The participant's own answer on the profile; the office may correct it.
                 Edit only: the card file needs the company's folder. --}}
            @php
                $oku = old('oku', $company->credentials['oku'] ?? null);
                $okuCard = $company->credentials['oku_cert'] ?? null;
            @endphp
            <div class="col-md-6">
                <span class="mac-label d-block">OKU status</span>
                <div class="d-flex gap-3 mt-1" data-choices>
                    <label class="mac-text-sm"><input type="radio" name="oku" value="" @checked(! $oku)> Not answered</label>
                    @foreach(\App\Support\CompanyCredentials::OKU as $key => $label)
                        <label class="mac-text-sm"><input type="radio" name="oku" value="{{ $key }}" @checked($oku === $key)> {{ $label }}</label>
                    @endforeach
                </div>
                @error('oku')<small class="text-danger d-block">{{ $message }}</small>@enderror
            </div>

            <div class="col-12" data-oku-card @unless($oku === 'yes') hidden @endunless>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="mac-label" for="oku_number">OKU card number</label>
                        <input type="text" id="oku_number" name="oku_number" class="mac-form-control" maxlength="50"
                               value="{{ old('oku_number', $okuCard['number'] ?? '') }}">
                        @error('oku_number')<small class="text-danger d-block">{{ $message }}</small>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="mac-label" for="oku_file">OKU card <span class="mac-muted">(PDF, JPG or PNG, 5 MB)</span></label>
                        <input type="file" id="oku_file" name="oku_file" class="mac-form-control" accept=".pdf,.jpg,.jpeg,.png">
                        @if(! empty($okuCard['path']))
                            <small class="mac-muted d-block">
                                On file: <a href="{{ route('companies.certificate', [$company, 'oku']) }}" target="_blank" rel="noopener">{{ $okuCard['original'] }}</a>.
                                Leave empty to keep it; a new card number needs a new copy.
                            </small>
                        @endif
                        @error('oku_file')<small class="text-danger d-block">{{ $message }}</small>@enderror
                    </div>
                </div>
            </div>
            <script>
                // The card's fields show, and are needed, only while the answer is yes.
                (function () {
                    var card = document.querySelector('[data-oku-card]');
                    var hasCopy = @json(! empty($okuCard['path']));
                    function sync() {
                        var yes = (document.querySelector('input[name="oku"]:checked') || {}).value === 'yes';
                        card.hidden = ! yes;
                        document.getElementById('oku_number').required = yes;
                        document.getElementById('oku_file').required = yes && ! hasCopy;
                    }
                    document.querySelectorAll('input[name="oku"]').forEach(function (r) { r.addEventListener('change', sync); });
                    sync();
                })();
            </script>
        @endif

        <div class="col-md-6">
            <label class="mac-label" for="registration_no">Registration no. <span class="mac-muted">(optional)</span></label>
            <input type="text" id="registration_no" name="registration_no" class="mac-form-control"
                   value="{{ $value('registration_no') }}" maxlength="100">
        </div>

        <div class="col-md-6 d-flex align-items-end">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                       @checked(old('is_active', $editing ? $company->is_active : true))>
                <label class="form-check-label mac-text-sm" for="is_active">
                    On the books
                    <span class="mac-muted d-block">Switch off to keep the record without offering it to a draw.</span>
                </label>
            </div>
        </div>
    </div>
</div>

<div class="mac-card mac-card-padded mb-3">
    <h3 class="mac-card-title">The account</h3>
    <p class="mac-muted mac-text-sm">
        One account per company — this is who signs in and draws the lot.
    </p>

    <div class="row g-3">
        <div class="col-md-2">
            <label class="mac-label" for="salutation">Salutation</label>
            @php $salutation = $owner('salutation', 'salutation'); @endphp
            <select id="salutation" name="salutation" class="mac-select">
                <option value="">—</option>
                {{-- A value from the old data not on the list stays selectable,
                     or saving would quietly change it. --}}
                @foreach(array_unique(array_filter([...\App\Models\User::SALUTATIONS, $salutation])) as $option)
                    <option value="{{ $option }}" @selected($salutation === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-6">
            <label class="mac-label" for="owner_name">Owner's name</label>
            <input type="text" id="owner_name" name="owner_name" class="mac-form-control"
                   value="{{ $owner('owner_name', 'name') }}" required maxlength="255">
        </div>

        <div class="col-md-4">
            <label class="mac-label" for="phone">Phone</label>
            <input type="text" id="phone" name="phone" class="mac-form-control"
                   value="{{ $owner('phone', 'phone') }}" maxlength="30">
        </div>

        <div class="col-md-4">
            <label class="mac-label" for="ic_number">IC number</label>
            <input type="text" id="ic_number" name="ic_number" class="mac-form-control"
                   value="{{ $owner('ic_number', 'ic_number') }}" placeholder="990101-01-1234" required>
        </div>

        <div class="col-md-4">
            <label class="mac-label" for="community_id">Community ID</label>
            <input type="text" id="community_id" name="community_id" class="mac-form-control"
                   value="{{ $owner('community_id', 'community_id') }}" maxlength="50" required>
        </div>

        <div class="col-12">
            <label class="mac-label" for="address">Address</label>
            <textarea id="address" name="address" class="mac-form-control" rows="3" maxlength="500"
                      autocomplete="street-address">{{ $owner('address', 'address') }}</textarea>
        </div>

        <div class="col-md-4">
            <label class="mac-label" for="postcode">Postcode</label>
            <input type="text" id="postcode" name="postcode" class="mac-form-control"
                   value="{{ $owner('postcode', 'postcode') }}" maxlength="20" autocomplete="postal-code">
        </div>

        <div class="col-md-8">
            <label class="mac-label" for="district">District</label>
            <input type="text" id="district" name="district" class="mac-form-control"
                   value="{{ $owner('district', 'district') }}" maxlength="100">
        </div>
    </div>

    {{-- The password in a block of its own, its note under BOTH fields: under
         one of them it was taller than its neighbour and pushed the grid out
         of line. --}}
    <h4 class="mac-card-title mt-4 pt-3 mb-3" style="border-top:1px solid var(--ui-sep);font-size:0.9375rem;">{{ $editing ? 'Reset password' : 'Password' }}</h4>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="mac-label" for="password">{{ $editing ? 'New password' : 'Password' }}</label>
            <input type="password" id="password" name="password" class="mac-form-control" autocomplete="new-password">
        </div>

        <div class="col-md-6">
            <label class="mac-label" for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation"
                   class="mac-form-control" autocomplete="new-password">
        </div>

        <div class="col-12 mt-1">
            <small class="mac-muted">
                Not needed: the participant signs in with community ID and IC.
                Leave both empty{{ $editing ? ' to keep the current password' : ' (a random one is set)' }}.
            </small>
        </div>

        <div class="col-12">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="account_active" name="account_active" value="1"
                       @checked(old('account_active', $editing ? $user->is_active : true))>
                <label class="form-check-label mac-text-sm" for="account_active">
                    Account may sign in
                    <span class="mac-muted d-block">
                        Switching this off ends the session of whoever is already signed in.
                    </span>
                </label>
            </div>
        </div>
    </div>
</div>

<div class="mb-3 d-flex gap-2">
    <button type="submit" class="mac-btn-primary">Save</button>
    <a href="{{ route('admin.companies.index') }}" class="mac-btn-secondary">Cancel</a>
</div>

@include('partials.inline-errors', ['class' => 'text-danger small d-block mt-1'])
