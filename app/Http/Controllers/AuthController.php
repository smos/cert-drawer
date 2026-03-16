<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return view('settings.auth', compact('settings'));
    }

    public function update(Request $request)
    {
        // Handle the allowed groups array specifically
        $allowedGroups = $request->input('ldap_allowed_groups', []);
        Setting::updateOrCreate(['key' => 'ldap_allowed_groups'], ['value' => json_encode($allowedGroups)]);

        // Handle the admin groups array specifically
        $adminGroups = $request->input('admin_groups', []);
        Setting::updateOrCreate(['key' => 'admin_groups'], ['value' => json_encode($adminGroups)]);

        $granularAreas = ['auth', 'settings', 'automations', 'audit', 'dns', 'cert_health'];
        foreach ($granularAreas as $area) {
            $key = "access_groups_{$area}";
            $groups = $request->input($key, []);
            Setting::updateOrCreate(['key' => $key], ['value' => json_encode($groups)]);
        }

        $excludeFromGeneral = array_merge(['_token', 'ldap_allowed_groups', 'admin_groups'], array_map(fn($a) => "access_groups_{$a}", $granularAreas));
        foreach ($request->except($excludeFromGeneral) as $key => $value) {
            if (in_array($key, ['ldap_password']) && ($value === '********' || empty($value))) {
                if ($value === '********') continue;
                if (empty($value) && Setting::where('key', $key)->exists()) continue;
            }
            
            // Handle array to string conversion safely
            $storeValue = is_array($value) ? json_encode($value) : $value;
            Setting::updateOrCreate(['key' => $key], ['value' => $storeValue]);
        }

        AuditLog::log('auth_settings_update', "Updated authentication settings");

        return redirect()->route('auth.settings.index')->with('success', 'Authentication settings updated.');
    }

    public function testLdap(Request $request)
    {
        $host = $request->input('ldap_host');
        if (empty($host)) {
            return response()->json(['success' => false, 'message' => 'LDAP Host is required for testing.']);
        }

        $user = $request->input('ldap_username');
        $pass = $request->input('ldap_password');
        $port = (int) ($request->input('ldap_port') ?? 389);
        $base = $request->input('ldap_base_dn');
        $useSsl = (bool) $request->input('ldap_use_tls');
        $useTls = (bool) $request->input('ldap_use_starttls');
        $skipVerify = (bool) $request->input('ldap_skip_verify');

        if ($pass === '********') {
            $pass = Setting::where('key', 'ldap_password')->first()->value ?? '';
        }

        try {
            $config = [
                'hosts' => [$host],
                'username' => $user,
                'password' => $pass,
                'port' => $port,
                'base_dn' => $base,
                'use_ssl' => $useSsl,
                'use_tls' => $useTls,
                'options' => [
                    LDAP_OPT_X_TLS_REQUIRE_CERT => $skipVerify ? LDAP_OPT_X_TLS_NEVER : LDAP_OPT_X_TLS_HARD,
                ],
            ];

            if ($skipVerify) {
                putenv('LDAPTLS_REQCERT=never');
            }

            $connection = new \LdapRecord\Connection($config);
            $connection->connect();

            if ($connection->auth()->attempt($user, $pass)) {
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'message' => 'Bind failed. Check credentials.']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function searchGroups(Request $request)
    {
        $term = $request->input('q');
        if (empty($term)) return response()->json([]);

        try {
            $groups = \LdapRecord\Models\ActiveDirectory\Group::where('cn', 'contains', $term)
                ->limit(10)
                ->get()
                ->map(function($group) {
                    return [
                        'dn' => $group->getDn(),
                        'cn' => $group->getName(),
                    ];
                });
            return response()->json($groups);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
