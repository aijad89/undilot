<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A filled sales book uploaded back by HQ. See SalesBookImport. */
class SalesBookUpload extends Model
{
    use Auditable;

    protected $fillable = ['event_id', 'admin_id', 'filename', 'entries'];

    protected $casts = ['entries' => 'integer'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function salesEntries()
    {
        return $this->hasMany(SalesBookEntry::class, 'upload_id');
    }
}
