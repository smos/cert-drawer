<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationLog extends Model
{
    protected $fillable = ['automation_id', 'status', 'message', 'details'];

    protected $casts = [
        'details' => 'array',
    ];

    public function automation()
    {
        return $this->belongsTo(Automation::class);
    }
}
