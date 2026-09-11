<?php

namespace Tests\Feature;

use App\Models\CertHealthLog;
use App\Models\Domain;
use App\Models\Setting;
use App\Services\CertHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;
use App\Mail\CertHealthReport;
use Tests\TestCase;

class MonitorCertTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ignores_timeout_changes()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Old success log
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'abc123success',
            'expiry_date' => now()->addDays(60),
            'created_at' => now()->subHours(2),
        ]);

        // Mock CertHealthService to simulate a timeout error
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'error' => 'Connection failed: Connection timed out (110)',
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Mail should NOT be sent because it's a timeout
        Mail::assertNotSent(CertHealthReport::class);
    }

    public function test_it_alerts_on_real_changes()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Old success log
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'abc123old',
            'expiry_date' => now()->addDays(60),
            'created_at' => now()->subHours(2),
        ]);

        // Mock CertHealthService to simulate a real change
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'thumbprint_sha256' => 'def456new',
                        'expiry_date' => now()->addDays(60),
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Mail SHOULD be sent because thumbprint changed
        Mail::assertSent(CertHealthReport::class, function ($mail) {
            return count($mail->changes) === 1 && $mail->changes[0]['new']['thumbprint_sha256'] === 'def456new';
        });
    }

    public function test_it_alerts_on_other_errors()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);
        Setting::create(['key' => 'notify_connection_issues', 'value' => '1']);

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Old success log
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'abc123success',
            'expiry_date' => now()->addDays(60),
            'created_at' => now()->subHours(2),
        ]);

        // Mock CertHealthService to simulate a different error (e.g. Connection Refused)
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'error' => 'Connection failed: Connection refused (111)',
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Mail SHOULD be sent because notify_connection_issues is enabled
        Mail::assertSent(CertHealthReport::class);
    }

    public function test_it_suppresses_connection_errors_by_default()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);
        // notify_connection_issues is NOT enabled (default)

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Old success log
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'abc123success',
            'expiry_date' => now()->addDays(60),
            'created_at' => now()->subHours(2),
        ]);

        // Simulate Connection Refused
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'error' => 'Connection failed: Connection refused (111)',
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Mail should NOT be sent because notify_connection_issues is disabled by default
        Mail::assertNotSent(CertHealthReport::class);
    }

    public function test_it_suppresses_recovery_from_connection_error_by_default()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);
        // notify_connection_issues is NOT enabled (default)

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Older healthy log
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'abc123success',
            'expiry_date' => now()->addDays(60),
            'created_at' => now()->subHours(4),
        ]);

        // Immediate previous check had an error (e.g. connection refused)
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'error' => 'Connection failed: Connection refused (111)',
            'created_at' => now()->subHours(2),
        ]);

        // Simulate host recovered with same certificate
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'thumbprint_sha256' => 'abc123success',
                        'expiry_date' => now()->addDays(60),
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Recovery alert should be suppressed because notify_connection_issues is off and cert did not change
        Mail::assertNotSent(CertHealthReport::class);
    }

    public function test_it_alerts_on_real_cert_change_after_connection_error_even_when_suppressed()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);
        // notify_connection_issues is disabled

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Older healthy log with old cert
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'thumbprint_sha256' => 'old_thumbprint_111',
            'expiry_date' => now()->addDays(30),
            'created_at' => now()->subHours(4),
        ]);

        // Immediate previous check had an error
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'error' => 'Connection failed: Connection reset by peer (104)',
            'created_at' => now()->subHours(2),
        ]);

        // Simulate host recovered with NEW renewed certificate
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'thumbprint_sha256' => 'new_thumbprint_222',
                        'expiry_date' => now()->addDays(395),
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Mail SHOULD be sent because the certificate thumbprint actually changed from old to new!
        Mail::assertSent(CertHealthReport::class, function ($mail) {
            return count($mail->changes) === 1 
                && $mail->changes[0]['old']['thumbprint_sha256'] === 'old_thumbprint_111'
                && $mail->changes[0]['new']['thumbprint_sha256'] === 'new_thumbprint_222';
        });
    }

    public function test_it_ignores_recovery_from_timeout()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Old timeout log
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'error' => 'Connection failed: Connection timed out (110)',
            'created_at' => now()->subHours(2),
        ]);

        // Mock CertHealthService to simulate a success
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'thumbprint_sha256' => 'abc123success',
                        'expiry_date' => now()->addDays(60),
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        // Mail should NOT be sent because transition from timeout is ignored
        Mail::assertNotSent(CertHealthReport::class);
    }

    public function test_it_ignores_different_timeout_messages()
    {
        Mail::fake();

        Setting::create(['key' => 'cert_mail_recipients', 'value' => 'test@example.com']);
        Setting::create(['key' => 'dns_check_interval', 'value' => 1]);

        $domain = Domain::create([
            'name' => 'example.com',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Old timeout log
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'error' => 'Connection failed: Connection timed out (110)',
            'created_at' => now()->subHours(2),
        ]);

        // Mock CertHealthService to simulate another timeout with different message
        $this->mock(CertHealthService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('monitorDomain')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function() use ($domain) {
                    CertHealthLog::create([
                        'domain_id' => $domain->id,
                        'check_type' => 'internal',
                        'ip_address' => '1.2.3.4',
                        'ip_version' => 'v4',
                        'error' => 'Connection failed: Request timed out (110)',
                        'created_at' => now(),
                    ]);
                });
        });

        Artisan::call('cert:monitor');

        Mail::assertNotSent(CertHealthReport::class);
    }
}
