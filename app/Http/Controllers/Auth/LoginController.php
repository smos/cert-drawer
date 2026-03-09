<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\LdapUser;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        \Log::info("Login attempt for: " . $credentials['email']);

        try {
            // 1. Find LDAP User
            $ldapUser = LdapUser::where('mail', $credentials['email'])->first();
            if (!$ldapUser) {
                $ldapUser = LdapUser::where('userprincipalname', $credentials['email'])->first();
            }

            if ($ldapUser) {
                \Log::info("LDAP User found: " . $ldapUser->getDn());
                
                // 2. Authenticate LDAP User
                if ($ldapUser->getConnection()->auth()->attempt($ldapUser->getDn(), $credentials['password'])) {
                    \Log::info("LDAP Authentication successful for: " . $ldapUser->getDn());

                    // 3. Check Group Authorization
                    $settings = \App\Models\Setting::all()->pluck('value', 'key');
                    $allowedGroups = json_decode($settings['ldap_allowed_groups'] ?? '[]', true);

                    if (!empty($allowedGroups)) {
                        $userGroups = $ldapUser->getAttribute('memberof') ?: [];
                        
                        $userGroupsLower = array_map('strtolower', $userGroups);
                        $isAuthorized = false;
                        foreach ($allowedGroups as $allowedGroupDn) {
                            if (in_array(strtolower($allowedGroupDn), $userGroupsLower)) {
                                $isAuthorized = true;
                                break;
                            }
                        }

                        if (!$isAuthorized) {
                            \Log::warning("User unauthorized by group membership. Allowed: " . json_encode($allowedGroups) . " User has: " . json_encode($userGroups));
                            throw ValidationException::withMessages(['email' => 'Your account is not authorized to access this application.']);
                        }
                        \Log::info("Group authorization successful for: " . $credentials['email']);
                    }

                    // 4. Sync to Local Database
                    $guid = $ldapUser->getConvertedGuid();
                    $existingUser = User::where('guid', $guid)->first();
                    
                    if (!$existingUser) {
                        $existingUser = User::where('email', $credentials['email'])->first();
                    }

                    $user = User::updateOrCreate(
                        ['guid' => $guid],
                        [
                            'name' => $ldapUser->getFirstAttribute('cn') ?? $ldapUser->getName(),
                            'email' => $credentials['email'],
                            'password' => $existingUser ? $existingUser->password : Hash::make(Str::random(32)),
                        ]
                    );

                    \Log::info("User synced and logging in: " . $user->email);
                    
                    Auth::login($user, $request->boolean('remember'));
                    AuditLog::log('login', "User logged in via LDAP: {$user->email}");
                    
                    $request->session()->regenerate();
                    return redirect()->intended(route('domains.index'));
                } else {
                    \Log::warning("LDAP Authentication FAILED (wrong password) for: " . $ldapUser->getDn());
                }
            } else {
                \Log::warning("LDAP User NOT FOUND for: " . $credentials['email']);
            }
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            \Log::error("LDAP error during manual login flow: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
        }

        // 5. Local Fallback (for non-LDAP accounts like 'admin@example.com')
        \Log::info("Attempting local database fallback for: " . $credentials['email']);
        $localUser = User::where('email', $credentials['email'])->first();
        if ($localUser && empty($localUser->guid)) {
            if (Hash::check($credentials['password'], $localUser->password)) {
                \Log::info("Local password check passed for: " . $localUser->email);
                
                Auth::login($localUser, $request->boolean('remember'));
                AuditLog::log('login', "User logged in via local database: {$localUser->email}");
                
                $request->session()->regenerate();
                return redirect()->intended(route('domains.index'));
            } else {
                \Log::warning("Local password check FAILED for: " . $localUser->email);
            }
        }

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            AuditLog::log('logout', "User logged out: {$user->email}");
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}
