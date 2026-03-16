<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    protected $fillable = ['name', 'notes', 'allowed_groups', 'is_enabled', 'dns_monitored', 'cert_monitored', 'last_dns_check', 'last_cert_check'];

    protected $casts = [
        'allowed_groups' => 'array',
        'is_enabled' => 'boolean',
        'dns_monitored' => 'boolean',
        'cert_monitored' => 'boolean',
        'last_dns_check' => 'datetime',
        'last_cert_check' => 'datetime',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function dnsLogs(): HasMany
    {
        return $this->hasMany(DnsLog::class);
    }

    public function certHealthLogs(): HasMany
    {
        return $this->hasMany(CertHealthLog::class);
    }
}
