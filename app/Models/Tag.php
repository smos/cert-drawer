<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tag extends Model
{
    protected $fillable = ['domain_id', 'entra_app_id', 'name', 'type'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function entraApp(): BelongsTo
    {
        return $this->belongsTo(EntraApp::class);
    }
}
