<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    protected $fillable = [
        'domain_id', 'request_type', 'csr', 'certificate', 
        'private_key', 'pfx_password', 'issuer', 'expiry_date', 'status',
        'thumbprint_sha1', 'thumbprint_sha256', 'metadata', 'is_ca'
    ];

    protected $casts = [
        'expiry_date' => 'datetime',
        'metadata' => 'array',
        'is_ca' => 'boolean',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
