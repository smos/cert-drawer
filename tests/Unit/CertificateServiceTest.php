<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\CertificateService;

class CertificateServiceTest extends TestCase
{
    protected $certService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->certService = new CertificateService();
    }

    public function test_it_recognizes_valid_standard_csr()
    {
        $dn = ['commonName' => 'test.local'];
        $res = $this->certService->generateCsr($dn);
        $csr = $res['csr'];

        $this->assertTrue($this->certService->isValidCsr($csr));
        $this->assertStringContainsString('-----BEGIN CERTIFICATE REQUEST-----', $this->certService->ensureCsrPem($csr));
    }

    public function test_it_recognizes_valid_new_header_csr()
    {
        $dn = ['commonName' => 'test.local'];
        $res = $this->certService->generateCsr($dn);
        $csr = str_replace('CERTIFICATE REQUEST', 'NEW CERTIFICATE REQUEST', $res['csr']);

        $this->assertTrue($this->certService->isValidCsr($csr));
        $this->assertStringContainsString('-----BEGIN NEW CERTIFICATE REQUEST-----', $this->certService->ensureCsrPem($csr));
    }

    public function test_it_detects_invalid_csr()
    {
        $invalidCsr = "-----BEGIN CERTIFICATE REQUEST-----\nInvalidData\n-----END CERTIFICATE REQUEST-----";
        $this->assertFalse($this->certService->isValidCsr($invalidCsr));
    }

    public function test_it_detects_double_wrapped_csr()
    {
        $dn = ['commonName' => 'test.local'];
        $res = $this->certService->generateCsr($dn);
        $csr = $res['csr'];
        
        $doubleWrapped = "-----BEGIN CERTIFICATE REQUEST-----\n" . base64_encode($csr) . "\n-----END CERTIFICATE REQUEST-----";
        
        // This should fail isValidCsr due to substr_count check or openssl failure
        $this->assertFalse($this->certService->isValidCsr($doubleWrapped));
    }

    public function test_extract_csr_base64()
    {
        $dn = ['commonName' => 'test.local'];
        $res = $this->certService->generateCsr($dn);
        $csr = $res['csr'];
        
        $base64 = $this->certService->extractCsrBase64($csr);
        
        $this->assertStringNotContainsString('-----BEGIN', $base64);
        $this->assertStringNotContainsString('-----END', $base64);
        $this->assertStringNotContainsString("\n", $base64);
        
        // Verify we can decode it back to DER
        $der = base64_decode($base64);
        $this->assertNotFalse($der);
    }

    public function test_extract_csr_base64_with_new_header()
    {
        $dn = ['commonName' => 'test.local'];
        $res = $this->certService->generateCsr($dn);
        $csr = str_replace('CERTIFICATE REQUEST', 'NEW CERTIFICATE REQUEST', $res['csr']);
        
        $base64 = $this->certService->extractCsrBase64($csr);
        
        $this->assertStringNotContainsString('-----BEGIN', $base64);
        $this->assertStringNotContainsString('NEW CERTIFICATE REQUEST', $base64);
        
        $der = base64_decode($base64);
        $this->assertNotFalse($der);
    }

    public function test_clean_csr_with_messy_input()
    {
        $dn = ['commonName' => 'test.local'];
        $res = $this->certService->generateCsr($dn);
        $validBase64 = $this->certService->extractCsrBase64($res['csr']);
        
        $messy = "Junk at top\n-----BEGIN CERTIFICATE REQUEST-----\n-----BEGIN NEW CERTIFICATE REQUEST-----\n" . 
                 $validBase64 . "\n-----END NEW CERTIFICATE REQUEST-----\n-----END CERTIFICATE REQUEST-----\nJunk at bottom";
        
        $cleaned = $this->certService->cleanCsr($messy);
        
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE REQUEST-----', $cleaned);
        $this->assertStringEndsWith('-----END CERTIFICATE REQUEST-----', $cleaned);
        $this->assertStringNotContainsString('Junk at top', $cleaned);
        $this->assertStringNotContainsString('NEW CERTIFICATE REQUEST', $cleaned);
        $this->assertTrue($this->certService->isValidCsr($cleaned));
    }

    public function test_extract_csr_base64_with_messy_input()
    {
        $dn = ['commonName' => 'test.local'];
        $res = $this->certService->generateCsr($dn);
        $validBase64 = $this->certService->extractCsrBase64($res['csr']);
        
        $messy = "Junk\n-----BEGIN CERTIFICATE REQUEST-----\n" . $validBase64 . "\n-----END CERTIFICATE REQUEST-----\nMore Junk";
        
        $extracted = $this->certService->extractCsrBase64($messy);
        $this->assertEquals($validBase64, $extracted);
    }
}
