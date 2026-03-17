<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\Domain;
use App\Models\AuditLog;
use App\Services\KempService;
use App\Services\FortigateService;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function index()
    {
        $automations = Automation::with('domain')->latest()->get();
        $domains = Domain::orderBy('name')->get();
        return view('automations.index', compact('automations', 'domains'));
    }

    public function testConnection(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:kemp,fortigate,paloalto',
            'hostname' => 'required|string',
            'password' => 'required|string', // This is the API Key/Password
        ]);

        // Create a temporary Automation object for the service
        $tempAuto = new Automation([
            'type' => $validated['type'],
            'hostname' => $validated['hostname'],
        ]);
        // Set password directly (it will be encrypted by the mutator)
        $tempAuto->password = $validated['password'];

        try {
            if ($validated['type'] === 'kemp') {
                $certs = app(KempService::class)->listCerts($tempAuto);
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully connected to Kemp!',
                    'count' => count($certs),
                    'certs' => $certs
                ]);
            }

            if ($validated['type'] === 'fortigate') {
                $certs = app(FortigateService::class)->listCerts($tempAuto);
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully connected to Fortigate!',
                    'count' => count($certs),
                    'certs' => $certs
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "Manufacturer '{$validated['type']}' is not yet fully implemented for testing."
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ], 400);
        }
    }

    public function checkCertificate(Request $request)
    {
        $validated = $request->validate([
            'domain_id' => 'required|exists:domains,id',
            'type' => 'required|in:kemp,fortigate,paloalto',
            'hostname' => 'required|string',
            'password' => 'required|string',
        ]);

        $domain = Domain::findOrFail($validated['domain_id']);
        $certName = "auto_" . str_replace(['*', '.'], ['wildcard', '_'], $domain->name);

        $tempAuto = new Automation([
            'type' => $validated['type'],
            'hostname' => $validated['hostname'],
        ]);
        $tempAuto->password = $validated['password'];

        try {
            if ($validated['type'] === 'kemp') {
                $service = app(KempService::class);
                $certs = $service->listCerts($tempAuto);
                
                $exists = false;
                foreach ($certs as $c) {
                    if (($c['name'] ?? '') === $certName) {
                        $exists = true;
                        break;
                    }
                }

                $details = $exists ? $service->getCert($tempAuto, $certName) : null;

                return response()->json([
                    'success' => true,
                    'exists' => $exists,
                    'cert_name' => $certName,
                    'message' => $exists ? "Certificate '{$certName}' already exists on device." : "Certificate '{$certName}' not found on device. It will be created."
                ]);
            }

            if ($validated['type'] === 'fortigate') {
                $service = app(FortigateService::class);
                $certs = $service->listCerts($tempAuto);
                
                $exists = false;
                foreach ($certs as $c) {
                    if (($c['name'] ?? '') === $certName) {
                        $exists = true;
                        break;
                    }
                }

                return response()->json([
                    'success' => true,
                    'exists' => $exists,
                    'cert_name' => $certName,
                    'message' => $exists ? "Local certificate '{$certName}' already exists on Fortigate." : "Certificate '{$certName}' not found on Fortigate. It will be imported."
                ]);
            }

            return response()->json(['success' => true, 'exists' => false, 'cert_name' => $certName]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'domain_id' => ['required', 'exists:domains,id'],
            'type' => ['required', 'in:kemp,fortigate,paloalto'],
            'hostname' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'config' => ['required', 'array'],
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
            } elseif ($automation->type === 'fortigate') {
                app(FortigateService::class)->deploy($automation, $latestCert);
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
