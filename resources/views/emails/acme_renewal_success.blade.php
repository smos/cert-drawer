<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .container { width: 80%; margin: 20px auto; padding: 20px; border: 1px solid #eee; border-radius: 5px; }
        .header { background: #28a745; color: white; padding: 10px; border-radius: 5px 5px 0 0; }
        .content { padding: 20px; }
        .footer { font-size: 0.8em; color: #777; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table th, table td { text-align: left; padding: 8px; border-bottom: 1px solid #eee; }
        table th { background: #f9f9f9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ACME Certificate Renewed</h1>
        </div>
        <div class="content">
            <p>An ACME certificate has been successfully auto-renewed.</p>
            
            <h3>Details</h3>
            <table>
                <tr>
                    <th>Domain</th>
                    <td>{{ $certificate->domain->name }}</td>
                </tr>
                <tr>
                    <th>Expiry Date</th>
                    <td>{{ $certificate->expiry_date }}</td>
                </tr>
                <tr>
                    <th>Serial Number</th>
                    <td>{{ $certificate->serial_number ?? 'N/A' }}</td>
                </tr>
            </table>

            <p>Any configured automations for this domain have also been triggered.</p>
        </div>
        <div class="footer">
            This is an automated message from Cert Drawer.
        </div>
    </div>
</body>
</html>
