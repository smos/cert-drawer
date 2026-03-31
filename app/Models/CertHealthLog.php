<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertHealthLog extends Model
{
    protected $fillable = ['domain_id', 'check_type', 'ip_address', 'ip_version', 'thumbprint_sha256', 'issuer', 'expiry_date', 'error'];

    protected $casts = [
        'expiry_date' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
