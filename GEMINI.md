# Project Status: Cert Drawer (certkast)

## Core Capabilities
- **Certificate Lifecycle:** Supports CSR initiation (Auto/Custom/Upload) and fulfillment via ADCS or ACME.
- **ACME Integration:** Native PHP implementation (`AcmeService`) for automated DV, SAN, and Wildcard fulfillment with EAB support (Networking4all).
- **Automation:** Support for automated certificate deployment to Kemp Loadmasters, Fortigate firewalls, and Palo Alto firewalls via API.
- **Authentication:** Dual LDAP (Active Directory) and Local database authentication. Automatic synchronization of LDAP users to local DB using `guid`. 
- **Authorization:** 
    - Global "Allowed LDAP Groups" in settings.
    - Domain-level "Visibility Groups" to restrict domain access to specific LDAP groups.
    - Delegated administration for specific AD groups (`3e_Lijn`, `Technisch_Applicatiebeheer`).
- **Security:** 
    - Private keys encrypted in database.
    - Sensitive downloads (Key, PFX) use POST requests and masked password modals.
    - Thumbprints (SHA1/SHA256) stored and searchable.
- **Audit Logging:** Comprehensive tracking of logins, requests, downloads, and domain/setting changes.
- **Management:** Dashboard filterable by health (Expired, Expiring, Healthy) and domain status (Enabled/Disabled).

## Technical Details
- **ACME Implementation:** Native PHP ACME v2 client.
- **Database:** SQLite.
- **LDAP:** Uses `LdapRecord-Laravel`. `AppServiceProvider` dynamically re-registers connection from DB settings.
- **CLI:** `php artisan admin:setup {email}` for local admin management.

## Roadmap / Next Steps
- Automated Expiry Notifications (Email/Webhooks).
- Scheduled auto-renewal for ACME certificates (Implemented).
- Certificate chain management (Improved).
- Improved search (search by serial, issuer).
