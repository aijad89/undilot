{{-- A certificate's history, newest first, each with its file. Takes $field
     (classification|quality), $rows (Company::profile()'s _history) and $company. --}}
<div class="pf-history">
    <p class="ap-label">Previous certificates</p>
    <div class="pf-history-scroll">
        <table class="ap-table">
            <thead><tr>
                @if($field === 'quality')<th>Standard</th>@endif
                <th>Number</th><th>Valid from</th><th>Valid until</th><th>File</th>
            </tr></thead>
            <tbody>
                @foreach($rows as $past)
                    <tr>
                        @if($field === 'quality')<td>{{ \App\Models\Company::QUALITY_STANDARDS[$past['standard'] ?? ''] ?? ($past['standard'] ?? '—') }}</td>@endif
                        <td>{{ $past['number'] ?? '—' }}</td>
                        <td class="ap-tabular">{{ ! empty($past['start']) ? \Illuminate\Support\Carbon::parse($past['start'])->format('d M Y') : '—' }}</td>
                        <td class="ap-tabular">{{ ! empty($past['end']) ? \Illuminate\Support\Carbon::parse($past['end'])->format('d M Y') : '—' }}</td>
                        <td>
                            @if(! empty($past['path']))
                                <a href="{{ route('companies.certificate', [$company, $field, 'history' => $past['id']]) }}" target="_blank" rel="noopener">{{ $past['original'] ?? 'View' }}</a>
                            @else — @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
