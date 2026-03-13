<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\DnsLog;
use Illuminate\Http\Request;

class DnsController extends Controller
{
    public function health()
    {
        $domains = Domain::where('dns_monitored', true)
            ->where('is_enabled', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->orderBy('name')
            ->get();

        $logs = DnsLog::with('domain')->latest()->limit(50)->get();

        return view('dns.health', compact('domains', 'logs'));
    }

    public function runGlobalCheck(\App\Services\DnsService $dnsService)
    {
        // We use the service directly. For large numbers of domains, 
        // this should be queued, but for now we'll run it synchronously.
        $domains = Domain::where('dns_monitored', true)
            ->where('is_enabled', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->get();

        foreach ($domains as $domain) {
            $dnsService->monitorDomain($domain);
        }

        return back()->with('success', 'Global DNS check completed.');
    }

    public function runDomainCheck(Domain $domain, \App\Services\DnsService $dnsService)
    {
        if (!auth()->user()->canAccess($domain)) {
            abort(403);
        }

        $dnsService->monitorDomain($domain);

        return response()->json(['success' => true, 'message' => 'DNS check completed for ' . $domain->name]);
    }

    public function showLogs(Domain $domain)
    {
        if (!auth()->user()->canAccess($domain)) {
            abort(403);
        }

        $logs = DnsLog::where('domain_id', $domain->id)->latest()->paginate(50);
        return view('dns.domain_logs', compact('domain', 'logs'));
    }
}
