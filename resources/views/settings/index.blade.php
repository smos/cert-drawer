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
    <h3>Email Notifications</h3>
    <div style="margin-bottom:15px">
        <label>DNS Health Recipients (Comma-separated)</label><br>
        <input type="text" name="dns_mail_recipients" value="{{ $settings['dns_mail_recipients'] ?? '' }}" placeholder="admin@example.com, it@example.com" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>Cert Health Recipients (Comma-separated)</label><br>
        <input type="text" name="cert_mail_recipients" value="{{ $settings['cert_mail_recipients'] ?? '' }}" placeholder="admin@example.com, it@example.com" style="width:100%; padding:8px; border:1px solid #ddd;">
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
        const originalAction = form.action;
        
        // Temporarily change form action to the test-email route
        form.action = "{{ route('settings.test-email') }}";
        form.submit();
        
        // The page will reload anyway, so no need to reset action here.
    }
</script>
@endsection
