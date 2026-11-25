<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An extra tab in an event's sales book: a programme outside the draw, as
 * `blocks` empty exhibitor blocks for HQ to fill in Excel. See SalesBook.
 */
class SalesBookTab extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['event_id', 'name', 'blocks'];

    protected $casts = ['blocks' => 'integer'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
