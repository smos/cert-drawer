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
                            Cert Name: auto_{{ str_replace('*', 'wildcard', str_replace('.', '_', $auto->domain->name)) }}
                        @elseif($auto->type === 'fortigate')
                            Roles: 
                            @php
                                $roles = [];
                                if(isset($auto->config['roles']['vpn_ssl']) && $auto->config['roles']['vpn_ssl']) $roles[] = 'SSL-VPN';
                                if(isset($auto->config['roles']['web_ui']) && $auto->config['roles']['web_ui']) $roles[] = 'WebUI';
                                echo !empty($roles) ? implode(', ', $roles) : 'None';
                            @endphp
                        @elseif($auto->type === 'paloalto')
                            Profiles: {{ $auto->config['profiles_string'] ?? 'None' }}
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
                            <button type="button" class="btn btn-sm" style="background: #f39c12; color: white;" onclick="openEditModal({{ $auto->id }})">Edit</button>
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

<!-- Modal -->
<div id="add-automation-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1001; border-radius:8px; width:500px; max-height: 90vh; overflow-y: auto;">
    <h3 id="modal-title">Add New Automation</h3>
    
    <div id="step-indicators" style="display: flex; gap: 10px; margin-bottom: 20px;">
        <div id="ind-1" style="flex: 1; height: 4px; background: #3498db; border-radius: 2px;"></div>
        <div id="ind-2" style="flex: 1; height: 4px; background: #eee; border-radius: 2px;"></div>
    </div>

    <form id="automation-form" action="{{ route('automations.store') }}" method="POST">
        @csrf
        <div id="method-container"></div>
        
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

            <div id="fortigate-settings" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #e67e22;">
                <h5 style="margin-top: 0; margin-bottom: 10px;">Fortigate Roles</h5>
                <p style="font-size: 0.8rem; color: #666; margin-bottom: 10px;">Select which services should use the new certificate:</p>
                
                <div style="margin-bottom: 8px;">
                    <label style="display: flex; align-items: center; cursor: pointer; font-size: 0.9rem;">
                        <input type="checkbox" name="config[roles][vpn_ssl]" value="1" id="role_vpn_ssl" checked style="margin-right: 10px;">
                        SSL VPN Settings (servercert)
                    </label>
                </div>
                
                <div style="margin-bottom: 8px;">
                    <label style="display: flex; align-items: center; cursor: pointer; font-size: 0.9rem;">
                        <input type="checkbox" name="config[roles][web_ui]" value="1" id="role_web_ui" style="margin-right: 10px;">
                        Admin Web UI (admin-server-cert)
                    </label>
                </div>

                <div style="margin-bottom: 8px;">
                    <label style="display: flex; align-items: center; cursor: pointer; font-size: 0.9rem; color: #888;">
                        <input type="checkbox" name="config[roles][ssl_decryption]" value="1" id="role_ssl_decryption" disabled style="margin-right: 10px;">
                        SSL Decryption / Deep Inspection (Coming Soon)
                    </label>
                </div>
            </div>

            <div id="paloalto-settings" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #34495e;">
                <h5 style="margin-top: 0; margin-bottom: 10px;">Palo Alto Configuration</h5>
                <p style="font-size: 0.8rem; color: #666; margin-bottom: 10px;">The certificate will be imported to the shared certificate store.</p>
                
                <div style="margin-bottom: 10px;">
                    <label style="font-weight: 600; font-size: 0.85rem;">SSL/TLS Service Profiles to Update</label><br>
                    <p style="font-size: 0.75rem; color: #888; margin-bottom: 5px;">Comma-separated list of profile names (e.g., GP-Portal, Management-Profile)</p>
                    <input type="text" name="config[profiles_string]" id="pa_profiles" placeholder="Profile-1, Profile-2" style="width:100%; padding:8px; border:1px solid #ddd; font-size: 0.9rem;">
                </div>
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
    let editMode = false;

    function openAddModal() {
        editMode = false;
        document.getElementById('modal-title').innerText = 'Add New Automation';
        document.getElementById('automation-form').action = '{{ route('automations.store') }}';
        document.getElementById('method-container').innerHTML = '';
        document.getElementById('password').required = true;
        document.getElementById('password-hint').innerText = "For Kemp, ensure the API Key has permissions to 'addcert' and 'listcert'.";
        
        document.getElementById('add-automation-modal').style.display = 'block';
        goToStep(1);
    }

    async function openEditModal(id) {
        editMode = true;
        document.getElementById('modal-title').innerText = 'Edit Automation';
        document.getElementById('automation-form').action = `/automations/${id}`;
        document.getElementById('method-container').innerHTML = '<input type="hidden" name="_method" value="PUT">';
        document.getElementById('password').required = false;
        document.getElementById('password-hint').innerText = "Leave blank to keep current password.";

        document.getElementById('add-automation-modal').style.display = 'block';
        
        try {
            const response = await fetch(`/automations/${id}`);
            const data = await response.json();
            
            if (data.success) {
                const auto = data.automation;
                document.getElementById('device_type').value = auto.type;
                document.getElementById('hostname').value = auto.hostname;
                document.getElementById('domain_id').value = auto.domain_id;
                
                // Reset checkboxes
                document.getElementById('role_vpn_ssl').checked = false;
                document.getElementById('role_web_ui').checked = false;

                if (auto.type === 'fortigate' && auto.config && auto.config.roles) {
                    document.getElementById('role_vpn_ssl').checked = !!auto.config.roles.vpn_ssl;
                    document.getElementById('role_web_ui').checked = !!auto.config.roles.web_ui;
                }

                if (auto.type === 'paloalto' && auto.config) {
                    document.getElementById('pa_profiles').value = auto.config.profiles_string || '';
                }

                connectionTested = true;
                document.getElementById('btn-to-step-2').disabled = false;
                goToStep(1);
            }
        } catch (error) {
            alert('Error fetching automation data');
        }
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
        
        document.getElementById('kemp-settings').style.display = type === 'kemp' ? 'block' : 'none';
        document.getElementById('fortigate-settings').style.display = type === 'fortigate' ? 'block' : 'none';
        document.getElementById('paloalto-settings').style.display = type === 'paloalto' ? 'block' : 'none';
        document.getElementById('generic-settings').style.display = (type !== 'kemp' && type !== 'fortigate' && type !== 'paloalto') ? 'block' : 'none';

        if (domainSelect.selectedIndex > 0) {
            const domainName = domainSelect.options[domainSelect.selectedIndex].text;
            if (type === 'kemp') {
                document.getElementById('kemp-cert-name').innerText = 'auto_' + domainName.replace('*', 'wildcard').replace(/\./g, '_');
            }
        }
    }

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
            checkDiv.innerHTML = data.message;
            saveBtn.disabled = false;
        } catch (error) {
            checkDiv.innerHTML = 'Error checking certificate';
            saveBtn.disabled = false;
        }
    });

    document.getElementById('device_type').addEventListener('change', function() {
        const type = this.value;
        const label = document.getElementById('password-label');
        if (type === 'kemp') {
            label.innerText = 'API Key';
        } else {
            label.innerText = 'API Key / Password';
        }
        connectionTested = false;
        document.getElementById('btn-to-step-2').disabled = true;
    });

    async function testConnection() {
        const btn = document.getElementById('btn-test');
        const resultDiv = document.getElementById('test-result');
        const type = document.getElementById('device_type').value;
        const hostname = document.getElementById('hostname').value;
        const password = document.getElementById('password').value;

        btn.disabled = true;
        btn.innerText = 'Testing...';

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
            resultDiv.innerHTML = data.message;
            
            if (data.success) {
                connectionTested = true;
                document.getElementById('btn-to-step-2').disabled = false;
            }
        } catch (error) {
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = 'Connection failed';
        } finally {
            btn.disabled = false;
            btn.innerText = 'Test Connection';
        }
    }
</script>
@endsection
