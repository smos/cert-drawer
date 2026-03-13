<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $totalDomains = Domain::count();
        
        $dnsDomains = Domain::where('dns_monitored', true)
            ->where('is_enabled', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->count();

        $certDomains = Domain::where('is_enabled', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->count();

        $lastRun = Setting::where('key', 'scheduler_last_run')->value('value');
        $schedulerHealthy = false;
        
        if ($lastRun) {
            $lastRunTime = \Carbon\Carbon::parse($lastRun);
            // If it ran in the last 3 minutes, it's healthy
            if ($lastRunTime->greaterThanOrEqualTo(now()->subMinutes(3))) {
                $schedulerHealthy = true;
            }
        }

        return response()->json([
            'status' => 'ok',
            'domains' => $totalDomains,
            'dns_monitored_domains' => $dnsDomains,
            'cert_monitored_domains' => $certDomains,
            'scheduler_running' => $schedulerHealthy,
            'scheduler_last_heartbeat' => $lastRun,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}
