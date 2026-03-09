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
     * Check if user can access a domain based on group membership.
     */
    public function canAccess(Domain $domain): bool
    {
        // Local admins (no GUID) see everything
        if (empty($this->guid)) {
            return true;
        }

        // Global admins (delegated) see everything
        if ($this->canAccessDomainManagement()) {
            return true;
        }

        $allowedGroups = $domain->allowed_groups;

        // If no groups specified, everyone can see it
        if (empty($allowedGroups)) {
            return true;
        }

        // Cache the LDAP groups for the duration of the request to avoid multiple LDAP calls
        $userGroups = \Cache::remember('user_groups_' . $this->id, 60, function () {
            $ldapUser = \App\Models\LdapUser::where('mail', $this->email)
                ->orWhere('userprincipalname', $this->email)
                ->first();
            
            if (!$ldapUser) return [];
            
            $memberOf = $ldapUser->getAttribute('memberof') ?: [];
            return array_map('strtolower', $memberOf);
        });

        foreach ($allowedGroups as $groupDn) {
            if (in_array(strtolower($groupDn), $userGroups)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has management permissions (delegated admin).
     */
    public function canAccessDomainManagement(): bool
    {
        if (empty($this->guid)) return true;

        $managementGroupsStr = \App\Models\Setting::where('key', 'admin_groups')->value('value') ?? '';
        $managementGroups = array_filter(explode(';', strtolower($managementGroupsStr)));

        if (empty($managementGroups)) {
            // Fallback if not configured
            $managementGroups = [
                'cn=admins,ou=groups,dc=domain,dc=local',
            ];
        }

        $userGroups = \Cache::remember('user_groups_' . $this->id, 60, function () {
            $ldapUser = \App\Models\LdapUser::where('mail', $this->email)
                ->orWhere('userprincipalname', $this->email)
                ->first();
            
            if (!$ldapUser) return [];
            
            $memberOf = $ldapUser->getAttribute('memberof') ?: [];
            return array_map('strtolower', $memberOf);
        });

        foreach ($managementGroups as $groupDn) {
            if (in_array(strtolower($groupDn), $userGroups)) {
                return true;
            }
        }

        return false;
    }
}
