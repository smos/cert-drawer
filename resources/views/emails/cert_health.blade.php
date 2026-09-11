<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.5; color: #1e293b; background-color: #f1f5f9; margin: 0; padding: 20px; }
        .container { max-width: 680px; margin: 0 auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .header { background: #1e293b; color: #ffffff; padding: 20px 24px; }
        .header h2 { margin: 0; font-size: 18px; font-weight: 600; }
        .header .subtitle { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .content { padding: 20px 24px; }
        .section-title { font-size: 15px; font-weight: 600; color: #0f172a; margin-top: 20px; margin-bottom: 4px; }
        .section-subtitle { font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 14px; }
        .card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; margin-bottom: 12px; }
        .card-header { width: 100%; margin-bottom: 6px; }
        .domain-name { font-size: 15px; font-weight: 600; color: #0f172a; }
        .badge-ips { font-size: 11px; background: #e2e8f0; color: #334155; padding: 2px 7px; border-radius: 10px; font-weight: 600; }
        .single-ip { font-size: 12px; color: #64748b; font-family: monospace; }
        .ip-list { font-size: 11px; color: #64748b; font-family: monospace; margin-bottom: 10px; word-break: break-all; }
        .meta-table { width: 100%; font-size: 13px; color: #334155; border-collapse: collapse; }
        .meta-table td { padding: 3px 0; vertical-align: top; }
        .meta-label { width: 85px; color: #64748b; }
        .thumb-old { font-family: monospace; background: #fee2e2; color: #991b1b; padding: 2px 5px; border-radius: 3px; font-size: 12px; text-decoration: line-through; }
        .thumb-new { font-family: monospace; background: #dcfce7; color: #166534; padding: 2px 5px; border-radius: 3px; font-size: 12px; font-weight: 600; }
        .alert-box { padding: 8px 12px; font-size: 13px; border-radius: 4px; margin-top: 4px; }
        .alert-error { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }
        .alert-success { background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534; }
        .expiry-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        .expiry-table th { background: #f8fafc; color: #475569; padding: 8px 10px; border: 1px solid #e2e8f0; text-align: left; font-size: 12px; }
        .expiry-table td { padding: 8px 10px; border: 1px solid #e2e8f0; vertical-align: middle; }
        .badge-severity { padding: 3px 8px; border-radius: 10px; font-weight: bold; font-size: 11px; display: inline-block; white-space: nowrap; }
        .footer { padding: 14px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center; }
        .footer a { color: #2563eb; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Certificate Health Alert</h2>
            <div class="subtitle">Automated monitoring scan &bull; {{ now()->toDateTimeString() }}</div>
        </div>
        <div class="content">
            @if(!empty($changes))
                <div class="section-title" style="margin-top: 0;">Certificate Changes Detected</div>
                <div class="section-subtitle">The following domains had changes in their certificate or connectivity status.</div>

                @php
                    $groupedByDomain = collect($changes)->groupBy(function($item) {
                        if (is_object($item['domain'] ?? null)) {
                            return $item['domain']->name ?? 'Unknown';
                        }
                        return $item['domain']['name'] ?? (is_string($item['domain'] ?? null) ? $item['domain'] : 'Unknown');
                    });
                @endphp

                @foreach($groupedByDomain as $domainName => $domainChanges)
                    @php
                        $hasMismatch = $domainChanges->contains('has_mismatch', true);
                        if (!$hasMismatch) {
                            foreach (['internal', 'external'] as $chkType) {
                                $typeThumbs = $domainChanges->filter(function($c) use ($chkType) {
                                    return ($c['check_type'] ?? ($c['new']['check_type'] ?? null)) === $chkType;
                                })->pluck('new.thumbprint_sha256')->filter()->unique();
                                if ($typeThumbs->count() > 1) {
                                    $hasMismatch = true;
                                    break;
                                }
                            }
                        }
                    @endphp

                    @if($hasMismatch)
                        <div class="alert-box" style="background: #fff7ed; border-left: 4px solid #ea580c; color: #9a3412; margin-bottom: 12px; padding: 12px 14px; border-radius: 4px;">
                            <strong style="font-size: 14px;">⚠️ Certificate Mismatch Warning: Differing Fingerprints Detected Across Host IPs!</strong><br>
                            <span style="font-size: 12px; line-height: 1.4; display: inline-block; margin-top: 4px;">Different IP addresses for <strong>{{ $domainName }}</strong> within the same network face (internal or external) are serving different SSL/TLS certificates. This typically indicates an incomplete rollout across load balancers/servers or a misconfigured backend. Each certificate group is listed below:</span>
                        </div>
                    @endif

                    @foreach($domainChanges as $change)
                        @php
                            $ips = [];
                            if (!empty($change['ips']) && is_array($change['ips'])) {
                                $ips = $change['ips'];
                            } elseif (!empty($change['ip'])) {
                                $ips = array_map('trim', explode(',', $change['ip']));
                            }
                            $ipCount = count($ips);
                        @endphp
                        <div class="card">
                            <table class="card-header" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="left">
                                        <span class="domain-name">{{ $domainName }}</span>
                                    </td>
                                    <td align="right">
                                        @if(!empty($change['has_mismatch']))
                                            <span style="font-size: 11px; background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 10px; font-weight: 600; margin-right: 4px;">⚠️ Differing Cert</span>
                                        @endif
                                        @php
                                            $checkTypeLabel = $change['check_type'] ?? ($change['new']['check_type'] ?? null);
                                        @endphp
                                        @if($checkTypeLabel)
                                            <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 2px 7px; border-radius: 10px; font-weight: 600; margin-right: 4px;">{{ ucfirst($checkTypeLabel) }}</span>
                                        @endif
                                        @if($ipCount > 1)
                                            <span class="badge-ips">{{ $ipCount }} IP addresses</span>
                                        @elseif($ipCount === 1)
                                            <span class="single-ip">IP: {{ $ips[0] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            @if($ipCount > 1)
                                <div class="ip-list">
                                    IPs: {{ implode(', ', $ips) }}
                                </div>
                            @endif

                            @if(!empty($change['is_divergent']))
                                <div style="font-size: 12px; color: #b45309; margin-bottom: 6px; font-weight: 500;">
                                    <strong>Status: Divergent Host</strong> &mdash; This host did not update and is serving a different certificate:
                                </div>
                            @endif

                            @if(!empty($change['new']['error']))
                                <div class="alert-box alert-error">
                                    <strong>Status: Error</strong> &mdash; {{ $change['new']['error'] }}
                                </div>
                            @elseif(!empty($change['old']['error']))
                                <div class="alert-box alert-success" style="margin-bottom: 8px;">
                                    <strong>Status: Resolved</strong> &mdash; Connectivity restored. Host is healthy.
                                </div>
                                <table class="meta-table" cellpadding="0" cellspacing="0">
                                    @if(!empty($change['new']['issuer']))
                                        <tr><td class="meta-label">Issuer:</td><td><strong>{{ $change['new']['issuer'] }}</strong></td></tr>
                                    @endif
                                    @if(!empty($change['new']['expiry_date']))
                                        <tr><td class="meta-label">Expiry:</td><td><strong>{{ $change['new']['expiry_date'] }}</strong></td></tr>
                                    @endif
                                </table>
                            @else
                                <table class="meta-table" cellpadding="0" cellspacing="0">
                                    @if(!empty($change['old']['thumbprint_sha256']) && !empty($change['new']['thumbprint_sha256']))
                                        <tr>
                                            <td class="meta-label">Fingerprint:</td>
                                            <td>
                                                <span class="thumb-old">{{ substr($change['old']['thumbprint_sha256'], 0, 10) }}...</span>
                                                &rarr;
                                                <span class="thumb-new">{{ substr($change['new']['thumbprint_sha256'], 0, 10) }}...</span>
                                            </td>
                                        </tr>
                                    @endif
                                    @if(!empty($change['new']['issuer']))
                                        <tr>
                                            <td class="meta-label">Issuer:</td>
                                            <td>{{ $change['new']['issuer'] }}</td>
                                        </tr>
                                    @endif
                                    @if(!empty($change['new']['expiry_date']))
                                        <tr>
                                            <td class="meta-label">Expiry:</td>
                                            <td><strong>{{ $change['new']['expiry_date'] }}</strong></td>
                                        </tr>
                                    @endif
                                </table>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            @endif

            @if(!empty($expiryAlerts))
                <div class="section-title">Upcoming Expiry Alerts</div>
                <div class="section-subtitle">The following certificates are approaching their expiration threshold.</div>

                <table class="expiry-table">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Expiry Date</th>
                            <th>Remaining</th>
                            <th>Threshold / Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($expiryAlerts as $alert)
                            @php
                                $alertDomain = is_object($alert['domain'] ?? null) 
                                    ? $alert['domain']->name 
                                    : ($alert['domain']['name'] ?? (is_string($alert['domain'] ?? null) ? $alert['domain'] : 'Unknown'));
                                $alertIps = [];
                                if (!empty($alert['ips']) && is_array($alert['ips'])) {
                                    $alertIps = $alert['ips'];
                                } elseif (!empty($alert['ip']) && $alert['ip'] !== 'N/A (Database Record)') {
                                    $alertIps = array_map('trim', explode(',', $alert['ip']));
                                }
                                $alertIpCount = count($alertIps);
                                
                                $badgeBg = '#dcfce7';
                                $badgeColor = '#166534';
                                if ($alert['days'] <= $thresholds['red']) {
                                    $badgeBg = '#fee2e2';
                                    $badgeColor = '#991b1b';
                                } elseif ($alert['days'] <= $thresholds['orange']) {
                                    $badgeBg = '#ffedd5';
                                    $badgeColor = '#9a3412';
                                } elseif ($alert['days'] <= $thresholds['yellow']) {
                                    $badgeBg = '#fef9c3';
                                    $badgeColor = '#854d0e';
                                }
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $alertDomain }}</strong>
                                    @if($alertIpCount > 1)
                                        <br><span style="color: #64748b; font-size: 11px; font-family: monospace;">{{ $alertIpCount }} IPs: {{ implode(', ', $alertIps) }}</span>
                                    @elseif($alertIpCount === 1)
                                        <br><span style="color: #64748b; font-size: 11px; font-family: monospace;">IP: {{ $alertIps[0] }}</span>
                                    @endif
                                </td>
                                <td style="font-weight: 600; white-space: nowrap;">{{ $alert['expiry'] }}</td>
                                <td>
                                    <span class="badge-severity" style="background: {{ $badgeBg }}; color: {{ $badgeColor }};">
                                        {{ $alert['days'] <= 0 ? 'EXPIRED' : $alert['days'] . ' days' }}
                                    </span>
                                </td>
                                <td style="color: #475569; font-size: 12px;">{{ $alert['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="footer">
            This is an automated notification from <a href="{{ config('app.url') }}">Cert Drawer</a>.
        </div>
    </div>
</body>
</html>
