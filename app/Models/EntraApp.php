<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntraApp extends Model
{
    protected $fillable = [
        'display_name',
        'app_id',
        'object_id',
        'type',
        'allowed_groups',
        'notes',
        'is_enabled',
        'last_sync'
    ];

    protected $casts = [
        'allowed_groups' => 'array',
        'is_enabled' => 'boolean',
        'last_sync' => 'datetime',
    ];

    public function secrets(): HasMany
    {
        return $this->hasMany(EntraAppSecret::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
