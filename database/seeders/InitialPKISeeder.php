<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Services\CertificateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InitialPKISeeder extends Seeder
{
    public function run(): void
    {
        $service = new CertificateService();

        $dnBase = [
            'countryName' => 'NL',
            'stateOrProvinceName' => 'State',
            'localityName' => 'Locality',
            'organizationName' => 'Organization',
            'organizationalUnitName' => 'IT Department',
        ];

        // 1. Generate Root CA
        $rootName = 'Certkast Root CA';
        $rootDomain = Domain::updateOrCreate(['name' => $rootName], ['is_enabled' => true]);

        if (!DB::table('certificates')->where('domain_id', $rootDomain->id)->where('status', 'issued')->exists()) {
            $rootDn = array_merge($dnBase, ['commonName' => $rootName]);
            $rootData = $service->generateSelfSignedCert($rootDn);
            
            DB::table('certificates')->insert([
                'domain_id' => $rootDomain->id,
                'request_type' => 'manual',
                'certificate' => $rootData['certificate'],
                'private_key' => encrypt($rootData['private_key']),
                'issuer' => $rootName,
                'expiry_date' => now()->addYears(10)->toDateTimeString(),
                'status' => 'issued',
                'is_ca' => 1,
                'thumbprint_sha1' => $service->extractThumbprint($rootData['certificate'], 'sha1'),
                'thumbprint_sha256' => $service->extractThumbprint($rootData['certificate'], 'sha256'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rootCert = DB::table('certificates')->where('domain_id', $rootDomain->id)->first();
        $rootPrivKey = decrypt($rootCert->private_key);

        // 2. Generate Intermediate CA
        $interName = 'Certkast Intermediate CA';
        $interDomain = Domain::updateOrCreate(['name' => $interName], ['is_enabled' => true]);

        if (!DB::table('certificates')->where('domain_id', $interDomain->id)->where('status', 'issued')->exists()) {
            $interDn = array_merge($dnBase, ['commonName' => $interName]);
            $interCsr = $service->generateCsr($interDn);
            $interCertPem = $service->signCsr($interCsr['csr'], $rootCert->certificate, $rootPrivKey, 3650, true);
            
            DB::table('certificates')->insert([
                'domain_id' => $interDomain->id,
                'request_type' => 'manual',
                'csr' => $interCsr['csr'],
                'certificate' => $interCertPem,
                'private_key' => encrypt($interCsr['private_key']),
                'issuer' => $rootName,
                'expiry_date' => now()->addYears(10)->toDateTimeString(),
                'status' => 'issued',
                'is_ca' => 1,
                'thumbprint_sha1' => $service->extractThumbprint($interCertPem, 'sha1'),
                'thumbprint_sha256' => $service->extractThumbprint($interCertPem, 'sha256'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $interCert = DB::table('certificates')->where('domain_id', $interDomain->id)->first();
        $interPrivKey = decrypt($interCert->private_key);

        // 3. Generate Host Certificate
        $host = 'domain.local';
        $hostDomain = Domain::updateOrCreate(['name' => $host], ['is_enabled' => true]);

        if (!DB::table('certificates')->where('domain_id', $hostDomain->id)->where('status', 'issued')->exists()) {
            $hostDn = array_merge($dnBase, ['commonName' => $host]);
            $hostCsr = $service->generateCsr($hostDn, [$host]);
            $hostCertPem = $service->signCsr($hostCsr['csr'], $interCert->certificate, $interPrivKey, 365, false, [$host]);

            DB::table('certificates')->insert([
                'domain_id' => $hostDomain->id,
                'request_type' => 'manual',
                'csr' => $hostCsr['csr'],
                'certificate' => $hostCertPem,
                'private_key' => encrypt($hostCsr['private_key']),
                'issuer' => $interName,
                'expiry_date' => now()->addYear()->toDateTimeString(),
                'status' => 'issued',
                'is_ca' => 0,
                'thumbprint_sha1' => $service->extractThumbprint($hostCertPem, 'sha1'),
                'thumbprint_sha256' => $service->extractThumbprint($hostCertPem, 'sha256'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
