<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Setting;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$interval = 1;
try {
    if (Illuminate\Support\Facades\Schema::hasTable('settings')) {
        $interval = (int) (Setting::where('key', 'dns_check_interval')->value('value') ?? 1);
    }
} catch (\Exception $e) {
    // Use default if DB is not ready
}

Schedule::call(function () {
    Setting::updateOrCreate(['key' => 'scheduler_last_run'], ['value' => now()->toDateTimeString()]);
})->everyMinute();

Schedule::command('dns:monitor')->cron("0 */{$interval} * * *");
Schedule::command('cert:monitor')->cron("0 */{$interval} * * *");
