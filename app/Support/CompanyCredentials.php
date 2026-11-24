<?php

namespace App\Support;

use App\Models\Company;
use App\Models\EventJoinRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A company's recognition, certificates and the staff to contact — the part of the
 * MICF entry form that is the same for every event. Kept on the profile
 * (`companies.credentials`); a request to join copies it as it stood.
 *
 * Product photos live in the same JSON but are sent with each request to join,
 * not from the profile.
 */
final class CompanyCredentials
{
    /** Allowed per file: PDF or an image, 5 MB. */
    public const FILE_RULE = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

    /** The MICF form's "Sila pilih salah satu kategori anda": the participant picks one. */
    public const ENTREPRENEUR_CATEGORIES = [
        'Satu Daerah Satu Industri - Produk Kraf',
        'Mikro, Kecil & Sederhana (MKS)',
        'Skim Pengeluaran Inkubator Kraf',
        'Agensi Kerajaan (*Pendaftaran atas nama agensi luar)',
        'Badan Bukan Kerajaan (NGO)',
        'Agensi Swasta',
    ];

    /** OKU (orang kurang upaya): is the owner a person with disabilities? */
    public const OKU = ['yes' => 'Yes, OKU', 'no' => 'No'];

    /** The recognitions that need proof on file. */
    public const RECOGNISED = ['adiguru', 'tokoh'];

    public const MESSAGES = [
        'recognition.required' => 'Choose the recognition you have received, or Not applicable.',
        'classification.required' => 'Say whether you hold a classification certificate.',
        'quality.required' => 'Choose your quality compliance standard, or Not applicable.',
        'recognition_file.required' => 'Upload proof of the recognition.',
        'classification_file.required' => 'Upload the certificate.',
        'quality_file.required' => 'Upload the certificate.',
        'oku.required' => 'Say whether you are OKU.',
        'oku_number.required' => 'Enter your OKU card number.',
        'oku_file.required' => 'Upload your OKU card.',
        'entrepreneur_category.required' => 'Choose your entrepreneur category.',
        'staff.required' => 'Add at least one staff member to contact.',
        'staff.*.phone.regex' => 'Enter a Malaysian mobile number, e.g. 012-345 6789.',
    ];

    public const ATTRIBUTES = [
        'classification_number' => 'certificate number', 'quality_number' => 'certificate number',
        'classification_start' => 'valid from', 'quality_start' => 'valid from',
        'classification_end' => 'valid until', 'quality_end' => 'valid until',
        'oku_number' => 'OKU card number', 'oku_file' => 'OKU card',
        'staff.*.name' => 'staff name', 'staff.*.phone' => 'mobile number',
    ];

    /**
     * The rules for the profile's credential fields. The quality question is
     * asked only of a craft that has a standard (Batik, Songket, Tenun).
     */
    public static function rules(Request $request, Company $company): array
    {
        $held = $company->profile(false);

        $rules = [
            'recognition' => ['required', Rule::in(array_keys(Company::RECOGNITIONS))],
            'classification' => ['required', Rule::in(['yes', 'no'])],
        ];

        // Proof of a recognition: the file on record counts while the answer is unchanged.
        if (in_array($request->input('recognition'), self::RECOGNISED, true)) {
            $kept = ! empty($held['recognition_cert']['path']) && ($held['recognition'] ?? null) === $request->input('recognition');
            $rules['recognition_file'] = $kept ? ['nullable', ...array_slice(self::FILE_RULE, 1)] : self::FILE_RULE;
        }

        if ($request->input('classification') === 'yes') {
            $rules += self::certificateRules($request, 'classification', $held['classification_cert'] ?? null);
        }

        if ($standards = $company->qualityStandards()) {
            $rules['quality'] = ['required', Rule::in([...$standards, 'none'])];
            if (in_array($request->input('quality'), $standards, true)) {
                // A certificate on file counts only for the standard it was given for:
                // a Batik one is no Songket / Tenunan one, whatever its number.
                $rules += self::certificateRules($request, 'quality', self::certOnFile($held, 'quality', $request->input('quality')));
            }
        }

        return $rules;
    }

    /**
     * The General information card: category, OKU status (with the card's
     * number and a copy while the answer is yes) and the staff to contact.
     * The card on file counts while its number is unchanged.
     */
    public static function staffRules(?Request $request = null, ?Company $company = null): array
    {
        return self::okuRules($request, $company, required: true) + [
            'entrepreneur_category' => ['required', Rule::in(self::ENTREPRENEUR_CATEGORIES)],
            'staff' => ['required', 'array', 'max:20'],
            'staff.*.name' => ['required', 'string', 'max:100'],
            'staff.*.phone' => ['required', 'regex:/^\+?6?01\d[- ]?\d{3,4}[- ]?\d{4}$/'],
        ];
    }

    /**
     * OKU status, and the card's number and a copy while the answer is yes.
     * The copy on file counts while its number is unchanged. The office may
     * leave the status unanswered; the participant may not.
     */
    public static function okuRules(?Request $request, ?Company $company, bool $required): array
    {
        $onFile = $company?->credentials['oku_cert'] ?? null;
        $kept = ! empty($onFile['path']) && $request?->input('oku_number') === ($onFile['number'] ?? null);

        return ['oku' => [$required ? 'required' : 'nullable', Rule::in(array_keys(self::OKU))]]
            + ($request?->input('oku') === 'yes' ? [
                'oku_number' => ['required', 'string', 'max:50'],
                'oku_file' => $kept ? ['nullable', ...array_slice(self::FILE_RULE, 1)] : self::FILE_RULE,
            ] : []);
    }

    /** The OKU card to keep: a new copy, else the one on file while the number is the same. */
    public static function okuCert(Request $request, array $data, Company $company): ?array
    {
        return match (true) {
            ($data['oku'] ?? null) !== 'yes' => null,
            $request->hasFile('oku_file') => [
                'number' => $data['oku_number'],
                'path' => $request->file('oku_file')->store("certificates/{$company->id}", 'local'),
                'original' => $request->file('oku_file')->getClientOriginalName(),
            ],
            default => ['number' => $data['oku_number']]
                + array_intersect_key($company->credentials['oku_cert'] ?? [], array_flip(['path', 'original'])),
        };
    }

    /**
     * Saves the recognition and certificates card: the answers to
     * `credentials`, the dated certificates to company_certificates. New files
     * are stored. Staff, OKU and product photos on record are kept as they are.
     */
    public static function save(Request $request, array $data, Company $company): void
    {
        $held = $company->profile(false);

        // A certificate keeps the file on record unless a new one was sent, and
        // only while it is the same certificate (same number, same end date).
        $certificate = function (string $field) use ($data, $request, $held, $company) {
            $onFile = self::certOnFile($held, $field, $data[$field] ?? null) ?? [];
            $cert = [
                'number' => $data["{$field}_number"] ?? null,
                'start' => $data["{$field}_start"] ?? null,
                'end' => $data["{$field}_end"] ?? null,
            ];

            return $cert + match (true) {
                $request->hasFile("{$field}_file") => [
                    'path' => $request->file("{$field}_file")->store("certificates/{$company->id}", 'local'),
                    'original' => $request->file("{$field}_file")->getClientOriginalName(),
                ],
                ($onFile['number'] ?? null) === $cert['number'] && ($onFile['end'] ?? null) === $cert['end'] => array_intersect_key($onFile, array_flip(['path', 'original'])),
                default => [],
            };
        };

        $recognitionCert = match (true) {
            ! in_array($data['recognition'], self::RECOGNISED, true) => null,
            $request->hasFile('recognition_file') => [
                'path' => $request->file('recognition_file')->store("certificates/{$company->id}", 'local'),
                'original' => $request->file('recognition_file')->getClientOriginalName(),
            ],
            ($held['recognition'] ?? null) === $data['recognition'] => $held['recognition_cert'] ?? null,
            default => null,
        };

        $quality = in_array($data['quality'] ?? null, [null, 'none'], true) ? null : $data['quality'];

        DB::transaction(function () use ($company, $data, $recognitionCert, $certificate, $quality) {
            $company->update(['credentials' => [
                'recognition' => $data['recognition'],
                'recognition_cert' => $recognitionCert,
                'classification' => $data['classification'],
                'quality' => $data['quality'] ?? null,
            ] + ($company->credentials ?? [])]);

            self::saveCertificate($company, 'classification', $data['classification'] === 'yes' ? $certificate('classification') : null);
            self::saveCertificate($company, 'quality', $quality ? $certificate('quality') + ['standard' => $quality] : null);
        });
    }

    /**
     * Makes $cert the company's current certificate of this type. The same
     * certificate (number, end date, file, standard) is updated in place; a
     * different one, or none, moves the current one to the history, file kept.
     */
    private static function saveCertificate(Company $company, string $type, ?array $cert): void
    {
        $current = $company->certificates()->where('type', $type)->whereNull('replaced_at')->first();
        $key = fn (?array $c) => $c ? [$c['number'], $c['end'], $c['path'] ?? null, $c['standard'] ?? null] : null;

        if ($current && $key($current->toCert()) === $key($cert)) {
            $current->update(['starts_on' => $cert['start']]);

            return;
        }

        $current?->update(['replaced_at' => now()]);

        if ($cert) {
            $company->certificates()->create([
                'type' => $type,
                'standard' => $cert['standard'] ?? null,
                'number' => $cert['number'],
                'starts_on' => $cert['start'],
                'ends_on' => $cert['end'],
                'path' => $cert['path'] ?? null,
                'original' => $cert['original'] ?? null,
            ]);
        }
    }

    /**
     * What the profile still lacks before the company may ask to join, as
     * labels to show. Empty when it is complete. A declared certificate counts
     * with its file; an EXPIRED one still counts (owner, 2026-11-24 — the
     * certificates are the participant's choice and bring their own
     * privileges; the office weighs an expired one when it reviews).
     */
    public static function missing(Company $company): array
    {
        $held = $company->profile(false);
        $valid = fn (?array $cert) => ! empty($cert['path']) && ! empty($cert['end']);

        $missing = in_array($held['entrepreneur_category'] ?? null, self::ENTREPRENEUR_CATEGORIES, true) ? [] : ['entrepreneur category'];

        if (! isset(Company::RECOGNITIONS[$held['recognition'] ?? ''])
            || (in_array($held['recognition'], self::RECOGNISED, true) && empty($held['recognition_cert']['path']))) {
            $missing[] = 'recognition';
        }
        if (! in_array($held['classification'] ?? null, ['yes', 'no'], true)
            || ($held['classification'] === 'yes' && ! $valid($held['classification_cert'] ?? null))) {
            $missing[] = 'classification certificate';
        }
        if ($standards = $company->qualityStandards()) {
            $quality = $held['quality'] ?? null;
            if (! in_array($quality, [...$standards, 'none'], true)
                || ($quality !== 'none' && ! $valid($held['quality_cert'] ?? null))) {
                $missing[] = 'quality compliance';
            }
        }
        if (($held['oku'] ?? null) === 'yes' && empty($held['oku_cert']['path'])) {
            $missing[] = 'OKU card';
        }
        if (empty($held['staff'])) {
            $missing[] = 'staff to contact';
        }

        return $missing;
    }

    /** The staff to contact, as one line: "Siti (012-345 6789), Ahmad (019-111 2222)". */
    public static function staffLine(?array $answers): string
    {
        return implode(', ', array_map(fn ($s) => "{$s['name']} ({$s['phone']})", $answers['staff'] ?? [])) ?: '—';
    }

    /** The certificate and product photo files a set of answers points at. */
    public static function filePaths(array $answers): array
    {
        return array_values(array_filter([
            $answers['recognition_cert']['path'] ?? null,
            $answers['oku_cert']['path'] ?? null,
            $answers['classification_cert']['path'] ?? null,
            $answers['quality_cert']['path'] ?? null,
            ...array_column($answers['product_images'] ?? [], 'path'),
            ...array_column($answers['classification_history'] ?? [], 'path'),
            ...array_column($answers['quality_history'] ?? [], 'path'),
        ]));
    }

    /**
     * Deletes the files in $before that neither the company nor any of its
     * requests, on any event, still points at.
     */
    public static function deleteUnused(Company $company, array $before): void
    {
        $inUse = self::filePaths($company->fresh()->profile());
        foreach (EventJoinRequest::where('company_id', $company->id)->get(['answers', 'documents']) as $jr) {
            array_push($inUse, ...self::filePaths($jr->answers ?? []), ...array_column($jr->documents ?? [], 'path'));
        }

        Storage::disk('local')->delete(array_values(array_diff(array_unique($before), $inUse)));
    }

    /**
     * The certificate on file for this answer: the quality one only while the
     * answer is the standard it was given for.
     */
    public static function certOnFile(array $held, string $field, ?string $answer): ?array
    {
        if ($field === 'quality' && ($held['quality_cert']['standard'] ?? null) !== $answer) {
            return null;
        }

        return $held["{$field}_cert"] ?? null;
    }

    /**
     * The rules for one certificate: number, dates, and the file. Expired is
     * refused. The file on record still counts while it is the same certificate
     * (same number, same end date); a renewed one needs its new file.
     */
    private static function certificateRules(Request $request, string $field, ?array $onFile): array
    {
        $same = ! empty($onFile['path'])
            && $request->input("{$field}_number") === $onFile['number']
            && $request->input("{$field}_end") === $onFile['end'];

        return [
            "{$field}_number" => ['required', 'string', 'max:100'],
            "{$field}_start" => ['required', 'date'],
            // An expired one may be kept: it does not hold anything up (owner, 2026-11-24).
            "{$field}_end" => ['required', 'date', "after:{$field}_start"],
            "{$field}_file" => $same ? ['nullable', ...array_slice(self::FILE_RULE, 1)] : self::FILE_RULE,
        ];
    }
}
