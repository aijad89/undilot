<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\KrafBidang;
use App\Models\KrafPengelasan;
use App\Models\KrafProduk;
use App\Models\KrafSubBidang;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Adding and starring a company's craft selections — one set of rules for the
 * admin company page and the participant's own profile.
 */
final class KrafSelections
{
    public static function rules(): array
    {
        return [
            'bidang_id' => ['required', Rule::exists('kraf_bidangs', 'id')->whereNull('code')],
            'sub_bidang_id' => ['required', 'array', 'min:1'],
            'sub_bidang_id.*' => ['required', 'integer'],
            'pengelasan_id' => ['nullable', 'array'],
            'pengelasan_id.*' => ['integer'],
            'produk_id' => ['nullable', 'array'],
            'produk_id.*' => ['integer'],
        ];
    }

    /**
     * Add a craft selection. Returns an error message, or null when added.
     *
     * HASIL LOGAM is picked several sub-categories at a time and stored as one
     * row each, which is why the input is always an array. Every sub-category is
     * checked to belong to the category named beside it: the two columns describe
     * one path down the hierarchy, and a pair that does not is a row no screen
     * can draw.
     */
    public static function add(Company $company, array $data): ?string
    {
        $category = KrafBidang::findOrFail($data['bidang_id']);

        $subIds = KrafSubBidang::whereIn('id', $data['sub_bidang_id'])
            ->where('bidang_id', $category->id)
            ->pluck('id');

        if ($subIds->isEmpty()) {
            return 'Pick a sub-category that belongs to that craft category.';
        }

        // One at a time unless the category says otherwise, so a stray second
        // value cannot quietly become a second selection.
        if ($category->name !== KrafPicker::MULTI_SUB_CATEGORY) {
            $subIds = $subIds->take(1);
        }

        // Classifications and products ticked, both optional, grouped under the
        // sub-category each belongs to — so one ticked under another sub-category,
        // or a product not placed in any, matches none and is simply not taken.
        $ticked = KrafPengelasan::whereIn('id', $data['pengelasan_id'] ?? [])->get()->groupBy('sub_bidang_id');

        $tickedProduks = KrafProduk::whereIn('id', $data['produk_id'] ?? [])->get()->groupBy('sub_bidang_id');

        // The first selection is the primary; after that, the star on a row changes it.
        $makePrimary = $company->krafSelections()->doesntExist();

        DB::transaction(function () use ($company, $category, $subIds, $makePrimary, $ticked, $tickedProduks) {
            foreach ($subIds as $index => $subId) {
                $selection = CompanyKraf::create([
                    'company_id' => $company->id,
                    'bidang_id' => $category->id,
                    'sub_bidang_id' => $subId,
                    'is_primary' => $makePrimary && $index === 0,
                ]);
                $selection->pengelasan()->attach($ticked->get($subId, collect())->pluck('id'));
                $selection->produks()->attach($tickedProduks->get($subId, collect())->pluck('id'));
            }
        });

        return null;
    }

    /** One primary per company: this one on, any other off. Through the model, so it is audited. */
    public static function makePrimary(Company $company, CompanyKraf $selection): void
    {
        DB::transaction(function () use ($company, $selection) {
            $company->krafSelections()->where('is_primary', true)->whereKeyNot($selection->id)->get()
                ->each->update(['is_primary' => false]);
            $selection->update(['is_primary' => true]);
        });
    }
}
