<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .container { width: 80%; margin: 20px auto; padding: 20px; border: 1px solid #eee; border-radius: 5px; }
        .header { background: #dc3545; color: white; padding: 10px; border-radius: 5px 5px 0 0; }
        .content { padding: 20px; }
        .footer { font-size: 0.8em; color: #777; margin-top: 20px; }
        .error-box { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; border: 1px solid #f5c6cb; font-family: monospace; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table th, table td { text-align: left; padding: 8px; border-bottom: 1px solid #eee; }
        table th { background: #f9f9f9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Automation Failed</h1>
        </div>
        <div class="content">
            <p>An automated certificate deployment has failed.</p>
            
            <h3>Error Details</h3>
            <div class="error-box">
                {{ $errorMessage }}
            </div>

            <h3>Automation Context</h3>
            <table>
                <tr>
                    <th>Domain</th>
                    <td>{{ $automation->domain->name }}</td>
                </tr>
                <tr>
                    <th>Type</th>
                    <td>{{ strtoupper($automation->type) }}</td>
                </tr>
                <tr>
                    <th>Hostname</th>
                    <td>{{ $automation->hostname }}</td>
                </tr>
                <tr>
                    <th>Certificate Serial</th>
                    <td>{{ $certificate->serial_number ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <th>Certificate Expiry</th>
                    <td>{{ $certificate->expiry_date }}</td>
                </tr>
            </table>

            <p>Please log in to the Cert Drawer to investigate and retry the deployment manually if needed.</p>
        </div>
        <div class="footer">
            This is an automated message from <a href="{{ config('app.url') }}">Cert Drawer</a>.
        </div>
    </div>
</body>
</html>
