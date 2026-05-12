<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['user_id', 'action', 'description', 'ip_address', 'metadata', 'entra_app_id'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function entraApp()
    {
        return $this->belongsTo(EntraApp::class, 'entra_app_id');
    }

    public static function log($action, $description = null, $metadata = [], $entraAppId = null)
    {
        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'metadata' => $metadata,
            'entra_app_id' => $entraAppId,
        ]);
    }
}
