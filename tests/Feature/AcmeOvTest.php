<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\Services\AcmeService;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AcmeOvTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_initiate_acme_ov_request()
    {
        $domain = Domain::create(['name' => 'example.com', 'is_enabled' => true]);
        $cert = $domain->certificates()->create([
            'request_type' => 'unknown',
            'status' => 'requested',
            'csr' => 'dummy-csr',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('certificates.acme-request', $cert), [
            'acme_type' => 'ov',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Ready to verify via ACME (OV) service.',
        ]);

        $cert->refresh();
        $this->assertEquals('acme', $cert->request_type);
        $this->assertEquals('ov', $cert->acme_type);
        $this->assertEquals('pending_verification', $cert->status);
    }

    public function test_initiate_acme_defaults_to_dv()
    {
        $domain = Domain::create(['name' => 'example.com', 'is_enabled' => true]);
        $cert = $domain->certificates()->create([
            'request_type' => 'unknown',
            'status' => 'requested',
            'csr' => 'dummy-csr',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('certificates.acme-request', $cert), []);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Ready to verify via ACME (DV) service.',
        ]);

        $cert->refresh();
        $this->assertEquals('acme', $cert->request_type);
        $this->assertEquals('dv', $cert->acme_type);
    }

    public function test_certificate_show_endpoint_returns_acme_type()
    {
        $domain = Domain::create(['name' => 'example.com', 'is_enabled' => true]);
        $cert = $domain->certificates()->create([
            'request_type' => 'acme',
            'acme_type' => 'ov',
            'status' => 'issued',
        ]);

        $response = $this->actingAs($this->user)->getJson(route('certificates.show', $cert));

        $response->assertOk();
        $this->assertEquals('ov', $response->json('certificate.acme_type'));
        $this->assertEquals('ov', $response->json('certificate.effective_acme_type'));
    }

    public function test_renew_acme_command_preserves_ov_validation_type()
    {
        Setting::updateOrCreate(['key' => 'acme_renewal_days'], ['value' => '30']);
        
        $domain = Domain::create(['name' => 'ov-domain.com', 'is_enabled' => true]);
        
        $certService = app(CertificateService::class);
        $pair = $certService->generateSelfSignedCert(['commonName' => $domain->name]);

        $cert = $domain->certificates()->create([
            'request_type' => 'acme',
            'acme_type' => 'ov',
            'status' => 'issued',
            'certificate' => $pair['certificate'],
            'csr' => 'dummy-csr',
            'expiry_date' => now()->addDays(5), // due for renewal
        ]);

        // Mock AcmeService to avoid real ACME network calls
        $mockAcme = Mockery::mock(AcmeService::class);
        $mockAcme->shouldReceive('issueCertificate')
            ->once()
            ->withArgs(function ($newCert) {
                // Ensure new certificate created has acme_type = 'ov'
                return $newCert->acme_type === 'ov' && $newCert->request_type === 'acme';
            })
            ->andReturn(true);
        $this->app->instance(AcmeService::class, $mockAcme);

        $this->artisan('certificates:renew-acme')
            ->expectsOutputToContain('Successfully renewed ACME (OV) certificate for ov-domain.com')
            ->assertExitCode(0);

        // Verify the latest certificate on the domain is an OV certificate
        $latestCert = $domain->certificates()->whereNull('archived_at')->latest('id')->first();
        $this->assertNotNull($latestCert);
        $this->assertEquals('ov', $latestCert->acme_type);
        $this->assertEquals('acme', $latestCert->request_type);
    }

    public function test_renew_domain_command_preserves_acme_type_and_supports_cli_override()
    {
        Setting::updateOrCreate(['key' => 'acme_renewal_days'], ['value' => '30']);
        
        $domain = Domain::create(['name' => 'dv-domain.com', 'is_enabled' => true]);
        
        $certService = app(CertificateService::class);
        $pair = $certService->generateSelfSignedCert(['commonName' => $domain->name]);

        $cert = $domain->certificates()->create([
            'request_type' => 'acme',
            'acme_type' => 'dv',
            'status' => 'issued',
            'certificate' => $pair['certificate'],
            'csr' => 'dummy-csr',
            'expiry_date' => now()->addDays(5),
        ]);

        // Mock AcmeService
        $mockAcme = Mockery::mock(AcmeService::class);
        $mockAcme->shouldReceive('issueCertificate')
            ->once()
            ->withArgs(function ($newCert) {
                return $newCert->acme_type === 'ov'; // Overridden via CLI
            })
            ->andReturn(true);
        $this->app->instance(AcmeService::class, $mockAcme);

        $this->artisan('certificates:renew-domain dv-domain.com --acme-type=ov')
            ->expectsOutputToContain('Successfully renewed ACME (OV) certificate for dv-domain.com')
            ->assertExitCode(0);

        $latestCert = $domain->certificates()->latest('id')->first();
        $this->assertEquals('ov', $latestCert->acme_type);
    }

    public function test_settings_can_save_acme_ov_urls()
    {
        $response = $this->actingAs($this->user)->withoutMiddleware()->post(route('settings.update'), [
            'acme_url_ov' => 'https://acme.example.com/custom-ov',
            'acme_url_ov_san' => 'https://acme.example.com/custom-ov-san',
            'acme_url_ov_wildcard' => 'https://acme.example.com/custom-ov-wildcard',
        ]);

        $response->assertRedirect(route('settings.index'));

        $this->assertEquals('https://acme.example.com/custom-ov', Setting::where('key', 'acme_url_ov')->value('value'));
        $this->assertEquals('https://acme.example.com/custom-ov-san', Setting::where('key', 'acme_url_ov_san')->value('value'));
        $this->assertEquals('https://acme.example.com/custom-ov-wildcard', Setting::where('key', 'acme_url_ov_wildcard')->value('value'));
    }
}
