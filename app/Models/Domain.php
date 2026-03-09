<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    protected $fillable = ['name', 'notes', 'allowed_groups', 'is_enabled'];

    protected $casts = [
        'allowed_groups' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}
