{{--
    The craft-category picker for one company.

    ALL FROM THE HIERARCHY. A selection is a CATEGORY
    (`kraf_bidangs` where `code` IS NULL), a sub-category under it, and any of
    that sub-category's classifications and products, ticked. The craft
    CODE a draw matches on is NOT picked here — it is set per event on the draw
    list, because a company can be in two events under two codes.

    HASIL LOGAM is the one category whose sub-categories are picked several at a
    time; every other is one. The select switches between single and multiple
    below, and the controller enforces the same rule on the far side.

    Expects:
      $company  — the company being edited
      $picker   — App\Support\KrafPicker::data($company)
--}}
<div class="mac-card mac-card-padded mb-3" id="craft-categories" style="scroll-margin-top: 80px">
    <h3 class="mac-card-title">Craft categories</h3>

    @if(session('anchor') === 'craft-categories')
        @include('partials.admin-alerts')
    @endif
    <p class="mac-muted mac-text-sm">
        What this company makes. The first one added is the primary.
    </p>

    @if($picker['selections']->isEmpty())
        <div class="mac-empty py-3">
            <p class="mac-empty-text mb-0">Nothing picked yet.</p>
        </div>
    @else
        <div class="table-responsive mb-3">
            <table class="mac-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Sub-category</th>
                        <th>Classification</th>
                        <th>Products</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($picker['selections'] as $i => $selection)
                        <tr>
                            <td>
                                {{-- HASIL LOGAM is one row per sub-category: name the category
                                     once, on the first of its rows, as the book's merged box does. --}}
                                @unless($i > 0 && $picker['selections'][$i - 1]->bidang_id === $selection->bidang_id)
                                    {{ $selection->bidang?->name ?? '—' }}
                                @endunless
                                @if($selection->is_primary)
                                    <span class="mac-badge mac-badge-blue ms-1">Primary</span>
                                @endif
                            </td>
                            <td>{{ $selection->subBidang?->name ?? '—' }}</td>
                            <td class="mac-text-sm">{{ $selection->pengelasan->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="mac-text-sm">{{ $selection->produks->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="text-end text-nowrap">
                                @unless($selection->is_primary)
                                    <form method="POST" class="d-inline"
                                          action="{{ route('admin.companies.kraf.primary', [$company, $selection]) }}">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="mac-action-btn" title="Make primary">
                                            <i class="bi bi-star"></i>
                                        </button>
                                    </form>
                                @endunless
                                <form method="POST" class="d-inline"
                                      action="{{ route('admin.companies.kraf.destroy', [$company, $selection]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="mac-action-btn mac-action-btn-danger"
                                            title="Remove"
                                            data-confirm="Remove this craft selection?">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.companies.kraf.store', $company) }}">
        @csrf
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="mac-label" for="bidang_id">Category</label>
                <select id="bidang_id" name="bidang_id" class="mac-select" required
                        data-multi="{{ \App\Support\KrafPicker::MULTI_SUB_CATEGORY }}">
                    <option value="">— Pick a category —</option>
                    @foreach($picker['bidangs'] as $bidang)
                        <option value="{{ $bidang->id }}" data-name="{{ $bidang->name }}">{{ $bidang->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-5">
                <label class="mac-label" for="sub_bidang_id">Sub-category</label>
                <select id="sub_bidang_id" name="sub_bidang_id[]" class="mac-select" required>
                    <option value="">— Pick a category first —</option>
                </select>
            </div>

            <div class="col-md-2">
                <button type="submit" class="mac-btn-primary w-100">Add</button>
            </div>

            <fieldset class="col-12" id="kraf-pengelasan" hidden>
                <legend class="mac-label">Classification <span class="mac-muted">— optional</span></legend>
                <div class="kraf-ticks"></div>
            </fieldset>

            <fieldset class="col-12" id="kraf-produk" hidden>
                <legend class="mac-label">Products <span class="mac-muted">— optional</span></legend>
                <div class="kraf-ticks"></div>
            </fieldset>

        </div>
    </form>
</div>

@push('scripts')
@include('partials.kraf-picker-script', ['picker' => $picker])
@endpush
