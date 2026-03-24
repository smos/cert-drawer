<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\CertHealthLog;
use App\Services\CertHealthService;
use Illuminate\Http\Request;

class CertHealthController extends Controller
{
    public function index()
    {
        $domains = Domain::where('is_enabled', true)
            ->where('cert_monitored', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->orderBy('name')
            ->get();

        foreach ($domains as $domain) {
            // Get latest check per IP
            $latestLogs = CertHealthLog::where('domain_id', $domain->id)
                ->whereIn('id', function($query) use ($domain) {
                    $query->selectRaw('MAX(id)')
                        ->from('cert_health_logs')
                        ->where('domain_id', $domain->id)
                        ->groupBy('ip_address');
                })->get();

            $domain->health_logs = $latestLogs;
            
            // Check for IPv4 / IPv6 mismatch
            $thumbprints = $latestLogs->whereNotNull('thumbprint_sha256')->pluck('thumbprint_sha256')->unique();
            $domain->mismatch = $thumbprints->count() > 1;
            
            // Any errors?
            $domain->has_errors = $latestLogs->whereNotNull('error')->count() > 0;

            // Expiry health calculation
            $settings = \App\Models\Setting::all()->pluck('value', 'key');
            $yellow = (int) ($settings['expiry_yellow'] ?? 30);
            $orange = (int) ($settings['expiry_orange'] ?? 20);
            $red = (int) ($settings['expiry_red'] ?? 10);

            $minDays = null;
            foreach ($latestLogs as $log) {
                if ($log->expiry_date) {
                    $days = (int) ceil(now()->diffInDays($log->expiry_date, false));
                    if ($minDays === null || $days < $minDays) {
                        $minDays = $days;
                    }
                }
            }

            $domain->min_days = $minDays;
            if ($domain->has_errors) {
                $domain->health_status = 'critical';
            } elseif ($domain->mismatch) {
                $domain->health_status = 'urgent';
            } elseif ($minDays !== null) {
                if ($minDays <= 0) {
                    $domain->health_status = 'expired';
                } elseif ($minDays <= $red) {
                    $domain->health_status = 'critical';
                } elseif ($minDays <= $orange) {
                    $domain->health_status = 'urgent';
                } elseif ($minDays <= $yellow) {
                    $domain->health_status = 'warning';
                } else {
                    $domain->health_status = 'healthy';
                }
            } else {
                $domain->health_status = $latestLogs->isEmpty() ? 'none' : 'healthy';
            }
        }

        return view('cert_health.index', compact('domains'));
    }

    public function runCheck(CertHealthService $certService)
    {
        $domains = Domain::where('is_enabled', true)
            ->where('cert_monitored', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->get();

        foreach ($domains as $domain) {
            $certService->monitorDomain($domain);
        }

        return back()->with('success', 'Certificate health check completed for all domains.');
    }

    public function runDomainCheck(Domain $domain, CertHealthService $certService)
    {
        if (!auth()->user()->canAccess($domain)) {
            abort(403);
        }

        $certService->monitorDomain($domain);

        return response()->json(['success' => true, 'message' => 'Certificate check completed for ' . $domain->name]);
    }
}
