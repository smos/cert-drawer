<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Automation extends Model
{
    protected $fillable = [
        'domain_id', 'type', 'hostname', 'username', 'password', 'config', 'is_active'
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function setPasswordAttribute($value)
    {
        if ($value) {
            $this->attributes['password'] = encrypt($value);
        }
    }

    public function getDecryptedPassword()
    {
        return $this->password ? decrypt($this->password) : null;
    }
}
