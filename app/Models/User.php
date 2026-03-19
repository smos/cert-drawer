<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LdapRecord\Laravel\Auth\AuthenticatesWithLdap;
use LdapRecord\Laravel\Auth\LdapAuthenticatable;

class User extends Authenticatable implements LdapAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, AuthenticatesWithLdap;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'guid',
        'domain',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the LDAP groups for the user (cached for the request).
     */
    protected function getUserLdapGroups(): array
    {
        return \Cache::remember('user_groups_' . $this->id, 60, function () {
            try {
                // Find LDAP User by mail or UPN (same as LoginController)
                $ldapUser = \App\Models\LdapUser::where('mail', $this->email)->first();
                if (!$ldapUser) {
                    $ldapUser = \App\Models\LdapUser::where('userprincipalname', $this->email)->first();
                }
                
                if (!$ldapUser) return [];
                
                $memberOf = $ldapUser->getAttribute('memberof') ?: [];
                return array_map('strtolower', $memberOf);
            } catch (\Exception $e) {
                \Log::error("LDAP group fetch failed for user {$this->email}: " . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Check if user is member of groups specified by a setting key.
     */
    protected function checkGroupMembership(string $settingKey, ?array $fallbackGroups = null): bool
    {
        $groupsStr = \App\Models\Setting::where('key', $settingKey)->value('value');
        
        if (is_null($groupsStr) || $groupsStr === '') {
            if (!is_null($fallbackGroups)) {
                $groups = $fallbackGroups;
            } else {
                return false;
            }
        } else {
            // Try to decode as JSON array
            $groups = json_decode($groupsStr, true);
            
            // Fallback to semicolon-separated string if not a JSON array
            if (!is_array($groups)) {
                $groups = array_filter(explode(';', strtolower($groupsStr)));
            } else {
                $groups = array_map('strtolower', $groups);
            }
        }

        if (empty($groups)) return false;

        $userGroups = $this->getUserLdapGroups();

        foreach ($groups as $groupDn) {
            if (in_array(strtolower($groupDn), $userGroups)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user can access a domain based on group membership.
     */
    public function canAccess(Domain $domain): bool
    {
        // Local admins (no GUID) see everything
        if (empty($this->guid)) return true;

        // Global admins (delegated) see everything
        if ($this->canAccessDomainManagement()) return true;

        $allowedGroups = $domain->allowed_groups;

        // If no groups specified, everyone can see it
        if (empty($allowedGroups)) return true;

        $userGroups = $this->getUserLdapGroups();

        foreach ($allowedGroups as $groupDn) {
            if (in_array(strtolower($groupDn), $userGroups)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has management permissions (delegated admin / super admin).
     */
    public function canAccessDomainManagement(): bool
    {
        if (empty($this->guid)) return true;

        return $this->checkGroupMembership('admin_groups', [
            'cn=admins,ou=groups,dc=domain,dc=local'
        ]);
    }

    /**
     * Check if user has access to a specific area (granular RBAC).
     */
    public function hasAccessTo(string $area): bool
    {
        if (empty($this->guid)) return true;

        // Super admins always have access
        if ($this->canAccessDomainManagement()) return true;

        // Specific area check
        return $this->checkGroupMembership('access_groups_' . $area);
    }
}
