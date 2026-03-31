@extends('layouts.app')

@section('content')
@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
    </div>
@endif

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Live Certificate Health Monitoring</h2>
    <form action="{{ route('cert-health.check-all') }}" method="POST">
        @csrf
        <button type="submit" class="btn btn-primary">Check All Domains (Now)</button>
    </form>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 10px;">Domain</th>
                <th style="padding: 10px;">Status</th>
                <th style="padding: 10px;">Resolved IP Checks</th>
                <th style="padding: 10px;">Last Check</th>
            </tr>
        </thead>
        <tbody>
            @foreach($domains as $domain)
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px; font-weight: 600;">
                        {{ $domain->name }}
                        @if($domain->mismatch)
                            <div style="color: #e67e22; font-size: 0.75rem; margin-top: 5px;">
                                ⚠️ <strong>Mismatch:</strong> Multiple certificates found within the same check type!
                            </div>
                        @endif
                        @if($domain->global_mismatch)
                            <div style="color: #3498db; font-size: 0.75rem; margin-top: 5px;">
                                ℹ️ <strong>Different Certs:</strong> Internal and External results return different certificates.
                            </div>
                        @endif
                        @if($domain->has_errors)
                            <div style="color: #c0392b; font-size: 0.75rem; margin-top: 5px;">
                                ⚠️ Some IP checks failed to connect.
                            </div>
                        @endif
                    </td>
                    <td style="padding: 10px;">
                        @switch($domain->health_status)
                            @case('expired')
                                <span class="tag" style="background: #e74c3c; color: white;">Expired</span>
                                @break
                            @case('critical')
                                <span class="tag" style="background: #c0392b; color: white;">Critical</span>
                                @break
                            @case('urgent')
                                <span class="tag" style="background: #e67e22; color: white;">Urgent</span>
                                @break
                            @case('warning')
                                <span class="tag" style="background: #f1c40f; color: black;">Warning</span>
                                @break
                            @case('healthy')
                                <span class="tag" style="background: #27ae60; color: white;">Healthy</span>
                                @break
                            @default
                                <span class="tag" style="background: #95a5a6; color: white;">No Data</span>
                        @endswitch
                    </td>
                    <td style="padding: 10px;">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            @foreach(['internal' => 'Internal (Local/Poller)', 'external' => 'External (Resolver)'] as $type => $label)
                                @if(isset($domain->health_logs[$type]))
                                    @php
                                        $logs = $domain->health_logs[$type];
                                        $hasTypeErrors = $logs->whereNotNull('error')->count() > 0;
                                        $typeThumbprints = $logs->whereNotNull('thumbprint_sha256')->pluck('thumbprint_sha256')->unique();
                                        $hasTypeMismatch = $typeThumbprints->count() > 1;
                                        $isCollapsed = !$hasTypeErrors && !$hasTypeMismatch;
                                    @endphp
                                    <div class="poller-section">
                                        <div style="font-size: 0.75rem; font-weight: bold; text-transform: uppercase; color: #777; margin-bottom: 3px; cursor: pointer; display: flex; align-items: center; gap: 5px;" 
                                             onclick="togglePoller(this)">
                                            <span>{{ $isCollapsed ? '▶' : '▼' }}</span> {{ $label }}
                                            @if($isCollapsed)
                                                <span style="font-weight: normal; text-transform: none; color: #27ae60; margin-left: 5px;">({{ $logs->count() }} IPs Healthy &check;)</span>
                                            @endif
                                        </div>
                                        <div class="poller-content" style="display: {{ $isCollapsed ? 'none' : 'flex' }}; flex-direction: column; gap: 4px;">
                                            @foreach($logs as $log)
                                                @php
                                                    $tooltip = $log->error ?: "Issuer: " . ($log->issuer ?: 'N/A') . "\nThumb: " . $log->thumbprint_sha256 . "\nExpires: " . ($log->expiry_date ? $log->expiry_date->format('Y-m-d H:i') : 'N/A');
                                                @endphp
                                                <div style="font-size: 0.85rem; border: 1px solid #f0f0f0; padding: 5px; border-radius: 4px;" title="{{ $tooltip }}">
                                                    <strong>[{{ $log->ip_version }}]</strong> {{ $log->ip_address }} 
                                                    @if($log->error)
                                                        <span style="color: #c0392b;">&times; {{ $log->error }}</span>
                                                    @else
                                                        <span style="color: #27ae60;">&check;</span> 
                                                        <small style="color: #666;">
                                                            <code>{{ substr($log->thumbprint_sha256, 0, 8) }}...</code> 
                                                        </small>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                            @if($domain->health_logs->isEmpty())
                                <span style="color: #95a5a6; font-style: italic;">No check data available.</span>
                            @endif
                        </div>
                    </td>
                    <td style="padding: 10px; color: #666; font-size: 0.85rem;">
                        <div>{{ $domain->last_cert_check ? $domain->last_cert_check->diffForHumans() : 'Never' }}</div>
                        <div style="margin-top: 10px; display: flex; gap: 5px;">
                            <form action="{{ route('cert-health.check-domain', $domain) }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-sm" style="padding: 2px 8px; font-size: 0.7rem;">Check Now</button>
                            </form>
                            <form action="{{ route('cert-health.purge', $domain) }}" method="POST" onsubmit="return confirm('Purge all health history for this domain?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm" style="padding: 2px 8px; font-size: 0.7rem; background: #e74c3c; color: white;">Purge</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection

<script>
function togglePoller(header) {
    const content = header.nextElementSibling;
    const arrow = header.querySelector('span');
    const healthySpan = header.querySelector('span[style*="color: #27ae60"]');
    
    if (content.style.display === 'none') {
        content.style.display = 'flex';
        arrow.innerText = '▼';
        if (healthySpan) healthySpan.style.display = 'none';
    } else {
        content.style.display = 'none';
        arrow.innerText = '▶';
        if (healthySpan) healthySpan.style.display = 'inline';
    }
}
</script>
