@extends('layouts.admin')

@section('title', 'Sales book — '.$event->title)

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    @include('partials.admin-alerts')

    <div class="mac-breadcrumb mb-2">
        <a href="{{ route('admin.polling.index') }}">Lot Draw</a> <span class="sep" aria-hidden="true">›</span> <a href="{{ route('admin.polling.show', $event) }}">{{ $event->title }}</a> <span class="sep" aria-hidden="true">›</span> Sales book
    </div>

    <div class="mac-header mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2>Sales book</h2>
            <p class="mac-muted mb-0">
                One sheet per craft code from the draw, then one per programme tab below.
                Each day of the event, {{ $event->start_date->format('d M') }} – {{ $event->end_date->format('d M Y') }}, is a column.
                Type the sales into the downloaded file, then upload it back to keep them here.
            </p>
        </div>
        @if($codeSheets->isNotEmpty() || $tabs->isNotEmpty())
            <a href="{{ route('admin.polling.sales-book.download', $event) }}" class="mac-btn-primary">
                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Download workbook (.xlsx)
            </a>
        @endif
    </div>

    <div class="mac-card mac-card-padded mb-3">
        <h3 class="mac-card-title">Upload the filled workbook</h3>
        <p class="mac-muted small mb-2">
            @if($upload)
                Last upload: <strong>{{ $upload->filename }}</strong>, {{ $upload->entries }} sales,
                by {{ $upload->admin?->name ?? 'an admin since removed' }} on {{ $upload->created_at->format('d M Y, H:i') }}.
                A new upload replaces it.
            @else
                Nothing uploaded yet. Each upload replaces the one before: the workbook is the whole book.
            @endif
        </p>
        <form method="POST" action="{{ route('admin.polling.sales-book.upload', $event) }}" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
            @csrf
            <input type="file" name="workbook" accept=".xlsx" class="mac-form-control" style="width:auto" required>
            <button type="submit" class="mac-btn-primary"
                    @if($upload) data-confirm="Replace the {{ $upload->entries }} sales from {{ $upload->filename }}?" @endif>
                <i class="bi bi-upload me-1" aria-hidden="true"></i>Upload
            </button>
        </form>
        @error('workbook')
            <div class="text-danger small mt-2">
                Nothing was saved.
                <ul class="mb-0">
                    @foreach($errors->get('workbook') as $message)
                        @foreach((array) $message as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @enderror
    </div>

    @if($upload)
        <div class="mac-card mac-card-padded mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>The uploaded sales are kept and shown on the Sales page.</span>
            <a href="{{ route('admin.sales.show', $event) }}" class="mac-btn-secondary">
                <i class="bi bi-table me-1" aria-hidden="true"></i>View sales
            </a>
        </div>
    @endif

    <div class="mac-card mac-card-padded mb-3">
        <h3 class="mac-card-title">Add a tab</h3>
        <p class="mac-muted small">For a programme outside the draw (Karyaneka, Ready Eksport, Puspanita…). The tab is left empty for you to fill in Excel.</p>

        <form method="POST" action="{{ route('admin.polling.sales-book.tabs.store', $event) }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="mac-label" for="name">Tab name</label>
                    <input type="text" id="name" name="name" class="mac-form-control" value="{{ old('name') }}" maxlength="31" required>
                    @error('name')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label class="mac-label" for="blocks">Exhibitors</label>
                    <input type="number" id="blocks" name="blocks" class="mac-form-control" min="1" max="200" value="{{ old('blocks', 20) }}" required>
                    @error('blocks')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <button type="submit" class="mac-btn-primary w-100">Add tab</button>
                </div>
            </div>
        </form>
    </div>

    <div class="mac-card">
        <div class="table-responsive">
            <table class="mac-table">
                <thead>
                    <tr>
                        <th>Tab</th>
                        <th>From</th>
                        <th class="text-end">Exhibitors</th>
                        <th class="text-end" style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($codeSheets as $code => $lots)
                        <tr>
                            <td class="fw-semibold">{{ $code }}</td>
                            <td class="mac-muted">Draw</td>
                            <td class="text-end ui-num">{{ $lots }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                    @foreach($tabs as $tab)
                        <tr>
                            <td class="fw-semibold">{{ $tab->name }}</td>
                            <td class="mac-muted">Added</td>
                            <td class="text-end ui-num">{{ $tab->blocks }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('admin.polling.sales-book.tabs.destroy', [$event, $tab]) }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="mac-action-btn mac-action-btn-danger" title="Remove"
                                            data-confirm="Remove the {{ $tab->name }} tab?">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    @if($codeSheets->isEmpty() && $tabs->isEmpty())
                        <tr><td colspan="4"><div class="mac-empty">No plots yet, and no tabs. Generate the plots on the draw page, or add a tab above.</div></td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
