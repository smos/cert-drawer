# Certificate Drawer
This web app is a Work in Progress solution for documenting and maintaining of SSL Certificates.
It is written almost entirely using gemini-cli where most time spent is in testing. Not sure if that development cycle will survive but it has come this far.

There is a mass import option available via the CLI, but the web front end supports adding a domain manually and uploading seperate files as well as importing a PFX file.

# Design Goals (working)
- searchable index by name, tags, thumbprints
- Lists Domains and Authorities
- No local storage of private keys, PFX password gated
- Expiry information configurable
- CSR Default DNS template based on common information
- Custom CSR supported
- LDAP authentication and groups
- LDAP group visibility and operations (view, download etc.)
- ADCS Certificate fulfilment using ADCS Webserver
- ACME Certificate fulfilment (tested with networking4all.com)
- Audit Logging

# Automation Wishlist, not yet working, TODO
- Kemp (API) (placeholder)
- Fortigate (API)
- Palo Alto (API)
- Windows (TBD)

# Deployment
This can be deployed through docker using the image from databeestje/cert-drawer
This should init a new install and first signon will prompt for the local admin user.

Inital deployment will generate a Self-Signed Root, Intermediate and domain.local certificates to allow some interaction.

The app has a few custom command available via the PHP artisan commands.
- "php artisan admin:setup" Setup the default user from the CLI instead of the web page.
- "php artisan import:folder" will attempt to import a wide range of CA and certificate files if these a somehwat decent, consistent format and layout. Testing with about ~100 certificates.
- "php artisan certificates:deduplicate" can be run as maintenance task for removing duplicates
- "php artisan certificates:migrate-folders" can be run to move from older directory format to newer format based on cert date.

