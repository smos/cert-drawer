<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityPermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audits_clean_when_all_permissions_are_secure(): void
    {
        $this->artisan('security:permissions')
            ->expectsOutputToContain('All audited sensitive file and directory permissions are secure.')
            ->assertSuccessful();
    }

    public function test_detects_insecure_permissions_and_fails_in_check_mode(): void
    {
        $privateDir = storage_path('app/private');
        if (!is_dir($privateDir)) {
            mkdir($privateDir, 0700, true);
        }

        $dummyKey = $privateDir . '/test_insecure_dummy.key';
        file_put_contents($dummyKey, 'test-content');
        chmod($dummyKey, 0666);

        try {
            $this->artisan('security:permissions')
                ->expectsOutputToContain('Insecure')
                ->expectsOutputToContain("Run with '--fix'")
                ->assertFailed();
        } finally {
            if (file_exists($dummyKey)) {
                @unlink($dummyKey);
            }
        }
    }

    public function test_fixes_insecure_permissions_and_logs_to_audit_trail(): void
    {
        $privateDir = storage_path('app/private');
        if (!is_dir($privateDir)) {
            mkdir($privateDir, 0700, true);
        }

        $dummyPem = $privateDir . '/test_remediation_dummy.pem';
        file_put_contents($dummyPem, 'test-pem-content');
        chmod($dummyPem, 0644);

        try {
            $this->artisan('security:permissions', ['--fix' => true])
                ->expectsOutputToContain('Fixed')
                ->expectsOutputToContain('Successfully fixed permissions')
                ->assertSuccessful();

            // Verify permission was tightened to 0600
            clearstatcache(true, $dummyPem);
            $mode = sprintf('%04o', fileperms($dummyPem) & 0777);
            $this->assertEquals('0600', $mode);

            // Verify AuditLog was recorded
            $log = AuditLog::where('action', 'security_permissions_fix')->latest()->first();
            $this->assertNotNull($log, 'AuditLog entry should exist for security_permissions_fix.');
            $this->assertStringContainsString('Corrected file permissions', $log->description);
            $this->assertGreaterThanOrEqual(1, $log->metadata['fixed_count'] ?? 0);
        } finally {
            if (file_exists($dummyPem)) {
                @unlink($dummyPem);
            }
        }
    }

    public function test_does_not_create_duplicate_audit_log_when_already_secure(): void
    {
        $initialCount = AuditLog::where('action', 'security_permissions_fix')->count();

        $this->artisan('security:permissions', ['--fix' => true])
            ->expectsOutputToContain('All audited sensitive file and directory permissions are secure.')
            ->assertSuccessful();

        $this->assertEquals($initialCount, AuditLog::where('action', 'security_permissions_fix')->count());
    }

    public function test_audit_logs_security_category_filter(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.internal',
        ]);

        AuditLog::create([
            'action' => 'security_permissions_fix',
            'description' => 'Corrected file permissions on test paths',
            'ip_address' => '127.0.0.1',
            'metadata' => ['fixed_count' => 1],
        ]);

        AuditLog::create([
            'action' => 'domain_create',
            'description' => 'Added domain test.example.internal',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->actingAs($user)->get(route('audit.index', ['category' => 'security']));

        $response->assertOk();
        $response->assertSee('Corrected file permissions on test paths');
        $response->assertDontSee('Added domain test.example.internal');
    }
}
