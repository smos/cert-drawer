<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .header { background: #c0392b; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 8px 8px; }
        .error { color: #c0392b; font-weight: bold; background: #fdf2f2; padding: 10px; border-left: 5px solid #c0392b; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h2 style="margin:0;">ACME Renewal Failed</h2>
    </div>
    <div class="content">
        <p>The automatic ACME renewal for <strong>{{ $certificate->domain->name }}</strong> has failed.</p>
        
        <p><strong>Certificate Details:</strong></p>
        <ul>
            <li>Domain: {{ $certificate->domain->name }}</li>
            <li>Current Expiry: {{ $certificate->expiry_date->format('Y-m-d H:i:s') }}</li>
            <li>Thumbprint: {{ $certificate->thumbprint_sha1 }}</li>
        </ul>

        <div class="error">
            Error Message: {{ $errorMessage }}
        </div>

        <p>Please log in to Cert Drawer and check the Audit Logs or ACME service status for more details.</p>
        
        <hr>
        <p style="font-size: 0.8rem; color: #888;">This is an automated notification from Cert Drawer.</p>
    </div>
</body>
</html>
