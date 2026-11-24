<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One dated certificate a company holds or held. `replaced_at` NULL is the one
 * on the profile now; the rest are its history, newest replaced first. Written
 * only by CompanyCredentials::saveCertificate().
 */
class CompanyCertificate extends Model
{
    use Auditable, HasFactory;

    public const TYPES = ['classification', 'quality'];

    protected $fillable = ['company_id', 'type', 'standard', 'number', 'starts_on', 'ends_on', 'path', 'original', 'replaced_at'];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'replaced_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** The shape the profile, the forms and a join request's copy read. */
    public function toCert(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'start' => $this->starts_on?->toDateString(),
            'end' => $this->ends_on?->toDateString(),
            'path' => $this->path,
            // Not $this->original: Eloquent's own property of that name wins.
            'original' => $this->getAttribute('original'),
        ] + ($this->type === 'quality' ? ['standard' => $this->standard] : []);
    }
}
