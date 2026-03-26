<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    protected $fillable = [
        'domain_id', 'issuer_certificate_id', 'request_type', 'csr', 'certificate', 
        'private_key', 'pfx_password', 'issuer', 'expiry_date', 'status',
        'thumbprint_sha1', 'thumbprint_sha256', 'metadata', 'is_ca', 'archived_at'
    ];

    protected $casts = [
        'expiry_date' => 'datetime',
        'metadata' => 'array',
        'is_ca' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function issuerCertificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'issuer_certificate_id');
    }
}
