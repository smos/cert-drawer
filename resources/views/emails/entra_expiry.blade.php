<h2>Entra ID Application Expiry Alert</h2>

<p>The following secrets or certificates for Entra ID applications are expiring soon or have already expired:</p>

<table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width: 100%;">
    <thead>
        <tr style="background-color: #f2f2f2;">
            <th>Application</th>
            <th>Type</th>
            <th>Name/Hint</th>
            <th>Expiry Date</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
            @php
                $days = ceil(now()->diffInDays($item->end_date, false));
                $status = $days <= 0 ? 'EXPIRED' : $days . ' days remaining';
                $color = $days <= 0 ? '#e74c3c' : ($days <= 10 ? '#c0392b' : '#f1c40f');
            @endphp
            <tr>
                <td><strong>{{ $item->app->display_name }}</strong><br><small>App ID: {{ $item->app->app_id }}</small></td>
                <td>{{ ucfirst($item->type) }}</td>
                <td>{{ $item->display_name ?: 'N/A' }} @if($item->hint) (Hint: {{ $item->hint }}) @endif</td>
                <td style="color: {{ $color }}; font-weight: bold;">{{ $item->end_date->format('Y-m-d') }}</td>
                <td style="color: {{ $color }}; font-weight: bold;">{{ $status }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p style="margin-top: 20px;">Please take appropriate action to renew these secrets in the Entra ID portal.</p>
