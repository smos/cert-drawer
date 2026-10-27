<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Domain;
use App\Models\User;
use App\Models\Certificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;
use Mockery;
use App\Services\KempService;

class AutomationTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_check_certificate_returns_success_even_with_missing_chain()
    {
        $this->withoutExceptionHandling();
        $domain = Domain::create(['name' => 'test.com']);
        
        $validCert = "-----BEGIN CERTIFICATE-----
MIIHczCCBlugAwIBAgIQCK0zMMsdseutzFHFrR9PeTANBgkqhkiG9w0BAQsFADB2
MQswCQYDVQQGEwJOTDEcMBoGA1UEChMTVHJ1c3QgUHJvdmlkZXIgQi5WLjEdMBsG
A1UECxMURG9tYWluIFZhbGlkYXRlZCBTU0wxKjAoBgNVBAMTIVRydXN0IFByb3Zp
ZGVyIEIuVi4gVExTIFJTQSBDQSBHMTAeFw0yNTA1MTIwMDAwMDBaFw0yNjA2MDYy
MzU5NTlaMBwxGjAYBgNVBAMTEWFwcHMuZGVuaGVsZGVyLm5sMIICIjANBgkqhkiG
9w0BAQEFAAOCAg8AMIICCgKCAgEA3hhvkj9CxmtCdlztSlPLuQviXE30dD1cIycN
huwXmEJroqZ2fpNf871PmyoHMFY49pyOUfeaOhiJrqr/f2fWaEz418/JxzuxCS3M
z5Ifqf/9/jYcFzM0RxMZvOCtWXlQNV/7nueGKqHmkVkuvORQiVfKSOk3d9LiBV6G
fXhN9XOgXCi/nQEnbSmvxTT5OOe+WqvlCG4PKl5XpFqzfwAgiySjGzwXpBt2Buqg
FDXhvdGU8yBaqW9e1EgiXiDnppSjUuNYZnud0LjnxR5hfLYYa/+x9EMzUY3496zY
WS6TJ/3+KYA31YS1jq4a27xgaSTUvuL+9pk9Ik4FLQbzhdwvMMpeTgqI89jyFNes
CMQXOurQz7+z/3y0+PM2/KQJQaK6NqPq4paH0FeOUCcxaYbWjTt5y+EkKKyABPzQ
JnyMySjoOefdE77U0SSRQQzOOUmghtLzkJC/gVJWqUeOBARJibb23QZ5IvpsO3aY
AGK4bH0WKodpr/2M6MAa6fFLttaYsnqAvL49E68A6ccgFZc+soFFoUZ5PDhqvwNY
WKumzOWvePT6XzunTXSA5id0R07KndzHJMC/zy3M2wniWEGu+IkSSmTqN6eQeilR
8yPQdTvixEhq4Vxbx8fXNrs77AuPAVztr62NgtkVZ0DvWUIavLOMFE6LaVzBTcBZ
OdlQLt0CAwEAAaOCA1UwggNRMB8GA1UdIwQYMBaAFPVWIh/Zv2tZJFKw4WrNwOFX
Z+noMB0GA1UdDgQWBBQeAAXK3LN6MZMgcgp07nVQQHcjjjAcBgNVHREEFTATghFh
cHBzLmRlbmhlbGRlci5ubDA+BgNVHSAENzA1MDMGBmeBDAECATApMCcGCCsGAQUF
BwIBFhtodHRwOi8vd3d3LmRpZ2ljZXJ0LmNvbS9DUFMwDgYDVR0PAQH/BAQDAgWg
MB0GA1UdJQQWMBQGCCsGAQUFBwMBBggrBgEFBQcDAjBUBgNVHR8ETTBLMEmgR6BF
hkNodHRwOi8vY2RwZC5kaWdpdGFsY2VydHZhbGlkYXRpb24uY29tL1RydXN0UHJv
dmlkZXJCVlRMU1JTQUNBRzEuY3JsMIGaBggrBgEFBQcBAQSBjTCBijA0BggrBgEF
BQcwAYYoaHR0cDovL3N0YXR1c2QuZGlnaXRhbGNlcnR2YWxpZGF0aW9uLmNvbTBS
BggrBgEFBQcwAoZGaHR0cDovL2NhY2VydHMuZGlnaXRhbGNlcnR2YWxpZGF0aW9u
LmNvbS9UcnVzdFByb3ZpZGVyQlZUTFNSU0FDQUcxLmNydDAMBgNVHRMBAf8EAjAA
MIIBfwYKKwYBBAHWeQIEAgSCAW8EggFrAWkAdgAOV5S8866pPjMbLJkHs/eQ35vC
PXEyJd0hqSWsYcVOIQAAAZbDduNiAAAEAwBHMEUCIQCgO7qCRzEhYnn5Q353y2A1
BvWLAphKQXToaGPRZZo9HwIgWuWzRUubEvn94qeEKCRD2gRVcHL6ZpI24CQ+dpiT
ghAAdgBkEcRspBLsp4kcogIuALyrTygH1B41J6vq/tUDyX3N8AAAAZbDduOeAAAE
AwBHMEUCIQC4wYxiCGqp0SVY8NdS39l7Pz7IDobVghhPEDZRGFytxQIgC4fvaZXG
HvZHnrzo2sIV+/mWnW4i4Xnqr4d2/9XqCkUAdwBJnJtp3h187Pw23s2HZKa4W68K
h4AZ0VVS++nrKd34wwAAAZbDduOyAAAEAwBIMEYCIQCi2Nm83lyIF0BjaSOaxMYY
0eq8nn7iU7R3GisJ5jzWrwIhALuJpSRDdNQvraC4jMFozREI5pFwfGecXoxHQ1HO
PM9CMA0GCSqGSIb3DQEBCwUAA4IBAQCoIX17lnYu38goCoEAyKvK/ZeX6Xw5w7sd
L/ulzKcU43ctrDW96NOoLDMJz6XP84mmOGSy1FrPp67lrsSN/xaksFZRuP8AV6KA
cjXXyF4dYuHnK4yHa7T5UZ3kzacPv+KWASSA9yR2lzJSP3aQ5hEJ0nR+exl8R32F
MN/9pE4YtvLWRTcapvmDElIWuuJGypM13x3zh1YFl1R+RTEuAc0cV8BG7qjlGDaP
JY+TkhGutKZux3loX28l/t9FeFTT2WiUz8qm933OuNAkx6eZx+JllDU5XBo2IWgB
LH6wYZxlExtMsrnyDq/FOpGCtOjCnCHxwJY8AY8P/IRAFkBqiEk7
-----END CERTIFICATE-----";

        // Create a certificate with no issuer (incomplete chain)
        $cert = Certificate::create([
            'domain_id' => $domain->id,
            'status' => 'issued',
            'certificate' => $validCert,
            'issuer' => 'Test Issuer',
            'is_ca' => false,
            'request_type' => 'manual',
        ]);

        // Mock KempService to return empty list of certs
        $this->mock(KempService::class, function ($mock) {
            $mock->shouldReceive('listCerts')->andReturn([]);
        });

        $response = $this->postJson(route('automations.check-cert'), [
            'domain_id' => $domain->id,
            'type' => 'kemp',
            'hostname' => 'kemp.test.local',
            'password' => 'secret-key'
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonFragment(['cert_name' => "auto_test_com_{$cert->id}"]);
        // Verify it contains the warning about incomplete chain
        $this->assertStringContainsString('incomplete chain', $response->json('message'));
    }

    public function test_test_connection_requires_password()
    {
        $response = $this->postJson(route('automations.test'), [
            'type' => 'kemp',
            'hostname' => 'kemp.test.local',
            // password missing
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_store_paloalto_successfully_without_profiles()
    {
        $domain = Domain::create(['name' => 'palo-test.com']);
        
        $response = $this->post(route('automations.store'), [
            'domain_id' => $domain->id,
            'type' => 'paloalto',
            'hostname' => 'palo.test.local',
            'password' => 'palo-api-key',
            'config' => [
                'profiles_string' => '' // Optional field
            ]
        ]);

        $response->assertRedirect(route('automations.index'));
        $this->assertDatabaseHas('automations', [
            'domain_id' => $domain->id,
            'type' => 'paloalto',
            'hostname' => 'palo.test.local',
        ]);
        
        $automation = Automation::where('domain_id', $domain->id)->where('type', 'paloalto')->first();
        $this->assertEquals('', $automation->config['profiles_string']);
    }

    public function test_kemp_service_deploy_uploads_versioned_cert_and_updates_referring_virtual_services()
    {
        $domain = Domain::create(['name' => 'kemptest.nl']);
        
        // Generate an RSA private key
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pkey);

        // Old cert (expired)
        $oldCert = Certificate::create([
            'domain_id' => $domain->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'certificate' => 'dummy-old-cert',
            'private_key' => encrypt($pkey),
            'expiry_date' => now()->subDays(5), // expired
        ]);

        // New cert
        $newCert = Certificate::create([
            'domain_id' => $domain->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'certificate' => 'dummy-new-cert',
            'private_key' => encrypt($pkey),
            'expiry_date' => now()->addDays(90),
        ]);

        $oldCertName = "auto_kemptest_nl_{$oldCert->id}";
        $newCertName = "auto_kemptest_nl_{$newCert->id}";

        \Illuminate\Support\Facades\Http::fake(function ($request) use ($oldCertName, $newCertName) {
            $data = $request->data();
            $cmd = $data['cmd'] ?? '';

            if ($cmd === 'listcert') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'cert' => [
                        ['name' => $oldCertName],
                    ]
                ], 200);
            }

            if ($cmd === 'addcert') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'message' => 'Certificate uploaded',
                ], 200);
            }

            if ($cmd === 'listvs') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'VS' => [
                        [
                            'Index' => 101,
                            'NickName' => 'App Web VS',
                            'CertFile' => $oldCertName, // Referring to old version!
                        ],
                        [
                            'Index' => 102,
                            'NickName' => 'Unrelated VS',
                            'CertFile' => 'other_cert',
                        ]
                    ]
                ], 200);
            }

            if ($cmd === 'modvs') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'message' => 'VS modified',
                ], 200);
            }

            if ($cmd === 'delcert') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'message' => 'Cert deleted',
                ], 200);
            }

            return \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200);
        });

        $automation = Automation::create([
            'domain_id' => $domain->id,
            'type' => 'kemp',
            'hostname' => 'kemp.test.local',
            'password' => 'test-key',
            'config' => ['mode' => 'auto_discovery'],
        ]);

        $service = app(KempService::class);
        $result = $service->deploy($automation, $newCert);

        $this->assertTrue($result);

        // Verify addcert was called with new versioned name
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($newCertName) {
            return ($request->data()['cmd'] ?? '') === 'addcert' &&
                   ($request->data()['cert'] ?? '') === $newCertName;
        });

        // Verify modvs was called for VS 101 with CertFile = new versioned name
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($newCertName) {
            return ($request->data()['cmd'] ?? '') === 'modvs' &&
                   ($request->data()['vs'] ?? null) == 101 &&
                   ($request->data()['CertFile'] ?? '') === $newCertName;
        });

        // Verify modvs was NOT called for unrelated VS 102
        \Illuminate\Support\Facades\Http::assertNotSent(function ($request) {
            return ($request->data()['cmd'] ?? '') === 'modvs' &&
                   ($request->data()['vs'] ?? null) == 102;
        });
    }

    public function test_kemp_service_check_status_identifies_outdated_virtual_services()
    {
        $domain = Domain::create(['name' => 'statuskemp.nl']);
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pkey);

        $cert = Certificate::create([
            'domain_id' => $domain->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'certificate' => 'dummy-cert',
            'private_key' => encrypt($pkey),
            'expiry_date' => now()->addDays(90),
            'thumbprint_sha256' => 'thumb123',
        ]);

        $expectedCertName = "auto_statuskemp_nl_{$cert->id}";

        $automation = Automation::create([
            'domain_id' => $domain->id,
            'type' => 'kemp',
            'hostname' => 'kemp.test.local',
            'password' => 'test-key',
            'config' => ['mode' => 'auto_discovery'],
        ]);

        \Illuminate\Support\Facades\Http::fake(function ($request) use ($expectedCertName) {
            $cmd = $request->data()['cmd'] ?? '';
            if ($cmd === 'readcert') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'certificate' => 'dummy-cert'
                ], 200);
            }
            if ($cmd === 'listvs') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'VS' => [
                        [
                            'Index' => 201,
                            'NickName' => 'Outdated VS',
                            'CertFile' => 'auto_statuskemp_nl_old', // Older version
                        ],
                        [
                            'Index' => 202,
                            'NickName' => 'Updated VS',
                            'CertFile' => $expectedCertName, // Matches new cert
                        ]
                    ]
                ], 200);
            }
            return \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200);
        });

        $service = app(KempService::class);
        $status = $service->checkStatus($automation, $cert);

        $this->assertTrue($status['exists_on_device']);
        $this->assertTrue($status['needs_update']); // Because VS 201 is outdated
        $this->assertCount(2, $status['details']['virtual_services']);
        $this->assertFalse($status['details']['virtual_services'][0]['up_to_date']);
        $this->assertTrue($status['details']['virtual_services'][1]['up_to_date']);
    }

    public function test_kemp_service_cleanup_does_not_delete_currently_deploying_or_latest_cert_even_if_expired()
    {
        $domain = Domain::create(['name' => 'expiredtest.nl']);
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pkey);

        // Certificate is expired in local DB
        $cert = Certificate::create([
            'domain_id' => $domain->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'certificate' => 'dummy-cert',
            'private_key' => encrypt($pkey),
            'expiry_date' => now()->subDays(10), // expired!
            'thumbprint_sha256' => 'thumb123',
        ]);

        $certName = "auto_expiredtest_nl_{$cert->id}";

        $automation = Automation::create([
            'domain_id' => $domain->id,
            'type' => 'kemp',
            'hostname' => 'kemp.test.local',
            'password' => 'test-key',
            'config' => ['mode' => 'auto_discovery'],
        ]);

        \Illuminate\Support\Facades\Http::fake(function ($request) use ($certName) {
            $cmd = $request->data()['cmd'] ?? '';
            if ($cmd === 'listcert') {
                return \Illuminate\Support\Facades\Http::response([
                    'status' => 'ok',
                    'cert' => [
                        ['name' => $certName],
                    ]
                ], 200);
            }
            if ($cmd === 'addcert') {
                return \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200);
            }
            if ($cmd === 'listvs') {
                // No virtual services bound
                return \Illuminate\Support\Facades\Http::response(['status' => 'ok', 'VS' => []], 200);
            }
            if ($cmd === 'delcert') {
                return \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200);
            }
            return \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200);
        });

        $service = app(KempService::class);
        $service->deploy($automation, $cert);

        // Ensure delcert was NOT called for this cert
        \Illuminate\Support\Facades\Http::assertNotSent(function ($request) use ($certName) {
            return ($request->data()['cmd'] ?? '') === 'delcert' &&
                   ($request->data()['cert'] ?? '') === $certName;
        });
    }
}
