<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->bootLdapConfiguration();
        $this->bootMailConfiguration();
    }

    public function bootMailConfiguration(): void
    {
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $dbSettings = Setting::whereIn('key', [
            'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name'
        ])->get()->pluck('value', 'key');

        if ($dbSettings->has('mail_host') && !empty($dbSettings['mail_host'])) {
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', $dbSettings['mail_host']);
            Config::set('mail.mailers.smtp.port', (int) ($dbSettings['mail_port'] ?? 587));
            Config::set('mail.mailers.smtp.encryption', $dbSettings['mail_encryption'] ?? 'tls');
            Config::set('mail.mailers.smtp.username', $dbSettings['mail_username'] ?? null);
            Config::set('mail.mailers.smtp.password', $dbSettings['mail_password'] ?? null);
            
            Config::set('mail.mailers.smtp.verify_peer', false);
            Config::set('mail.mailers.smtp.verify_peer_name', false);
            Config::set('mail.mailers.smtp.allow_self_signed', true);
            
            Config::set('mail.from.address', $dbSettings['mail_from_address'] ?? 'noreply@example.com');
            Config::set('mail.from.name', $dbSettings['mail_from_name'] ?? config('app.name'));
        }
    }

    protected function bootLdapConfiguration(): void
    {
        // Only attempt if database is migrated
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $dbSettings = Setting::whereIn('key', [
            'ldap_host', 'ldap_use_tls', 'ldap_use_starttls', 'ldap_skip_verify', 'ldap_base_dn', 'ldap_username', 'ldap_password', 'ldap_port'
        ])->get()->pluck('value', 'key');

        if ($dbSettings->has('ldap_host') && !empty($dbSettings['ldap_host'])) {
            $config = [
                'hosts' => [$dbSettings['ldap_host']],
                'username' => $dbSettings['ldap_username'] ?? env('LDAP_USERNAME'),
                'password' => $dbSettings['ldap_password'] ?? env('LDAP_PASSWORD'),
                'port' => (int) ($dbSettings['ldap_port'] ?? 389),
                'base_dn' => $dbSettings['ldap_base_dn'] ?? env('LDAP_BASE_DN'),
                'use_ssl' => (bool) ($dbSettings['ldap_use_tls'] ?? false),
                'use_tls' => (bool) ($dbSettings['ldap_use_starttls'] ?? false),
            ];

            if ((bool) ($dbSettings['ldap_skip_verify'] ?? false)) {
                $config['options'] = [
                    LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_NEVER
                ];
                putenv('LDAPTLS_REQCERT=never');
            }

            Config::set('ldap.connections.default', array_merge(Config::get('ldap.connections.default', []), $config));

            // Re-register the connection in LdapRecord to ensure it's updated
            try {
                \LdapRecord\Container::addConnection(
                    new \LdapRecord\Connection($config),
                    'default'
                );
            } catch (\Exception $e) {
                \Log::error("Failed to re-register LDAP connection in AppServiceProvider: " . $e->getMessage());
            }
        }
    }
}
