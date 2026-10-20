<?php

namespace Tests\Unit;

use App\Models\Certificate;
use App\Models\Domain;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected CertificateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CertificateService::class);
    }

    public function test_rejects_loopback_urls(): void
    {
        $this->assertFalse($this->service->isSafePublicUrl('http://127.0.0.1/ca.crt'));
        $this->assertFalse($this->service->isSafePublicUrl('http://127.0.0.1:8080/test'));
        $this->assertFalse($this->service->isSafePublicUrl('http://localhost/ca.crt'));
        $this->assertFalse($this->service->isSafePublicUrl('http://sub.localhost/ca.crt'));
    }

    public function test_rejects_cloud_metadata_service_urls(): void
    {
        $this->assertFalse($this->service->isSafePublicUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse($this->service->isSafePublicUrl('http://metadata.google.internal/computeMetadata/v1/'));
    }

    public function test_rejects_private_rfc1918_addresses(): void
    {
        $this->assertFalse($this->service->isSafePublicUrl('http://10.0.0.1/ca.crt'));
        $this->assertFalse($this->service->isSafePublicUrl('http://10.254.1.1:8000/cert.der'));
        $this->assertFalse($this->service->isSafePublicUrl('http://172.16.0.1/ca.crt'));
        $this->assertFalse($this->service->isSafePublicUrl('http://192.168.1.1/ca.crt'));
        $this->assertFalse($this->service->isSafePublicUrl('http://192.168.0.254/cert.der'));
    }

    public function test_rejects_invalid_schemes(): void
    {
        $this->assertFalse($this->service->isSafePublicUrl('file:///etc/passwd'));
        $this->assertFalse($this->service->isSafePublicUrl('gopher://127.0.0.1:6379/_flushall'));
        $this->assertFalse($this->service->isSafePublicUrl('dict://127.0.0.1:11211/'));
        $this->assertFalse($this->service->isSafePublicUrl('ftp://example.com/cert.cer'));
        $this->assertFalse($this->service->isSafePublicUrl(''));
        $this->assertFalse($this->service->isSafePublicUrl('not-a-url'));
    }

    public function test_allows_public_ip_and_domains(): void
    {
        // Public DNS IP
        $this->assertTrue($this->service->isSafePublicUrl('http://8.8.8.8/cert.crt'));
        $this->assertTrue($this->service->isSafePublicUrl('https://1.1.1.1/cert.crt'));
    }

    public function test_download_and_import_issuer_prevents_ssrf_requests(): void
    {
        Http::fake();

        $domain = Domain::create(['name' => 'ssrf-test.local']);

        // Mock a certificate whose extractAiaUrl returns an internal metadata URL
        $cert = Certificate::create([
            'domain_id' => $domain->id,
            'certificate' => 'dummy-cert',
            'status' => 'issued',
            'request_type' => 'manual',
        ]);

        $mockService = $this->getMockBuilder(CertificateService::class)
            ->onlyMethods(['extractAiaUrl'])
            ->getMock();

        $mockService->method('extractAiaUrl')
            ->willReturn('http://169.254.169.254/latest/meta-data/');

        $result = $mockService->downloadAndImportIssuer($cert);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_allows_configured_internal_pki_endpoint_host(): void
    {
        // Use a generic private IP to test that the configured ADCS endpoint is permitted
        \App\Models\Setting::updateOrCreate(
            ['key' => 'adcs_endpoint'],
            ['value' => 'https://10.20.30.40/certsrv']
        );

        $this->assertTrue($this->service->isSafePublicUrl('http://10.20.30.40/CertEnroll/ca.crt'));
        $this->assertTrue($this->service->isSafePublicUrl('https://10.20.30.40/pki/RootCA.crt'));

        // Any other unconfigured internal private IP must still be blocked
        $this->assertFalse($this->service->isSafePublicUrl('http://10.0.0.1/ca.crt'));
    }

    public function test_allows_configured_internal_pki_hostname_with_private_resolution(): void
    {
        // Use an RFC 2606 reserved example domain for testing
        \App\Models\Setting::updateOrCreate(
            ['key' => 'adcs_endpoint'],
            ['value' => 'https://pki.example.internal/certsrv']
        );

        $mockService = $this->getMockBuilder(CertificateService::class)
            ->onlyMethods(['resolveHostIps'])
            ->getMock();

        $mockService->method('resolveHostIps')
            ->willReturnMap([
                ['pki.example.internal', ['10.20.30.40']],
                ['untrusted.example.internal', ['10.20.30.50']],
                ['metadata.example.internal', ['169.254.169.254']],
            ]);

        // Allowed: configured PKI host resolving to private IP
        $this->assertTrue($mockService->isSafePublicUrl('http://pki.example.internal/CertEnroll/ca.crt'));

        // Blocked: unconfigured host resolving to private IP
        $this->assertFalse($mockService->isSafePublicUrl('http://untrusted.example.internal/ca.crt'));

        // Blocked: even if configured, metadata IP is strictly rejected
        \App\Models\Setting::updateOrCreate(
            ['key' => 'adcs_endpoint'],
            ['value' => 'https://metadata.example.internal/certsrv']
        );
        $this->assertFalse($mockService->isSafePublicUrl('http://metadata.example.internal/latest/meta-data/'));
    }
}
