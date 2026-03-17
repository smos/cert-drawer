# Certificate Drawer
This web app is a Work in Progress solution for documenting and maintaining of SSL Certificates.
It is written almost entirely using gemini-cli where most time spent is in testing. Not sure if that development cycle will survive but it has come this far.

There is a mass import option available via the CLI, but the web front end supports adding a domain manually and uploading seperate files as well as importing a PFX file.

Domain List
![alt text](screenshots/domainlist.png?raw=true "Domain List")

Certificate Details
![alt text](screenshots/certificatedetails.png?raw=true "Certificate Details")

# Design Goals (working)
- searchable index by name, tags, thumbprints
- Lists Domains and Authorities
- Add Tags for either private key or public keys
- Notes field to leave things like 3rd party contact information, owners
- No local storage of private keys, PFX password gated
- Expiry information configurable
- CSR Default DNS template based on common information
- Custom CSR supported
- LDAP authentication and groups
- LDAP group visibility and operations (view, download etc.)
- ADCS Certificate fulfilment using ADCS Webserver
- ACME Certificate fulfilment (tested with networking4all.com)
- Audit Logging
- DNS monitoring for domains with change tracking
- Certificate monitoring for domains with change tracking
- Automated emails on changes to DNS/Certificates

# Automation Wishlist, not yet working, TODO
- Kemp (API) (placeholder)
- Fortigate (API)
- Palo Alto (API)
- Windows (TBD)

# Deployment
This can be deployed through docker using the image from databeestje/cert-drawer
This should init a new install and first signon will prompt for the local admin user.

Example Stack content for Portainer

	services:
	  certkast:
	    image: databeestje/cert-drawer
	    container_name: cert-drawer
	    volumes:
	      - cert-data:/var/www/html/storage/app/private/certificates
	      - db-data:/var/www/html/storage/database
	      - acme-data:/acme
	    environment:
	      - APP_ENV=https://certdrawer.hdnet.nl
	      - APP_ENV=production
	      - APP_DEBUG=false
	      - APP_KEY=
	      - DB_CONNECTION=sqlite
	      - DB_DATABASE=/var/www/html/storage/database/database.sqlite
	      - ACME_HOME=/acme
	      - ACME_CERTS=/acme/certs
	      - ACME_BINARY=/acme/acme.sh
	    restart: unless-stopped
	    healthcheck:
	      test: ["CMD", "curl", "-f -s", "http://localhost/health"]
	      interval: 30s
	      timeout: 10s
	      retries: 3
	
	  scheduler:
	    image: databeestje/cert-drawer-scheduler
	    container_name: cert-drawer-scheduler
	    entrypoint: ["/usr/local/bin/entrypoint.sh", "php", "artisan", "schedule:work"]
	    volumes:
	      - cert-data:/var/www/html/storage/app/private/certificates
	      - db-data:/var/www/html/storage/database
	      - acme-data:/acme
	    environment:
	      - APP_ENV=production
	      - APP_DEBUG=false
	      - APP_KEY=
	      - DB_DATABASE=/var/www/html/storage/database/database.sqlite
	      - DB_CONNECTION=sqlite
	      - ACME_HOME=/acme
	      - ACME_CERTS=/acme/certs
	      - ACME_BINARY=/acme/acme.sh
	    restart: unless-stopped
	
	volumes:
	  cert-data:
	  db-data:
	  acme-data:



Inital deployment will generate a Self-Signed Root, Intermediate and domain.local certificates to allow some interaction.

The app has a few custom command available via the PHP artisan commands.
- "php artisan admin:setup" Setup the default user from the CLI instead of the web page.
- "php artisan import:folder" will attempt to import a wide range of CA and certificate files if these a somehwat decent, consistent format and layout. Testing with about ~100 certificates.
- "php artisan certificates:deduplicate" can be run as maintenance task for removing duplicates
- "php artisan certificates:migrate-folders" can be run to move from older directory format to newer format based on cert date.

