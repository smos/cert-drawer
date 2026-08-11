# Certificate Drawer
This web app is a solution for documenting and maintaining SSL Certificates with some automation features for Palo Alto, Fortigate and Kemp solutions.

It is written almost entirely using gemini-cli, antigravity. Based on a Laravel 11 framework with SQLite3 backing. There is limited automated testing, most is done through human testing. App is currently in use.

There is a mass import option available via the CLI to import a folder, but the web front end supports adding a domain manually and uploading seperate files as well as importing PFX files.

## Architecture:
You can run the container without a external poller, but that complicates the dual-stack deployment. I advise to use the external-poller/healthtest.php script that is in the repo that you can deploy on a seperate dual-stack host. The connection to the poller uses a shared secret and only JSON messages are sent back and forth so that the page can not be used as a proxy or for Recon.

The RBAC roles require LDAP authentication, the app does not have a native users and groups manager.

![alt text Deployment Diagram](screenshots/deployment.png?raw=true "Recommended method")

## Screenshots
Menu
![alt text Menu](screenshots/menu.png?raw=true "Menu")

Dashboard/Calendar
![alt text Calendar](screenshots/calendar.png?raw=true "Calendar")

LDAP Auth options
![alt text LDAP Auth Options](screenshots/ldapauth.png?raw=true "LDAP Auth Options")

Domain List
![alt text Domain List](screenshots/domainlist.png?raw=true "Domain List")

Certificate Details
![alt text Certificate Details](screenshots/certificatedetails.png?raw=true "Certificate Details")

# Implemented Features

### Administration
- Searchable index by name, tag, thumbprint
- Lists Domains and Authorities, performs chain validation
- Tags for either private key or public keys
- Notes field to leave things like 3rd party contact information, owners
- Audit Logging
- Private keys are PFX password gated through app

### Configuration
- Expiry information configurable
- CSR Defaults based on settings, existing certificate information (renew)
- Custom CSR supported
- LDAP authentication and groups
- LDAP group visibility and operations (view, download etc.)
- LDAP RBAC roles
- Archiving and cleanup on expired certificates

### Certificate fulfilment
- ADCS Certificate fulfilment using ADCS Webserver
- ACME Certificate fulfilment (Native PHP implementation)
- Manual CSR/PEM

### Monitoring
- DNS monitoring for domains with change tracking, internal/external resolver (split horizon)
- Certificate monitoring for domains with change tracking, internal/external
- Automated emails on changes to DNS/Certificates
- Webhook integration for notification for Entra, Certificates, DNS
- Entra ID Client Secrets and Certificates for Entra Apps

### Automation
- Kemp (API) deployment
- Fortigate (API) deployment
- Palo Alto (API) deployment
- Test, Dry-Run, Logs
- Scheduler activity
- 

# Deployment
This can be deployed through docker using the image from databeestje/cert-drawer
This should init a new install and first sign-on will prompt for the local admin user.
You can ofcourse run this bare metal, but would not recommend.

Example Stack for Portainer, basic but works. This does asume you use a reverse proxy on the portainer host like NPM or Traefik to reach it.

	services:
	  certdrawer:
	    image: databeestje/cert-drawer
	    container_name: cert-drawer
	    volumes:
	      - cert-data:/var/www/html/storage/app/private/certificates
	      - db-data:/var/www/html/storage/database
	    environment:
	      - APP_URL=https://certdrawer.domain.local
	      - APP_ENV=production
	      - APP_DEBUG=false
	      - APP_KEY=
	      - DB_CONNECTION=sqlite
	      - DB_DATABASE=/var/www/html/storage/database/database.sqlite
	    restart: unless-stopped
	    healthcheck:
	      test: ["CMD", "curl", "-f -s", "http://localhost/health"]
	      interval: 30s
	      timeout: 10s
	      retries: 3

	  scheduler:
	    image: databeestje/cert-drawer
	    container_name: cert-drawer-scheduler
	    entrypoint: ["/usr/local/bin/entrypoint.sh", "php", "artisan", "schedule:work"]
	    volumes:
	      - cert-data:/var/www/html/storage/app/private/certificates
	      - db-data:/var/www/html/storage/database
	    environment:
	      - APP_URL=https://certdrawer.domain.local
	      - APP_ENV=production
	      - APP_DEBUG=false
	      - APP_KEY=
	      - DB_DATABASE=/var/www/html/storage/database/database.sqlite
	      - DB_CONNECTION=sqlite
	    restart: unless-stopped

	volumes:
	  cert-data:
	  db-data:


**Note**: If you are not using the external poller script functionality: Make sure to configure the local Docker instance with IPv6, as the app will monitor both for DNS and certificate checks. This is quite a hassle, still, in 2026. Would recommend using the PHP healthtest.php script from the external-poller directory on a other webserver with Dual-Stack.

If you really must: On the docker host I modified the daemon.json with "ipv6:true" and a ULA prefix for the default bridge "fixed-cidr-v6": "fd00:dead:beef::/80"
The docker host is hosted in a /64 subnet, the host has single ip6 address <prefix48>:<subnet>::<ip>/112.
For a Portainer stack this requires removing the created network by the stack and manually adding it with the tags "ipv6: true", "experimental: true" and "ip6tables:true". 
On the newly created network I use the subnet "<prefix48>:<subnet>:<ip>::/80", range "<prefix48>:<subnet>:<ip>::/96" and gateway "<prefix48>:<subnet>:<ip>::1".

## Initial Configuration

On 1st configuration you create a admin user and password, it is recommended to setup LDAP authentication for the other functionality and RBAC roles.

Inital deployment will generate a Self-Signed Root, Intermediate and domain.local certificates to allow some interaction. You are free to delete it.

## CLI commands
The app has a few custom command available via the PHP artisan commands. Connect the console of the container with the www-data user and you can run these.

	 automation
	  automation:check                   Dry-run automation check to see if devices are up to date 
	 cert
	  cert:monitor                       Perform TLS certificate health checks for enabled domains
	  cert:sync-entra                    Daily synchronization of Entra ID applications and expiry monitoring
	  cert:test-mail                     Send a test certificate health report email
	 certificates
	  certificates:archive               Archive expired certificates that exceed the threshold setting.
	  certificates:automation-cleanup    Cleanup expired certificates on devices for all active automations (Palo Alto, Fortigate).
	  certificates:deduplicate           Remove duplicate certificate records and their files, keeping the most complete version.
	  certificates:migrate-folders       Migrate existing certificates from Y-m to Y-m-d_H-i-s folder structure
	  certificates:reindex               Recalculate all certificate thumbprints, serial numbers and issuers in the database.
	  certificates:renew-acme            Automatically renew ACME certificates that are close to expiry.
	  certificates:renew-domain          Renew ACME certificate for a specific domain.
	  certificates:test-expired-cleanup  Simulate an expired certificate to verify cleanup and automation triggers.
	 dns
	  dns:monitor                        Monitor DNS records for all enabled domains
	 fortigate
	  fortigate:deploy-test              Test certificate deployment to Fortigate
	 import
	  import:folder                      Mass import certificates from a folder structure
	 kemp
	  kemp:deploy-test                   Test certificate deployment to Kemp
	 paloalto
	  paloalto:deploy-test              Test certificate deployment to Palo Alto
	 test
	  test:acme-native                   Debug script for native PHP ACME fulfillment (EAB supported)
	  
