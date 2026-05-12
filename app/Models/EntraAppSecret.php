<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntraAppSecret extends Model
{
    protected $fillable = [
        'entra_app_id',
        'display_name',
        'key_id',
        'hint',
        'type',
        'start_date',
        'end_date',
        'thumbprint'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(EntraApp::class, 'entra_app_id');
    }
}
