<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\Domain;
use App\Models\AuditLog;
use App\Services\KempService;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function index()
    {
        $automations = Automation::with('domain')->latest()->get();
        $domains = Domain::orderBy('name')->get();
        return view('automations.index', compact('automations', 'domains'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'domain_id' => 'required|exists:domains,id',
            'type' => 'required|in:kemp',
            'hostname' => 'required|string',
            'password' => 'required|string',
            'config' => 'required|array',
        ]);

        $automation = Automation::create($validated);
        AuditLog::log('automation_create', "Created {$automation->type} automation for: {$automation->domain->name}");

        return redirect()->route('automations.index')->with('success', 'Automation created successfully.');
    }

    public function run(Automation $automation)
    {
        $latestCert = $automation->domain->certificates()->where('status', 'issued')->latest()->first();
        
        if (!$latestCert) {
            return back()->withErrors(['error' => 'No issued certificate found for this domain.']);
        }

        try {
            if ($automation->type === 'kemp') {
                app(KempService::class)->deploy($automation, $latestCert);
            }

            AuditLog::log('automation_run', "Manually triggered {$automation->type} deployment for: {$automation->domain->name}");
            return back()->with('success', 'Deployment successful.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Deployment failed: ' . $e->getMessage()]);
        }
    }

    public function destroy(Automation $automation)
    {
        $type = $automation->type;
        $domainName = $automation->domain->name;
        $automation->delete();
        AuditLog::log('automation_delete', "Deleted {$type} automation for: {$domainName}");
        
        return back()->with('success', 'Automation removed.');
    }
}
