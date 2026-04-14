<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    protected $fillable = [
        'domain_id', 'issuer_certificate_id', 'request_type', 'csr', 'certificate', 
        'private_key', 'pfx_password', 'issuer', 'expiry_date', 'status',
        'thumbprint_sha1', 'thumbprint_sha256', 'metadata', 'is_ca', 'archived_at',
        'serial_number'
    ];

    protected $hidden = [
        'private_key', 'pfx_password'
    ];

    protected $appends = [
        'has_private_key', 'has_pfx_password'
    ];

    protected $casts = [
        'expiry_date' => 'datetime',
        'metadata' => 'array',
        'is_ca' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function getHasPrivateKeyAttribute(): bool
    {
        return !empty($this->private_key);
    }

    public function getHasPfxPasswordAttribute(): bool
    {
        return !empty($this->pfx_password);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function issuerCertificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'issuer_certificate_id');
    }

    public function linkIssuer(bool $download = true): bool
    {
        if ($this->issuer_certificate_id || !$this->certificate) return false;

        $certService = app(\App\Services\CertificateService::class);
        $allCas = Certificate::where('is_ca', true)->whereNotNull('certificate')->get();
        
        $issuer = $certService->findIssuer($this, $allCas);
        if ($issuer) {
            $this->update(['issuer_certificate_id' => $issuer->id]);
            return true;
        }

        if ($download) {
            $newCa = $certService->downloadAndImportIssuer($this);
            if ($newCa) {
                $this->update(['issuer_certificate_id' => $newCa->id]);
                return true;
            }
        }

        return false;
    }
}
