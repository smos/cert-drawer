<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Setting;
use App\Models\Domain;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $defaultSettings = [
            // Expiry Thresholds
            'expiry_yellow' => '30',
            'expiry_orange' => '20',
            'expiry_red' => '10',
            
            // CSR Default DN Template
            'dn_country' => 'NL',
            'dn_state' => 'State',
            'dn_locality' => 'Locality',
            'dn_organization' => 'Organization',
            'dn_ou' => 'IT Department',

            // ACME Defaults
            'acme_url_dv' => 'https://acme.example.com/dv',
            'acme_url_san' => 'https://acme.example.com/dv-san',
            'acme_url_wildcard' => 'https://acme.example.com/dv-wildcard',
            'acme_renewal_days' => '15',

            // Admin & Notification Defaults
            'admin_groups' => 'cn=admins,ou=groups,dc=domain,dc=local',
            'admin_email' => 'admin@domain.local',
        ];

        // Only seed settings if the table is empty
        if (Setting::count() === 0) {
            foreach ($defaultSettings as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        // Only run PKI seeder if no domains exist
        if (Domain::count() === 0) {
            $this->call([
                InitialPKISeeder::class,
            ]);
        }
    }
}
