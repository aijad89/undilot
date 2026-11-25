<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sale read from an uploaded sales book: an exhibitor block on one sheet,
 * one day, one payment type. Written in bulk by SalesBookController::upload(),
 * so model events do not fire; the upload row carries the audit.
 */
class SalesBookEntry extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['sale_date' => 'date', 'amount' => 'decimal:2'];

    public function upload()
    {
        return $this->belongsTo(SalesBookUpload::class, 'upload_id');
    }
}
