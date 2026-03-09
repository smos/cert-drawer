<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tag extends Model
{
    protected $fillable = ['domain_id', 'name', 'type'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
