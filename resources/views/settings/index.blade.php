@extends('layouts.app')

@section('content')
@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
        {{ session('error') }}
    </div>
@endif

<h2>General Application Settings</h2>

<form action="{{ route('settings.update') }}" method="POST" id="settings-form">
    @csrf
    
    <h3>ADCS Integration</h3>
    <div style="margin-bottom:15px">
        <label>ADCS Endpoint URL</label><br>
        <input type="text" name="adcs_endpoint" value="{{ $settings['adcs_endpoint'] ?? '' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>ADCS Template Name</label><br>
        <input type="text" name="adcs_template" value="{{ $settings['adcs_template'] ?? '' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>

    <hr>
    <h3>CSR Default DN Template</h3>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
        <div>
            <label>Country (C)</label><br>
            <input type="text" name="dn_country" value="{{ $settings['dn_country'] ?? 'NL' }}" placeholder="NL" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>State / Province (ST)</label><br>
            <input type="text" name="dn_state" value="{{ $settings['dn_state'] ?? 'State' }}" placeholder="State" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>Locality (L)</label><br>
            <input type="text" name="dn_locality" value="{{ $settings['dn_locality'] ?? 'Locality' }}" placeholder="Locality" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>Organization (O)</label><br>
            <input type="text" name="dn_organization" value="{{ $settings['dn_organization'] ?? 'Organization' }}" placeholder="Organization" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="grid-column: span 2;">
            <label>Organizational Unit (OU)</label><br>
            <input type="text" name="dn_ou" value="{{ $settings['dn_ou'] ?? 'IT Department' }}" placeholder="IT Department" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
    </div>

    <hr>
    <h3>ACME Integration (Networking4all)</h3>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
        <div style="grid-column: span 2;">
            <label>External Account Binding (EAB) KID</label><br>
            <input type="text" name="acme_kid" value="{{ $settings['acme_kid'] ?? '' }}" placeholder="Key ID" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="grid-column: span 2;">
            <label>External Account Binding (EAB) HMAC Key</label><br>
            <input type="password" name="acme_hmac" value="{{ isset($settings['acme_hmac']) ? '********' : '' }}" placeholder="Secret Key" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>ACME DV URL</label><br>
            <input type="text" name="acme_url_dv" value="{{ $settings['acme_url_dv'] ?? 'https://acme.networking4all.com/dv' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>ACME DV-SAN URL</label><br>
            <input type="text" name="acme_url_san" value="{{ $settings['acme_url_san'] ?? 'https://acme.networking4all.com/dv-san' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="grid-column: span 2;">
            <label>ACME DV-Wildcard URL</label><br>
            <input type="text" name="acme_url_wildcard" value="{{ $settings['acme_url_wildcard'] ?? 'https://acme.networking4all.com/dv-wildcard' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="grid-column: span 2;">
            <label>Auto-Renewal Threshold (Days before expiry)</label><br>
            <input type="number" name="acme_renewal_days" value="{{ $settings['acme_renewal_days'] ?? '30' }}" min="1" style="width:100%; padding:8px; border:1px solid #ddd;">
            <small style="color: #666;">ACME certificates will be automatically renewed when they have fewer than this many days remaining.</small>
        </div>
    </div>

    <hr>
    <h3>Expiry Thresholds (Days)</h3>
    <div style="display: flex; gap: 20px;">
        <div style="flex:1">
            <label>Yellow (Warning)</label><br>
            <input type="number" name="expiry_yellow" value="{{ $settings['expiry_yellow'] ?? '30' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="flex:1">
            <label>Orange (Urgent)</label><br>
            <input type="number" name="expiry_orange" value="{{ $settings['expiry_orange'] ?? '20' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="flex:1">
            <label>Red (Critical)</label><br>
            <input type="number" name="expiry_red" value="{{ $settings['expiry_red'] ?? '10' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
    </div>

    <hr>
    <h3>DNS Monitoring</h3>
    <div style="margin-bottom:15px">
        <label>DNS Monitor Resolver</label><br>
        <input type="text" name="dns_resolver" value="{{ $settings['dns_resolver'] ?? '8.8.8.8' }}" placeholder="8.8.8.8" style="width:100%; padding:8px; border:1px solid #ddd;">
        <small style="color: #666;">IP address of the DNS resolver to use for monitoring (e.g., 8.8.8.8, 1.1.1.1, or internal resolver).</small>
    </div>

    <div style="margin-bottom:15px">
        <label>DNS Check Interval (Hours)</label><br>
        <input type="number" name="dns_check_interval" value="{{ $settings['dns_check_interval'] ?? '1' }}" min="1" max="24" style="width:100%; padding:8px; border:1px solid #ddd;">
        <small style="color: #666;">Minimum time between automated DNS checks (Recommended: 1 to 24 hours).</small>
    </div>

    <div style="margin-bottom:15px">
        <label>External Poller URL</label><br>
        <input type="text" name="external_poller_url" value="{{ $settings['external_poller_url'] ?? '' }}" placeholder="https://certpoller.domain.local/healthtest.php" style="width:100%; padding:8px; border:1px solid #ddd;">
        <small style="color: #666;">If set, DNS and Certificate health checks will be offloaded to this external endpoint. Leave empty for native (local) checks.</small>
    </div>

    <div style="margin-bottom:15px">
        <label>Poller API Key</label><br>
        <input type="password" name="poller_api_key" value="{{ isset($settings['poller_api_key']) ? '********' : '' }}" placeholder="Enter a secure key for poller communication" style="width:100%; padding:8px; border:1px solid #ddd;">
        <small style="color: #666;">This key must match the one configured in the external poller script for secure callbacks.</small>
    </div>

    <hr>
    <h3>Entra ID Integration (Enterprise Apps & App Registrations)</h3>
    <div style="margin-bottom: 20px; background: #f0f7ff; padding: 15px; border-left: 4px solid #0078d4; border-radius: 4px;">
        <h4 style="margin-top: 0; color: #0078d4;">Manual Setup Required</h4>
        <ol style="font-size: 0.9rem; margin-bottom: 0;">
            <li>Create an <strong>App Registration</strong> in the Entra ID portal.</li>
            <li>Add <strong>Microsoft Graph (Application)</strong> permissions: <code>Application.Read.All</code> and <code>ServicePrincipal.Read.All</code>.</li>
            <li>Grant <strong>Admin Consent</strong> for these permissions.</li>
            <li>Create a <strong>Client Secret</strong> and paste the values below.</li>
        </ol>
    </div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
        <div style="grid-column: span 2;">
            <label>Entra Tenant ID</label><br>
            <input type="text" name="entra_tenant_id" id="entra_tenant_id" value="{{ $settings['entra_tenant_id'] ?? '' }}" placeholder="00000000-0000-0000-0000-000000000000" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>Entra Client ID (App ID)</label><br>
            <input type="text" name="entra_client_id" id="entra_client_id" value="{{ $settings['entra_client_id'] ?? '' }}" placeholder="00000000-0000-0000-0000-000000000000" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>Entra Client Secret</label><br>
            <input type="password" name="entra_client_secret" id="entra_client_secret" value="{{ isset($settings['entra_client_secret']) ? '********' : '' }}" placeholder="Enter secret" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="grid-column: span 2;">
            <label>Ignore Expired items (Days since expiry)</label><br>
            <input type="number" name="entra_ignore_expired_days" value="{{ $settings['entra_ignore_expired_days'] ?? '30' }}" min="0" style="width:100%; padding:8px; border:1px solid #ddd;">
            <small style="color: #666;">Entra ID secrets/certificates expired longer than this many days will be ignored in alerts. Set to 0 to always alert for expired items without active replacement.</small>
        </div>
        <div style="grid-column: span 2;">
            <small style="color: #666;">Requires Microsoft Graph API permissions: <code>Application.Read.All</code> and <code>ServicePrincipal.Read.All</code>.</small>
        </div>
    </div>

    <hr>
    <h3>Archiving & Cleanup</h3>
    <div style="margin-bottom:15px">
        <label>Archive Expired Certificates (Days since expiry)</label><br>
        <input type="number" name="archive_threshold_days" value="{{ $settings['archive_threshold_days'] ?? '180' }}" min="1" style="width:100%; padding:8px; border:1px solid #ddd;">
        <small style="color: #666;">Certificates expired longer than this many days will be archived (private keys purged, certificates hidden in drawer).</small>
    </div>

    <hr>
    <h3>SMTP Settings</h3>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
        <div style="grid-column: span 2;">
            <label>Mail Host</label><br>
            <input type="text" name="mail_host" value="{{ $settings['mail_host'] ?? '' }}" placeholder="smtp.mailtrap.io" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>Mail Port</label><br>
            <input type="number" name="mail_port" value="{{ $settings['mail_port'] ?? '587' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>Mail Encryption</label><br>
            <select name="mail_encryption" style="width:100%; padding:8px; border:1px solid #ddd;">
                <option value="tls" {{ ($settings['mail_encryption'] ?? '') === 'tls' ? 'selected' : '' }}>TLS</option>
                <option value="ssl" {{ ($settings['mail_encryption'] ?? '') === 'ssl' ? 'selected' : '' }}>SSL</option>
                <option value="none" {{ ($settings['mail_encryption'] ?? '') === 'none' ? 'selected' : '' }}>None</option>
            </select>
        </div>
        <div>
            <label>Mail Username</label><br>
            <input type="text" name="mail_username" value="{{ $settings['mail_username'] ?? '' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>Mail Password</label><br>
            <input type="password" name="mail_password" value="{{ isset($settings['mail_password']) ? '********' : '' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>From Address</label><br>
            <input type="email" name="mail_from_address" value="{{ $settings['mail_from_address'] ?? 'noreply@example.com' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div>
            <label>From Name</label><br>
            <input type="text" name="mail_from_name" value="{{ $settings['mail_from_name'] ?? 'Cert Drawer' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
    </div>

    <hr>
    <h3>Webhook Notifications</h3>
    <p style="color: #666; font-size: 0.9rem;">Configure endpoints for automated JSON notifications. If a specific webhook is not set, the legacy "Alert Webhook" (Cert Health) will be used as fallback for certificate alerts.</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 20px;">
        <div>
            <h4 style="margin-bottom: 10px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 5px;">Cert Health</h4>
            <div style="margin-bottom:10px">
                <label>Webhook URL</label><br>
                <input type="text" name="cert_webhook_url" value="{{ $settings['cert_webhook_url'] ?? $settings['alert_webhook_url'] ?? '' }}" placeholder="https://hooks.example.com/certs" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
            <div>
                <label>Secret Key (Optional)</label><br>
                <input type="password" name="cert_webhook_secret" value="{{ isset($settings['cert_webhook_secret']) || isset($settings['alert_webhook_secret']) ? '********' : '' }}" placeholder="Secret key" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
        </div>

        <div>
            <h4 style="margin-bottom: 10px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 5px;">DNS Health</h4>
            <div style="margin-bottom:10px">
                <label>Webhook URL</label><br>
                <input type="text" name="dns_webhook_url" value="{{ $settings['dns_webhook_url'] ?? '' }}" placeholder="https://hooks.example.com/dns" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
            <div>
                <label>Secret Key (Optional)</label><br>
                <input type="password" name="dns_webhook_secret" value="{{ isset($settings['dns_webhook_secret']) ? '********' : '' }}" placeholder="Secret key" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
        </div>

        <div>
            <h4 style="margin-bottom: 10px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 5px;">Entra ID</h4>
            <div style="margin-bottom:10px">
                <label>Webhook URL</label><br>
                <input type="text" name="entra_webhook_url" value="{{ $settings['entra_webhook_url'] ?? '' }}" placeholder="https://hooks.example.com/entra" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
            <div>
                <label>Secret Key (Optional)</label><br>
                <input type="password" name="entra_webhook_secret" value="{{ isset($settings['entra_webhook_secret']) ? '********' : '' }}" placeholder="Secret key" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
        </div>

        <div>
            <h4 style="margin-bottom: 10px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 5px;">Automation & ACME</h4>
            <div style="margin-bottom:10px">
                <label>Webhook URL</label><br>
                <input type="text" name="automation_webhook_url" value="{{ $settings['automation_webhook_url'] ?? '' }}" placeholder="https://hooks.example.com/automation" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
            <div>
                <label>Secret Key (Optional)</label><br>
                <input type="password" name="automation_webhook_secret" value="{{ isset($settings['automation_webhook_secret']) ? '********' : '' }}" placeholder="Secret key" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
        </div>
    </div>

    <div style="margin-bottom:15px; background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #eee;">
        <label><strong>Test Webhook Integration</strong></label><br>
        <div style="display: flex; gap: 10px; margin-top: 10px;">
            <select id="test_webhook_type" style="padding:8px; border:1px solid #ddd; border-radius: 4px;">
                <option value="cert">Cert Health</option>
                <option value="dns">DNS Health</option>
                <option value="entra">Entra ID</option>
                <option value="automation">Automation</option>
            </select>
            <button type="button" onclick="sendTestWebhook()" class="btn" style="background: #6c757d; color: white;">Send Test Payload</button>
        </div>
        <small style="color: #666; display: block; margin-top: 5px;">This will save your settings first and then send a test payload to the selected webhook URL.</small>
    </div>

    <hr>
    <h3>Email Notifications</h3>
    <div style="margin-bottom:15px">
        <label>DNS Health Recipients (Comma-separated)</label><br>
        <input type="text" name="dns_mail_recipients" value="{{ $settings['dns_mail_recipients'] ?? '' }}" placeholder="admin@example.com, it@example.com" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>Cert Health Recipients (Comma-separated)</label><br>
        <input type="text" name="cert_mail_recipients" value="{{ $settings['cert_mail_recipients'] ?? '' }}" placeholder="admin@example.com, it@example.com" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>Entra ID Alert Recipients (Comma-separated)</label><br>
        <input type="text" name="entra_mail_recipients" value="{{ $settings['entra_mail_recipients'] ?? '' }}" placeholder="admin@example.com, it@example.com" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>Automation Recipients (Comma-separated)</label><br>
        <input type="text" name="automation_mail_recipients" value="{{ $settings['automation_mail_recipients'] ?? '' }}" placeholder="admin@example.com, it@example.com" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>

    <hr>
    <h3>Test SMTP Settings</h3>
    <div style="margin-bottom:15px; display: flex; gap: 10px; align-items: flex-end;">
        <div style="flex: 1;">
            <label>Test Recipient Email</label><br>
            <input type="email" name="test_recipient" placeholder="test@example.com" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <button type="button" onclick="sendTestEmail()" class="btn" style="background: #6c757d; color: white;">Send Test Email</button>
    </div>

    <button type="submit" class="btn btn-primary" style="margin-top: 20px;">Save General Settings</button>
</form>

<script>
    function sendTestEmail() {
        const form = document.getElementById('settings-form');
        form.action = "{{ route('settings.test-email') }}";
        form.submit();
    }

    function sendTestWebhook() {
        const type = document.getElementById('test_webhook_type').value;
        const form = document.getElementById('settings-form');
        
        // Add a hidden input for the type if it doesn't exist
        let typeInput = document.getElementById('webhook_test_type_input');
        if (!typeInput) {
            typeInput = document.createElement('input');
            typeInput.type = 'hidden';
            typeInput.name = 'webhook_type';
            typeInput.id = 'webhook_test_type_input';
            form.appendChild(typeInput);
        }
        typeInput.value = type;
        
        form.action = "{{ route('settings.test-webhook') }}";
        form.submit();
    }
</script>
@endsection
