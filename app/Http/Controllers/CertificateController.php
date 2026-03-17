<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Domain;
use App\Models\AuditLog;
use App\Services\CertificateService;
use App\Services\AcmeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\AdcsService;
use App\Models\Setting;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    protected $certService;
    protected $adcsService;
    protected $acmeService;

    public function __construct(CertificateService $certService, AdcsService $adcsService, AcmeService $acmeService)
    {
        $this->certService = $certService;
        $this->adcsService = $adcsService;
        $this->acmeService = $acmeService;
    }

    protected function authorizeAccess(Domain $domain)
    {
        if (!Auth::user()->canAccess($domain)) {
            abort(403, 'Unauthorized access to this domain.');
        }
    }

    public function previewCsrConfig(Request $request, Domain $domain)
    {
        $this->authorizeAccess($domain);

        $validated = $request->validate([
            'csr_option' => ['required', Rule::in(['auto', 'custom', 'upload'])],
            'cn' => [
                'required_if:csr_option,custom',
                'nullable',
                'string',
                'max:255',
                'regex:#^(\*\.)?([a-zA-Z0-9- \._]+\.)*[a-zA-Z0-9- \._]+$#'
            ],
            'sans' => 'nullable|array',
        ]);

        $settings = Setting::all()->pluck('value', 'key');
        $altNames = [];
        $commonName = $domain->name;
        $organization = $settings['dn_organization'] ?? "Organization";
        $ou = $settings['dn_ou'] ?? "IT Department";

        if ($validated['csr_option'] === 'custom') {
            $commonName = $validated['cn'];
            $altNames = $validated['sans'] ?? [];
        } else {
            // Auto: Try to get SANs and other details from latest issued certificate
            $latestCert = $domain->certificates()->where('status', 'issued')->whereNotNull('certificate')->latest()->first();
            if ($latestCert) {
                $info = $this->certService->getCertInfo($latestCert->certificate);
                $altNames = $this->certService->extractSansFromCert($info);
                $commonName = $info['subject']['CN'] ?? $domain->name;
                $organization = $info['subject']['O'] ?? $organization;
                $ou = $info['subject']['OU'] ?? $ou;
                if (is_array($ou)) $ou = implode(' / ', $ou);
            }
        }

        $dn = [
            "countryName" => $settings['dn_country'] ?? "NL",
            "stateOrProvinceName" => $settings['dn_state'] ?? "State",
            "localityName" => $settings['dn_locality'] ?? "Locality",
            "organizationName" => $organization,
            "organizationalUnitName" => $ou,
            "commonName" => $commonName,
        ];

        $config = $this->certService->getOpenSslConfig($dn, $altNames);

        return response()->json([
            'config' => $config,
            'dn' => $dn,
            'altNames' => $altNames
        ]);
    }

    public function initiateRequest(Request $request, Domain $domain)
    {
        $this->authorizeAccess($domain);

        $validated = $request->validate([
            'csr_option' => ['required', Rule::in(['auto', 'custom', 'upload', 'manual_config'])],
            'cn' => [
                'required_if:csr_option,custom',
                'nullable',
                'string',
                'max:255',
                'regex:#^(\*\.)?([a-zA-Z0-9- \._]+\.)*[a-zA-Z0-9- \._]+$#'
            ],
            'sans' => 'nullable|array',
            'csr' => 'required_if:csr_option,upload|string',
            'config' => 'required_if:csr_option,manual_config|string',
        ]);

        $csr = null;
        $privateKey = null;
        $settings = Setting::all()->pluck('value', 'key');

        if ($validated['csr_option'] === 'manual_config') {
            $res = $this->certService->generateCsrWithConfig($validated['config']);
            $csr = $res['csr'];
            $privateKey = $res['private_key'];
        } elseif ($validated['csr_option'] === 'custom') {
            $dn = [
                "countryName" => $settings['dn_country'] ?? "NL",
                "stateOrProvinceName" => $settings['dn_state'] ?? "State",
                "localityName" => $settings['dn_locality'] ?? "Locality",
                "organizationName" => $settings['dn_organization'] ?? "Organization",
                "organizationalUnitName" => $settings['dn_ou'] ?? "IT Department",
                "commonName" => $validated['cn'],
            ];
            $res = $this->certService->generateCsr($dn, $validated['sans']);
            $csr = $res['csr'];
            $privateKey = $res['private_key'];
        } elseif ($validated['csr_option'] === 'upload') {
            $csr = $validated['csr'];
        } else { // auto
            $dn = [
                "countryName" => $settings['dn_country'] ?? "NL",
                "stateOrProvinceName" => $settings['dn_state'] ?? "State",
                "localityName" => $settings['dn_locality'] ?? "Locality",
                "organizationName" => $settings['dn_organization'] ?? "Organization",
                "organizationalUnitName" => $settings['dn_ou'] ?? "IT Department",
                "commonName" => $domain->name,
            ];
            $res = $this->certService->generateCsr($dn);
            $csr = $res['csr'];
            $privateKey = $res['private_key'];
        }

        if (!$csr) {
            return response()->json(['success' => false, 'message' => 'Failed to generate or retrieve CSR.'], 400);
        }

        $certificate = $domain->certificates()->create([
            'request_type' => 'unknown',
            'csr' => $csr,
            'private_key' => $privateKey ? encrypt($privateKey) : null,
            'status' => 'requested',
        ]);

        $path = "certificates/" . $domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        Storage::disk('local')->put($path . "/request.csr", $csr);

        AuditLog::log('csr_initiate', "Initiated CSR for domain: {$domain->name}", ['option' => $validated['csr_option']]);

        return response()->json(['success' => true, 'message' => 'CSR successfully created.', 'certificate' => $certificate]);
    }

    public function requestAdcs(Request $request, Certificate $certificate)
    {
        $this->authorizeAccess($certificate->domain);

        $domain = $certificate->domain;
        $validated = $request->validate([
            'adcs_username' => 'required|string',
            'adcs_password' => 'required|string',
        ]);

        $settings = Setting::all()->pluck('value', 'key');
        $adcsEndpoint = $settings['adcs_endpoint'] ?? null;
        if ($adcsEndpoint) {
            if (!str_contains($adcsEndpoint, '/certsrv')) {
                $adcsEndpoint = rtrim($adcsEndpoint, '/') . '/certsrv';
            }
        }
        $adcsTemplate = $settings['adcs_template'] ?? null;

        try {
            $issuedCert = $this->adcsService->requestCertificate(
                $adcsEndpoint,
                $adcsTemplate,
                $certificate->csr,
                $validated['adcs_username'],
                $validated['adcs_password']
            );

            $path = "certificates/" . $domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
            Storage::disk('local')->put($path . "/certificate.cer", $issuedCert);

            $certInfo = $this->certService->getCertInfo($issuedCert);
            $expiry = isset($certInfo['validTo_time_t']) ? date('Y-m-d H:i:s', $certInfo['validTo_time_t']) : null;
            $issuer = $certInfo['issuer']['CN'] ?? 'Unknown';

            $certificate->update([
                'certificate' => $issuedCert,
                'status' => 'issued',
                'request_type' => 'adcs',
                'expiry_date' => $expiry,
                'issuer' => $issuer,
                'thumbprint_sha1' => $this->certService->extractThumbprint($issuedCert, 'sha1'),
                'thumbprint_sha256' => $this->certService->extractThumbprint($issuedCert, 'sha256'),
            ]);

            AuditLog::log('cert_fulfill_adcs', "Fulfilled certificate via ADCS for domain: {$domain->name}");

            return response()->json(['success' => true, 'message' => 'Certificate issued by ADCS.']);
        } catch (\SoapFault $sf) {
            \Log::error("ADCS SOAP Fault: " . $sf->getMessage());
            return response()->json(['success' => false, 'message' => 'ADCS SOAP Fault: ' . $sf->faultstring], 400);
        } catch (\Exception $e) {
            \Log::error("ADCS Request General Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Internal Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function requestAcme(Request $request, Certificate $certificate)
    {
        $this->authorizeAccess($certificate->domain);
        
        // With acme.sh, we just mark it as acme type and ready for fulfillment
        $certificate->update([
            'request_type' => 'acme',
            'status' => 'pending_verification'
        ]);

        AuditLog::log('acme_order_initiate', "Initiated ACME fulfillment via acme.sh for: {$certificate->domain->name}");

        return response()->json(['success' => true, 'message' => 'Ready to verify via acme.sh.']);
    }

    public function fulfillAcme(Request $request, Certificate $certificate)
    {
        $this->authorizeAccess($certificate->domain);

        try {
            // This runs the acme.sh command
            $this->acmeService->issueCertificate($certificate);

            return response()->json(['success' => true, 'message' => 'Certificate issued successfully via acme.sh.']);
        } catch (\Exception $e) {
            \Log::error("ACME fulfill error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'ACME Error: ' . $e->getMessage()], 500);
        }
    }

    public function show(Certificate $certificate)
    {
        $this->authorizeAccess($certificate->domain);

        $details = [
            'id' => $certificate->id,
            'status' => $certificate->status,
            'request_type' => $certificate->request_type,
            'created_at' => $certificate->created_at->toDateTimeString(),
            'issuer' => $certificate->issuer,
            'expiry_date' => $certificate->expiry_date ? $certificate->expiry_date->toDateTimeString() : null,
            'metadata' => $certificate->metadata,
        ];

        if ($certificate->certificate) {
            $info = $this->certService->getCertInfo($certificate->certificate);
            $details['type'] = 'Certificate';
            $details['subject'] = $info['name'] ?? 'Unknown';
            $details['serial'] = $info['serialNumber'] ?? 'Unknown';
            $details['signature_type'] = $info['signatureTypeSN'] ?? 'Unknown';
            $details['valid_from'] = isset($info['validFrom_time_t']) ? date('Y-m-d H:i:s', $info['validFrom_time_t']) : 'Unknown';
            $details['sans'] = $this->certService->extractSansFromCert($info);
            $details['full_subject'] = json_encode($info['subject'] ?? []);
            $details['thumbprint_sha1'] = $certificate->thumbprint_sha1;
            $details['thumbprint_sha256'] = $certificate->thumbprint_sha256;
        } elseif ($certificate->csr) {
            $details['type'] = 'CSR';
            $details['csr_body'] = $certificate->csr;
        }

        return response()->json($details);
    }

    public function generatePfx(Request $request, Certificate $certificate)
    {
        $this->authorizeAccess($certificate->domain);

        if (!$request->isMethod('post')) {
            abort(405, 'Method Not Allowed. Please use POST to transmit sensitive data.');
        }
        $password = $request->input('password');

        if (!$password) {
            return response()->json(['error' => 'Password required'], 400);
        }

        try {
            $pfxData = $this->certService->generatePfx(
                $certificate->certificate,
                decrypt($certificate->private_key),
                $password
            );

            $certificate->update(['pfx_password' => encrypt($password)]);
            
            AuditLog::log('pfx_generate', "Generated PFX for domain: {$certificate->domain->name}");

            return response($pfxData)
                ->header('Content-Type', 'application/x-pkcs12')
                ->header('Content-Disposition', 'attachment; filename="' . $certificate->domain->name . '.pfx"');

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function generateLegacyPfx(Request $request, Certificate $certificate)
    {
        $this->authorizeAccess($certificate->domain);

        if (!$request->isMethod('post')) {
            abort(405, 'Method Not Allowed. Please use POST to transmit sensitive data.');
        }
        $password = $request->input('password');

        if (!$password) {
            return response()->json(['error' => 'Password required'], 400);
        }

        try {
            $pfxData = $this->certService->generateLegacyPfx(
                $certificate->certificate,
                decrypt($certificate->private_key),
                $password
            );

            $certificate->update(['pfx_password' => encrypt($password)]);
            
            AuditLog::log('pfx_legacy_generate', "Generated Legacy PFX for domain: {$certificate->domain->name}");

            return response($pfxData)
                ->header('Content-Type', 'application/x-pkcs12')
                ->header('Content-Disposition', 'attachment; filename="' . $certificate->domain->name . '-legacy.pfx"');

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function upload(Request $request, Certificate $certificate)
    {
        $this->authorizeAccess($certificate->domain);

        $request->validate([
            'certificate' => 'required|string',
        ]);

        $path = "certificates/" . $certificate->domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        Storage::disk('local')->put($path . "/certificate.cer", $request->input('certificate'));

        $certInfo = $this->certService->getCertInfo($request->input('certificate'));
        $expiry = isset($certInfo['validTo_time_t']) ? date('Y-m-d H:i:s', $certInfo['validTo_time_t']) : null;
        $issuer = $certInfo['issuer']['CN'] ?? 'Unknown';

        $certificate->update([
            'certificate' => $request->input('certificate'),
            'status' => 'issued',
            'request_type' => 'manual',
            'expiry_date' => $expiry,
            'issuer' => $issuer,
            'thumbprint_sha1' => $this->certService->extractThumbprint($request->input('certificate'), 'sha1'),
            'thumbprint_sha256' => $this->certService->extractThumbprint($request->input('certificate'), 'sha256'),
        ]);

        AuditLog::log('cert_fulfill_manual', "Fulfilled certificate manually for domain: {$certificate->domain->name}");

        return response()->json($certificate);
    }

    public function download(Certificate $certificate, $type)
    {
        $this->authorizeAccess($certificate->domain);

        switch ($type) {
            case 'cert':
                AuditLog::log('cert_download', "Downloaded certificate for domain: {$certificate->domain->name}");
                return response($certificate->certificate)
                    ->header('Content-Type', 'application/x-x509-ca-cert')
                    ->header('Content-Disposition', 'attachment; filename="' . $certificate->domain->name . '.cer"');
            case 'csr':
                AuditLog::log('csr_download', "Downloaded CSR for domain: {$certificate->domain->name}");
                return response($certificate->csr)
                    ->header('Content-Type', 'application/x-pem-file')
                    ->header('Content-Disposition', 'attachment; filename="' . $certificate->domain->name . '.csr"');
            case 'key':
                if (!request()->isMethod('post')) {
                    abort(405, 'Method Not Allowed. Please use POST to transmit sensitive data.');
                }
                $password = request('password');
                if (!$password || !$certificate->pfx_password) {
                    return response()->json(['error' => 'Password required or no PFX generated yet.'], 403);
                }
                
                if ($password !== decrypt($certificate->pfx_password)) {
                    return response()->json(['error' => 'Invalid PFX password.'], 403);
                }

                AuditLog::log('key_download', "Downloaded private key for domain: {$certificate->domain->name}");
                return response(decrypt($certificate->private_key))
                    ->header('Content-Type', 'application/x-pem-file')
                    ->header('Content-Disposition', 'attachment; filename="' . $certificate->domain->name . '.key"');
        }
    }
}
