<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .domain { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .change { margin-left: 20px; font-size: 0.9em; }
        .error { color: #d9534f; }
        .status-critical { color: #c0392b; font-weight: bold; }
        .status-urgent { color: #e67e22; font-weight: bold; }
        .status-warning { color: #f1c40f; font-weight: bold; }
        .status-healthy { color: #2ecc71; font-weight: bold; }
        .thumbprint { font-family: monospace; font-size: 0.8em; }
        .date { font-weight: bold; }
    </style>
</head>
<body>
    <h2>Certificate Health Alert - {{ now()->toDateTimeString() }}</h2>
    <p>The automated certificate monitoring run detected important events.</p>

    @if(!empty($changes))
        <h3>Certificate Changes Detected</h3>
        <p>The following domains had changes in their thumbprint or connectivity status.</p>
        @foreach($changes as $change)
            <div class="domain">
                <strong>{{ $change['domain']->name }}</strong> (IP: {{ $change['ip'] }})
                <div class="change">
                    @if($change['new']['error'])
                        <span class="error">Status Change: Error - {{ $change['new']['error'] }}</span>
                    @elseif($change['old']['error'])
                        <span class="status-change">Status Change: Resolved! Now Healthy.</span><br>
                        <span>Issuer: {{ $change['new']['issuer'] }}</span><br>
                        <span>Expiry: <span class="date">{{ $change['new']['expiry_date'] }}</span></span>
                    @else
                        <span>Certificate changed on the host.</span><br>
                        <span>Old Thumbprint: <span class="thumbprint">{{ substr($change['old']['thumbprint_sha256'], 0, 8) }}...</span></span><br>
                        <span>New Thumbprint: <span class="thumbprint">{{ substr($change['new']['thumbprint_sha256'], 0, 8) }}...</span></span><br>
                        <span>New Issuer: {{ $change['new']['issuer'] }}</span><br>
                        <span>New Expiry: <span class="date">{{ $change['new']['expiry_date'] }}</span></span>
                    @endif
                </div>
            </div>
        @endforeach
    @endif

    @if(!empty($expiryAlerts))
        <hr>
        <h3>Upcoming Expiry Alerts</h3>
        <p>The following certificates are approaching their expiration date.</p>
        @foreach($expiryAlerts as $alert)
            @php
                $colorClass = 'status-healthy';
                if ($alert['days'] <= $thresholds['red']) {
                    $colorClass = 'status-critical';
                } elseif ($alert['days'] <= $thresholds['orange']) {
                    $colorClass = 'status-urgent';
                } elseif ($alert['days'] <= $thresholds['yellow']) {
                    $colorClass = 'status-warning';
                }
            @endphp
            <div class="domain">
                <strong>{{ $alert['domain']->name }}</strong> (IP: {{ $alert['ip'] }})
                <div class="change">
                    <span class="{{ $colorClass }}">{{ $alert['reason'] }}</span><br>
                    <span>Expiry: <span class="date">{{ $alert['expiry'] }}</span> ({{ $alert['days'] }} days remaining)</span>
                </div>
            </div>
        @endforeach
    @endif

    <hr>
    <p><small>This is an automated notification from Cert Drawer.</small></p>
</body>
</html>
