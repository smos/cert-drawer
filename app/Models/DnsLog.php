<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsLog extends Model
{
    protected $fillable = ['domain_id', 'record_type', 'old_value', 'new_value'];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
