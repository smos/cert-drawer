@extends('layouts.app')

@section('content')
@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
    </div>
@endif

@if($errors->any())
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
        {{ $errors->first() }}
    </div>
@endif

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Device Automations</h2>
    <button class="btn btn-primary" onclick="openAddModal()">+ Add Automation</button>
</div>

<div style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
        <thead>
            <tr style="background: #f8f9fa; border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 12px 15px;">Domain</th>
                <th style="padding: 12px 15px;">Device Type</th>
                <th style="padding: 12px 15px;">Hostname / IP</th>
                <th style="padding: 12px 15px;">Settings</th>
                <th style="padding: 12px 15px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($automations as $auto)
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 12px 15px;"><strong>{{ $auto->domain->name }}</strong></td>
                    <td style="padding: 12px 15px;">
                        <span class="tag" style="background: #e9ecef; color: #495057; text-transform: uppercase; font-size: 0.7rem; font-weight: 600;">
                            {{ $auto->type }}
                        </span>
                    </td>
                    <td style="padding: 12px 15px;">{{ $auto->hostname }}</td>
                    <td style="padding: 12px 15px; font-family: monospace; font-size: 0.8rem;">
                        @if($auto->type === 'kemp')
                            Cert Name: auto_{{ str_replace('*', 'wildcard', $auto->domain->name) }}
                        @else
                            -
                        @endif
                    </td>
                    <td style="padding: 12px 15px; text-align: right;">
                        <div style="display: flex; gap: 5px; justify-content: flex-end;">
                            <form action="{{ route('automations.run', $auto->id) }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Upload and REPLACE certificate on device now?')">Run Now</button>
                            </form>
                            <form action="{{ route('automations.destroy', $auto->id) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm" style="background: #c0392b; color: white;" onclick="return confirm('Remove this automation?')">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            @if($automations->isEmpty())
                <tr>
                    <td colspan="5" style="padding: 30px; text-align: center; color: #888;">No automations configured yet.</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

<!-- Modal Overhaul -->
<div id="add-automation-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1001; border-radius:8px; width:500px; max-height: 90vh; overflow-y: auto;">
    <h3 id="modal-title">Add New Automation</h3>
    
    <div id="step-indicators" style="display: flex; gap: 10px; margin-bottom: 20px;">
        <div id="ind-1" style="flex: 1; height: 4px; background: #3498db; border-radius: 2px;"></div>
        <div id="ind-2" style="flex: 1; height: 4px; background: #eee; border-radius: 2px;"></div>
    </div>

    <form id="automation-form" action="{{ route('automations.store') }}" method="POST">
        @csrf
        
        <!-- STEP 1: Device Configuration -->
        <div id="step-1">
            <h4 style="margin-top: 0;">Step 1: Device Configuration</h4>
            
            <div style="margin-bottom:15px">
                <label style="font-weight: 600;">Manufacturer</label><br>
                <select name="type" id="device_type" required style="width:100%; padding:8px; border:1px solid #ddd;">
                    <option value="kemp">Kemp Loadmaster</option>
                    <option value="fortigate">Fortigate Firewall</option>
                    <option value="paloalto">Palo Alto Firewall</option>
                </select>
            </div>

            <div style="margin-bottom:15px">
                <label style="font-weight: 600;">Hostname / IP</label><br>
                <input type="text" name="hostname" id="hostname" required placeholder="10.0.0.50" style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>

            <div style="margin-bottom:15px">
                <label id="password-label" style="font-weight: 600;">API Key</label><br>
                <input type="password" name="password" id="password" required placeholder="Paste API Key here" style="width:100%; padding:8px; border:1px solid #ddd;">
                <small id="password-hint" style="color: #888;">For Kemp, ensure the API Key has permissions to 'addcert' and 'listcert'.</small>
            </div>

            <div id="test-result" style="margin-bottom: 15px; display: none; padding: 10px; border-radius: 4px; font-size: 0.85rem;"></div>

            <div style="margin-top: 30px; display: flex; justify-content: space-between;">
                <button type="button" class="btn" style="background: #eee;" onclick="closeModal()">Cancel</button>
                <div style="display: flex; gap: 10px;">
                    <button type="button" id="btn-test" class="btn" style="background: #f39c12; color: white;" onclick="testConnection()">Test Connection</button>
                    <button type="button" id="btn-to-step-2" class="btn btn-primary" disabled onclick="goToStep(2)">Continue</button>
                </div>
            </div>
        </div>

        <!-- STEP 2: Linking & Final Settings -->
        <div id="step-2" style="display: none;">
            <h4 style="margin-top: 0;">Step 2: Linking & Settings</h4>
            
            <div style="margin-bottom:15px">
                <label style="font-weight: 600;">Link to Domain Certificate</label><br>
                <p style="font-size: 0.8rem; color: #666; margin-bottom: 5px;">Which local certificate should be pushed to this device?</p>
                <select name="domain_id" id="domain_id" required style="width:100%; padding:8px; border:1px solid #ddd;">
                    <option value="" disabled selected>Select a domain...</option>
                    @foreach($domains as $domain)
                        <option value="{{ $domain->id }}">{{ $domain->name }}</option>
                    @endforeach
                </select>
            </div>

            <div id="check-result" style="margin-bottom: 15px; display: none; padding: 10px; border-radius: 4px; font-size: 0.85rem;"></div>

            <div id="kemp-settings" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #3498db;">
                <h5 style="margin-top: 0; margin-bottom: 10px;">Kemp Specific Settings</h5>
                <label style="font-size: 0.85rem;">The certificate will be named:</label><br>
                <code id="kemp-cert-name" style="font-weight: bold; color: #e74c3c;">auto_domain.com</code>
                <input type="hidden" name="config[mode]" value="auto_prefix">
            </div>

            <div id="generic-settings" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #95a5a6;">
                <p style="font-size: 0.85rem; color: #666; margin: 0;">Additional configuration for this manufacturer will be available in a future update.</p>
                <input type="hidden" name="config[mode]" value="default">
            </div>

            <div style="margin-top: 30px; display: flex; justify-content: space-between;">
                <button type="button" class="btn" style="background: #eee;" onclick="goToStep(1)">Back</button>
                <button type="submit" id="btn-save" class="btn btn-primary" disabled>Save Automation</button>
            </div>
        </div>
    </form>
</div>

<script>
    let connectionTested = false;

    function openAddModal() {
        document.getElementById('add-automation-modal').style.display = 'block';
        goToStep(1);
    }

    function closeModal() {
        document.getElementById('add-automation-modal').style.display = 'none';
        document.getElementById('automation-form').reset();
        connectionTested = false;
        document.getElementById('btn-to-step-2').disabled = true;
        document.getElementById('test-result').style.display = 'none';
        document.getElementById('check-result').style.display = 'none';
        document.getElementById('btn-save').disabled = true;
    }

    function goToStep(step) {
        document.getElementById('step-1').style.display = step === 1 ? 'block' : 'none';
        document.getElementById('step-2').style.display = step === 2 ? 'block' : 'none';
        
        document.getElementById('ind-1').style.background = step >= 1 ? '#3498db' : '#eee';
        document.getElementById('ind-2').style.background = step >= 2 ? '#3498db' : '#eee';

        if (step === 2) {
            updateStep2UI();
        }
    }

    function updateStep2UI() {
        const type = document.getElementById('device_type').value;
        const domainSelect = document.getElementById('domain_id');
        
        if (domainSelect.selectedIndex > 0) {
            const domainName = domainSelect.options[domainSelect.selectedIndex].text;
            document.getElementById('kemp-settings').style.display = type === 'kemp' ? 'block' : 'none';
            document.getElementById('generic-settings').style.display = type !== 'kemp' ? 'block' : 'none';
            
            if (type === 'kemp') {
                document.getElementById('kemp-cert-name').innerText = 'auto_' + domainName.replace('*', 'wildcard').replace(/\./g, '_');
            }
        } else {
            document.getElementById('kemp-settings').style.display = 'none';
            document.getElementById('generic-settings').style.display = 'none';
        }
    }

    // Domain selection change -> Check if cert exists on device
    document.getElementById('domain_id').addEventListener('change', async function() {
        updateStep2UI();
        
        const domainId = this.value;
        const type = document.getElementById('device_type').value;
        const hostname = document.getElementById('hostname').value;
        const password = document.getElementById('password').value;
        const checkDiv = document.getElementById('check-result');
        const saveBtn = document.getElementById('btn-save');

        if (!domainId) return;

        checkDiv.style.display = 'block';
        checkDiv.style.background = '#f8f9fa';
        checkDiv.style.color = '#666';
        checkDiv.innerHTML = '<em>Checking device for existing certificate...</em>';
        saveBtn.disabled = true;

        try {
            const response = await fetch('{{ route('automations.check-cert') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ domain_id: domainId, type, hostname, password })
            });

            const data = await response.json();

            if (data.success) {
                if (data.exists) {
                    checkDiv.style.background = '#fff3cd';
                    checkDiv.style.color = '#856404';
                    checkDiv.style.border = '1px solid #ffeeba';
                    checkDiv.innerHTML = `<strong>Notice:</strong> ${data.message}`;
                } else {
                    checkDiv.style.background = '#d1ecf1';
                    checkDiv.style.color = '#0c5460';
                    checkDiv.style.border = '1px solid #bee5eb';
                    checkDiv.innerHTML = `<strong>Info:</strong> ${data.message}`;
                }
                saveBtn.disabled = false;
            } else {
                checkDiv.style.background = '#f8d7da';
                checkDiv.style.color = '#721c24';
                checkDiv.style.border = '1px solid #f5c6cb';
                checkDiv.innerHTML = `<strong>Check Failed:</strong> ${data.message}`;
                saveBtn.disabled = false; // Allow saving anyway? Or block? Let's allow but warned.
            }
        } catch (error) {
            checkDiv.innerHTML = 'Error checking certificate: ' + error.message;
            saveBtn.disabled = false;
        }
    });

    document.getElementById('device_type').addEventListener('change', function() {
        const type = this.value;
        const label = document.getElementById('password-label');
        const hint = document.getElementById('password-hint');
        
        if (type === 'kemp') {
            label.innerText = 'API Key';
            hint.innerText = "For Kemp, ensure the API Key has permissions to 'addcert' and 'listcert'.";
        } else {
            label.innerText = 'API Key / Password';
            hint.innerText = "Enter the administrative password or API key for the device.";
        }
        
        // Reset test if type changes
        connectionTested = false;
        document.getElementById('btn-to-step-2').disabled = true;
        document.getElementById('test-result').style.display = 'none';
        document.getElementById('check-result').style.display = 'none';
    });

    async function testConnection() {
        const btn = document.getElementById('btn-test');
        const resultDiv = document.getElementById('test-result');
        const type = document.getElementById('device_type').value;
        const hostname = document.getElementById('hostname').value;
        const password = document.getElementById('password').value;

        if (!hostname || !password) {
            alert('Please enter hostname and API Key/Password');
            return;
        }

        btn.disabled = true;
        btn.innerText = 'Testing...';
        resultDiv.style.display = 'none';

        try {
            const response = await fetch('{{ route('automations.test') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ type, hostname, password })
            });

            const data = await response.json();

            resultDiv.style.display = 'block';
            if (data.success) {
                resultDiv.style.background = '#d4edda';
                resultDiv.style.color = '#155724';
                resultDiv.style.border = '1px solid #c3e6cb';
                resultDiv.innerHTML = `<strong>Success!</strong> ${data.message}<br>Found ${data.count} existing certificates.`;
                connectionTested = true;
                document.getElementById('btn-to-step-2').disabled = false;
            } else {
                resultDiv.style.background = '#f8d7da';
                resultDiv.style.color = '#721c24';
                resultDiv.style.border = '1px solid #f5c6cb';
                resultDiv.innerHTML = `<strong>Failed:</strong> ${data.message}`;
                connectionTested = false;
                document.getElementById('btn-to-step-2').disabled = true;
            }
        } catch (error) {
            resultDiv.style.display = 'block';
            resultDiv.style.background = '#f8d7da';
            resultDiv.style.color = '#721c24';
            resultDiv.style.border = '1px solid #f5c6cb';
            resultDiv.innerText = 'Error: ' + error.message;
            connectionTested = false;
        } finally {
            btn.disabled = false;
            btn.innerText = 'Test Connection';
        }
    }
</script>
@endsection
