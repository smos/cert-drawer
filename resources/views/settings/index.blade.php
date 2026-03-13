@extends('layouts.app')

@section('content')
@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
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

    <button type="submit" class="btn btn-primary" style="margin-top: 20px;">Save General Settings</button>
</form>
@endsection
