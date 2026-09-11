<?php

namespace Tests\Feature;

use App\Mail\CertHealthReport;
use App\Models\CertHealthLog;
use App\Models\Domain;
use App\Models\Setting;
use App\Services\CertHealthService;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonitorCertMultiIpTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consolidates_changes_across_multiple_ips_for_one_site()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'admin@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);

        $domain = Domain::create([
            'name' => 'accept-zaaksysteem.denhelder.nl',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        $ips = [
            '185.110.174.12',
            '185.110.175.12',
            '2a02:2770:12::1',
            '2a02:2770:12::2',
            '2a02:2770:13::1',
            '2a02:2770:13::2',
        ];

        // Seed old logs for all 6 IPs
        foreach ($ips as $ip) {
            CertHealthLog::forceCreate([
                'domain_id' => $domain->id,
                'check_type' => 'internal',
                'ip_address' => $ip,
                'ip_version' => str_contains($ip, ':') ? 'v6' : 'v4',
                'thumbprint_sha256' => 'old_thumbprint_aaa111',
                'issuer' => 'Old CA Authority',
                'expiry_date' => now()->addDays(30),
                'created_at' => now()->subHours(2),
            ]);
        }

        // Mock CertHealthService to simulate new logs for all 6 IPs with the renewed certificate
        $this->mock(CertHealthService::class, function ($mock) use ($domain, $ips) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function () use ($domain, $ips) {
                    foreach ($ips as $ip) {
                        CertHealthLog::create([
                            'domain_id' => $domain->id,
                            'check_type' => 'internal',
                            'ip_address' => $ip,
                            'ip_version' => str_contains($ip, ':') ? 'v6' : 'v4',
                            'thumbprint_sha256' => 'new_thumbprint_bbb222',
                            'issuer' => 'New CA Authority',
                            'expiry_date' => now()->addDays(395),
                            'created_at' => now(),
                        ]);
                    }
                });
        });

        Artisan::call('cert:monitor');

        // Verify that Mail was sent
        Mail::assertSent(CertHealthReport::class, function ($mail) use ($ips) {
            // There should only be ONE consolidated change entry, not 6
            $this->assertCount(1, $mail->changes);
            
            $change = $mail->changes[0];
            $this->assertEquals('accept-zaaksysteem.denhelder.nl', $change['domain']->name);
            $this->assertEquals('old_thumbprint_aaa111', $change['old']['thumbprint_sha256']);
            $this->assertEquals('new_thumbprint_bbb222', $change['new']['thumbprint_sha256']);
            
            // All 6 IPs should be captured
            $this->assertCount(6, $change['ips']);
            foreach ($ips as $ip) {
                $this->assertContains($ip, $change['ips']);
                $this->assertStringContainsString($ip, $change['ip']);
            }

            // Render email and verify it renders concisely
            $rendered = $mail->render();
            $this->assertStringContainsString('accept-zaaksysteem.denhelder.nl', $rendered);
            $this->assertStringContainsString('6 IP addresses', $rendered);
            $this->assertStringContainsString('old_thumbp', $rendered);
            $this->assertStringContainsString('new_thumbp', $rendered);

            // Domain name header should only appear ONCE in the email
            $this->assertEquals(1, substr_count($rendered, 'class="domain-name">accept-zaaksysteem.denhelder.nl<'));

            return true;
        });
    }

    public function test_it_consolidates_raw_changes_passed_to_cert_health_report_mailable()
    {
        $domain = Domain::create([
            'name' => 'test-site.example.com',
            'is_enabled' => true,
        ]);

        $rawChanges = [
            [
                'domain' => $domain,
                'ip' => '1.1.1.1',
                'old' => ['thumbprint_sha256' => 'old123', 'error' => null],
                'new' => ['thumbprint_sha256' => 'new456', 'error' => null, 'issuer' => 'Test CA', 'expiry_date' => '2027-01-01'],
            ],
            [
                'domain' => $domain,
                'ip' => '1.1.1.2',
                'old' => ['thumbprint_sha256' => 'old123', 'error' => null],
                'new' => ['thumbprint_sha256' => 'new456', 'error' => null, 'issuer' => 'Test CA', 'expiry_date' => '2027-01-01'],
            ],
            [
                'domain' => $domain,
                'ip' => '1.1.1.3',
                'old' => ['thumbprint_sha256' => 'old123', 'error' => null],
                'new' => ['thumbprint_sha256' => 'new456', 'error' => null, 'issuer' => 'Test CA', 'expiry_date' => '2027-01-01'],
            ],
        ];

        $report = new CertHealthReport($rawChanges);

        $this->assertCount(1, $report->changes);
        $this->assertCount(3, $report->changes[0]['ips']);
        $this->assertStringContainsString('1.1.1.1', $report->changes[0]['ip']);
        $this->assertStringContainsString('1.1.1.2', $report->changes[0]['ip']);
        $this->assertStringContainsString('1.1.1.3', $report->changes[0]['ip']);

        $rendered = $report->render();
        $this->assertStringContainsString('3 IP addresses', $rendered);
        $this->assertEquals(1, substr_count($rendered, 'class="domain-name">test-site.example.com<'));
    }

    public function test_webhook_summary_groups_multiple_ips()
    {
        $domain = new Domain(['name' => 'accept-zaaksysteem.denhelder.nl']);
        $changes = [
            [
                'domain' => $domain,
                'ips' => ['1.2.3.4', '1.2.3.5', '1.2.3.6'],
                'ip' => '1.2.3.4, 1.2.3.5, 1.2.3.6',
            ],
        ];

        $service = new WebhookService();
        $method = new \ReflectionMethod(WebhookService::class, 'generateSummary');
        $method->setAccessible(true);
        $summary = $method->invoke($service, $changes, []);

        $this->assertStringContainsString('1 certificate change detected.', $summary);
        $this->assertStringContainsString('**accept-zaaksysteem.denhelder.nl** (3 IPs):', $summary);
    }

    public function test_it_highlights_differing_fingerprint_across_ips_as_mismatch()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'admin@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);

        $domain = Domain::create([
            'name' => 'mismatch-check.denhelder.nl',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        $normalIps = ['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5'];
        $differingIp = '10.0.0.6';

        // Seed old logs
        foreach ($normalIps as $ip) {
            CertHealthLog::forceCreate([
                'domain_id' => $domain->id,
                'check_type' => 'internal',
                'ip_address' => $ip,
                'ip_version' => 'v4',
                'thumbprint_sha256' => 'old_thumbprint_000',
                'expiry_date' => now()->addDays(30),
                'created_at' => now()->subHours(2),
            ]);
        }
        CertHealthLog::forceCreate([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => $differingIp,
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'old_thumbprint_000',
            'expiry_date' => now()->addDays(30),
            'created_at' => now()->subHours(2),
        ]);

        // Simulate 5 IPs updating to thumbprint_AAA, but 1 IP updating to thumbprint_BBB
        $this->mock(CertHealthService::class, function ($mock) use ($domain, $normalIps, $differingIp) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function () use ($domain, $normalIps, $differingIp) {
                    foreach ($normalIps as $ip) {
                        CertHealthLog::create([
                            'domain_id' => $domain->id,
                            'check_type' => 'internal',
                            'ip_address' => $ip,
                            'ip_version' => 'v4',
                            'thumbprint_sha256' => 'thumbprint_AAA',
                            'issuer' => 'Valid CA Authority',
                            'expiry_date' => now()->addDays(365),
                            'created_at' => now(),
                        ]);
                    }
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => $differingIp,
                        'ip_version' => 'v4',
                        'thumbprint_sha256' => 'thumbprint_BBB_DIFFERING',
                        'issuer' => 'Rogue or Outdated CA',
                        'expiry_date' => now()->addDays(90),
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        Mail::assertSent(CertHealthReport::class, function ($mail) use ($differingIp) {
            // Should have 2 separate change entries: 1 for the 5 IPs, 1 for the 1 differing IP
            $this->assertCount(2, $mail->changes);

            $changeThumbs = array_map(fn($c) => $c['new']['thumbprint_sha256'], $mail->changes);
            $this->assertContains('thumbprint_AAA', $changeThumbs);
            $this->assertContains('thumbprint_BBB_DIFFERING', $changeThumbs);

            // Both should be flagged with has_mismatch
            $this->assertTrue($mail->changes[0]['has_mismatch'] ?? false);
            $this->assertTrue($mail->changes[1]['has_mismatch'] ?? false);

            $rendered = $mail->render();
            // Verify prominent warning banner is rendered
            $this->assertStringContainsString('Certificate Mismatch Warning: Differing Fingerprints Detected Across Host IPs!', $rendered);
            $this->assertStringContainsString('⚠️ Differing Cert', $rendered);
            $this->assertStringContainsString('5 IP addresses', $rendered);
            $this->assertStringContainsString("IP: {$differingIp}", $rendered);

            return true;
        });
    }

    public function test_it_does_not_alert_for_expected_differences_between_internal_and_external_poller()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'admin@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);

        $domain = Domain::create([
            'name' => 'outlook.denhelder.nl',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Seed old logs: internal has private PKI, external has public PKI
        CertHealthLog::forceCreate([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '10.30.20.19',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'thumbprint_INTERNAL_PRIVATE_PKI',
            'issuer' => 'Gemeente Den Helder Issuing CA - G1',
            'expiry_date' => now()->addDays(200),
            'created_at' => now()->subHours(2),
        ]);

        CertHealthLog::forceCreate([
            'domain_id' => $domain->id,
            'check_type' => 'external',
            'ip_address' => '91.213.53.40',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'thumbprint_EXTERNAL_PUBLIC_PKI',
            'issuer' => 'Trust Provider B.V. TLS RSA CA G1',
            'expiry_date' => now()->addDays(300),
            'created_at' => now()->subHours(2),
        ]);

        CertHealthLog::forceCreate([
            'domain_id' => $domain->id,
            'check_type' => 'external',
            'ip_address' => '2a07:3500:13b8:d032::40',
            'ip_version' => 'v6',
            'thumbprint_sha256' => 'thumbprint_EXTERNAL_PUBLIC_PKI',
            'issuer' => 'Trust Provider B.V. TLS RSA CA G1',
            'expiry_date' => now()->addDays(300),
            'created_at' => now()->subHours(2),
        ]);

        // Scenario 1: Hourly check runs with identical certificates (no changes)
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function () use ($domain) {
                    // Internal check reproduces the same internal PKI cert
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '10.30.20.19',
                        'ip_version' => 'v4',
                        'thumbprint_sha256' => 'thumbprint_INTERNAL_PRIVATE_PKI',
                        'issuer' => 'Gemeente Den Helder Issuing CA - G1',
                        'expiry_date' => now()->addDays(200),
                        'created_at' => now(),
                    ]);
                    // External check reproduces the same external public PKI cert
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'external',
                        'ip_address' => '91.213.53.40',
                        'ip_version' => 'v4',
                        'thumbprint_sha256' => 'thumbprint_EXTERNAL_PUBLIC_PKI',
                        'issuer' => 'Trust Provider B.V. TLS RSA CA G1',
                        'expiry_date' => now()->addDays(300),
                        'created_at' => now(),
                    ]);
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'external',
                        'ip_address' => '2a07:3500:13b8:d032::40',
                        'ip_version' => 'v6',
                        'thumbprint_sha256' => 'thumbprint_EXTERNAL_PUBLIC_PKI',
                        'issuer' => 'Trust Provider B.V. TLS RSA CA G1',
                        'expiry_date' => now()->addDays(300),
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Mail must NOT be sent! The difference between internal and external is expected and should not trigger an alert.
        Mail::assertNotSent(CertHealthReport::class);
    }
}
