<?php

namespace Tests\Unit;

use App\Models\Certificate;
use App\Models\Domain;
use App\Services\AcmeService;
use App\Services\CertificateService;
use Exception;
use Tests\TestCase;

class AcmeServiceTest extends TestCase
{
    private AcmeService $acmeService;
    private CertificateService $certService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acmeService = new AcmeService();
        $this->certService = new CertificateService();
    }

    private function createCsr(string $cn, array $sans = []): string
    {
        $dn = [
            'commonName' => $cn,
            'countryName' => 'NL',
            'organizationName' => 'Test Org',
        ];
        $res = $this->certService->generateCsr($dn, $sans);
        return $res['csr'];
    }

    public function test_resolves_dv_single_domain_url()
    {
        $csr = $this->createCsr('example.com');
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => 'dv',
        ]);

        $settings = [
            'acme_url_dv' => 'https://acme.example.com/dv',
            'acme_url_san' => 'https://acme.example.com/dv-san',
            'acme_url_wildcard' => 'https://acme.example.com/dv-wildcard',
        ];

        $url = $this->acmeService->resolveDirectoryUrl($cert, $settings);
        $this->assertEquals('https://acme.example.com/dv', $url);
    }

    public function test_resolves_dv_san_url()
    {
        $csr = $this->createCsr('example.com', ['sub.example.com', 'other.com']);
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => 'dv',
        ]);

        $settings = [
            'acme_url_dv' => 'https://acme.example.com/dv',
            'acme_url_san' => 'https://acme.example.com/dv-san',
            'acme_url_wildcard' => 'https://acme.example.com/dv-wildcard',
        ];

        $url = $this->acmeService->resolveDirectoryUrl($cert, $settings);
        $this->assertEquals('https://acme.example.com/dv-san', $url);
    }

    public function test_resolves_dv_wildcard_url()
    {
        $csr = $this->createCsr('*.example.com');
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => 'dv',
        ]);

        $settings = [
            'acme_url_dv' => 'https://acme.example.com/dv',
            'acme_url_san' => 'https://acme.example.com/dv-san',
            'acme_url_wildcard' => 'https://acme.example.com/dv-wildcard',
        ];

        $url = $this->acmeService->resolveDirectoryUrl($cert, $settings);
        $this->assertEquals('https://acme.example.com/dv-wildcard', $url);
    }

    public function test_resolves_ov_single_domain_url()
    {
        $csr = $this->createCsr('example.com');
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => 'ov',
        ]);

        $settings = [
            'acme_url_ov' => 'https://acme.example.com/ov',
            'acme_url_ov_san' => 'https://acme.example.com/ov-san',
            'acme_url_ov_wildcard' => 'https://acme.example.com/ov-wildcard',
        ];

        $url = $this->acmeService->resolveDirectoryUrl($cert, $settings);
        $this->assertEquals('https://acme.example.com/ov', $url);
    }

    public function test_resolves_ov_san_url()
    {
        $csr = $this->createCsr('example.com', ['app.example.com']);
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => 'ov',
        ]);

        $settings = [
            'acme_url_ov' => 'https://acme.example.com/ov',
            'acme_url_ov_san' => 'https://acme.example.com/ov-san',
            'acme_url_ov_wildcard' => 'https://acme.example.com/ov-wildcard',
        ];

        $url = $this->acmeService->resolveDirectoryUrl($cert, $settings);
        $this->assertEquals('https://acme.example.com/ov-san', $url);
    }

    public function test_resolves_ov_wildcard_url()
    {
        $csr = $this->createCsr('*.example.com');
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => 'ov',
        ]);

        $settings = [
            'acme_url_ov' => 'https://acme.example.com/ov',
            'acme_url_ov_san' => 'https://acme.example.com/ov-san',
            'acme_url_ov_wildcard' => 'https://acme.example.com/ov-wildcard',
        ];

        $url = $this->acmeService->resolveDirectoryUrl($cert, $settings);
        $this->assertEquals('https://acme.example.com/ov-wildcard', $url);
    }

    public function test_defaults_to_dv_when_acme_type_is_null()
    {
        $csr = $this->createCsr('example.com');
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => null,
        ]);

        $settings = [
            'acme_url_dv' => 'https://acme.example.com/dv',
        ];

        $url = $this->acmeService->resolveDirectoryUrl($cert, $settings);
        $this->assertEquals('https://acme.example.com/dv', $url);
    }

    public function test_throws_exception_when_configured_url_is_missing()
    {
        $csr = $this->createCsr('example.com');
        $cert = new Certificate([
            'csr' => $csr,
            'acme_type' => 'ov',
        ]);

        $settings = [
            'acme_url_dv' => 'https://acme.example.com/dv',
            // acme_url_ov is missing
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ACME OV Directory URL not configured in Settings.');

        $this->acmeService->resolveDirectoryUrl($cert, $settings);
    }
}
