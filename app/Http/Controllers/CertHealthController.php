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
