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
    <div style="display: flex; gap: 10px; align-items: center;">
        <div style="background: white; padding: 10px 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); font-size: 0.85rem; display: flex; gap: 20px;">
            <div>
                <span style="color: #666;">Scheduler Heartbeat:</span> 
                @if($schedulerLastRun)
                    <strong style="color: {{ \Carbon\Carbon::parse($schedulerLastRun)->isAfter(now()->subMinutes(5)) ? '#27ae60' : '#c0392b' }};">
                        {{ \Carbon\Carbon::parse($schedulerLastRun)->diffForHumans() }}
                    </strong>
                @else
                    <strong style="color: #c0392b;">Never</strong>
                @endif
            </div>
            <div>
                <span style="color: #666;">Last Auto-Cleanup:</span>
                @if($lastCleanup)
                    <strong style="color: #2980b9;" title="{{ $lastCleanup->created_at }}">
                        {{ $lastCleanup->created_at->diffForHumans() }}
                    </strong>
                @else
                    <strong style="color: #888;">No history</strong>
                @endif
            </div>
            <div>
                <span style="color: #666;">Last Archive:</span>
                @if($lastArchive)
                    <strong style="color: #2980b9;" title="{{ $lastArchive->created_at }}">
                        {{ $lastArchive->created_at->diffForHumans() }}
                    </strong>
                @else
                    <strong style="color: #888;">No history</strong>
                @endif
            </div>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">+ Add Automation</button>
    </div>
</div>

<div class="table-responsive" style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
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
                    <td style="padding: 12px 15px;">
                        <strong>{{ $auto->domain->name }}</strong>
                        @php
                            $latestCert = $auto->domain->certificates()->where('status', 'issued')->latest()->first();
                            $incomplete = false;
                            if ($latestCert) {
                                // Direct check on model/relationship if possible, or just skip if too expensive here
                                // For now, let's just check if it has an issuer linked
                                if (!$latestCert->is_ca && !$latestCert->issuer_certificate_id) {
                                    $incomplete = true;
                                }
                            }
                        @endphp
                        @if($incomplete)
                            <div style="font-size: 0.75rem; color: #856404; background: #fff3cd; padding: 2px 5px; border-radius: 3px; display: inline-block; margin-left: 10px;" title="This domain has an incomplete certificate chain (missing Root/Intermediate)">
                                ⚠️ Incomplete Chain
                            </div>
                        @endif
                    </td>
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
                            <button type="button" class="btn btn-sm" style="background: #2980b9; color: white;" onclick="runDryRun(this, {{ $auto->id }})">Test</button>
                            <form action="{{ route('automations.run', $auto->id) }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Upload and REPLACE certificate on device now?')">Run Now</button>
                            </form>
                            <button type="button" class="btn btn-sm" style="background: #34495e; color: white;" onclick="openLogsModal({{ $auto->id }})">Logs</button>
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

<!-- Add Modal -->
<div id="add-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
    <div style="background:white; width:600px; margin:50px auto; padding:30px; border-radius:8px; max-height:90vh; overflow-y:auto;">
        <h3>Add New Automation</h3>
        <form id="add-form" action="{{ route('automations.store') }}" method="POST">
            @csrf
            <div style="margin-bottom:15px">
                <label>Domain</label><br>
                <select name="domain_id" id="add-domain-id" required style="width:100%; padding:8px; border:1px solid #ddd;" onchange="checkExistingCert()">
                    <option value="">Select Domain...</option>
                    @foreach($domains as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="margin-bottom:15px">
                <label>Manufacturer</label><br>
                <select name="type" id="add-type" required style="width:100%; padding:8px; border:1px solid #ddd;" onchange="updateFormFields(); checkExistingCert();">
                    <option value="kemp">Kemp Loadmaster</option>
                    <option value="fortigate">Fortigate Firewall</option>
                    <option value="paloalto">Palo Alto Firewall</option>
                </select>
            </div>
            <div style="margin-bottom:15px">
                <label>Hostname / IP Address</label><br>
                <input type="text" name="hostname" id="add-hostname" required style="width:100%; padding:8px; border:1px solid #ddd;" onchange="checkExistingCert()">
            </div>
            <div style="margin-bottom:15px">
                <label id="password-label">API Key / Password</label><br>
                <input type="password" name="password" id="add-password" required style="width:100%; padding:8px; border:1px solid #ddd;" onchange="checkExistingCert()">
            </div>
            
            <div id="cert-check-warning" style="display:none; background:#fff3cd; color:#856404; padding:10px; border-radius:4px; margin-bottom:15px; border:1px solid #ffeeba; font-size:0.85rem;">
                <!-- Filled by JS -->
            </div>

            <div id="add-extra-fields"></div>

            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="btn" style="background: #2980b9; color: white;" onclick="testConnectivity('add')">Test Connectivity</button>
                <button type="button" class="btn" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Automation</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="edit-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
    <div style="background:white; width:600px; margin:50px auto; padding:30px; border-radius:8px; max-height:90vh; overflow-y:auto;">
        <h3>Edit Automation</h3>
        <form id="edit-form" method="POST">
            @csrf
            @method('PUT')
            <div style="margin-bottom:15px">
                <label>Domain</label><br>
                <select name="domain_id" id="edit-domain-id" required style="width:100%; padding:8px; border:1px solid #ddd;" onchange="checkExistingCert('edit')">
                    @foreach($domains as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="margin-bottom:15px">
                <label>Manufacturer</label><br>
                <select name="type" id="edit-type" required style="width:100%; padding:8px; border:1px solid #ddd;" onchange="updateFormFields('edit'); checkExistingCert('edit');">
                    <option value="kemp">Kemp Loadmaster</option>
                    <option value="fortigate">Fortigate Firewall</option>
                    <option value="paloalto">Palo Alto Firewall</option>
                </select>
            </div>
            <div style="margin-bottom:15px">
                <label>Hostname / IP Address</label><br>
                <input type="text" name="hostname" id="edit-hostname" required style="width:100%; padding:8px; border:1px solid #ddd;" onchange="checkExistingCert('edit')">
            </div>
            <div style="margin-bottom:15px">
                <label id="edit-password-label">API Key / Password (Leave blank to keep current)</label><br>
                <input type="password" name="password" id="edit-password" style="width:100%; padding:8px; border:1px solid #ddd;" onchange="checkExistingCert('edit')">
            </div>

            <div id="edit-cert-check-warning" style="display:none; background:#fff3cd; color:#856404; padding:10px; border-radius:4px; margin-bottom:15px; border:1px solid #ffeeba; font-size:0.85rem;">
                <!-- Filled by JS -->
            </div>

            <div id="edit-extra-fields"></div>

            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="btn" style="background: #2980b9; color: white;" onclick="testConnectivity('edit')">Test Connectivity</button>
                <button type="button" class="btn" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Automation</button>
            </div>
        </form>
    </div>
</div>

<!-- Logs Modal -->
<div id="logs-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
    <div style="background:white; width:800px; margin:50px auto; padding:30px; border-radius:8px; max-height:90vh; overflow-y:auto;">
        <h3>Automation Logs</h3>
        <div id="logs-content"></div>
        <div style="text-align:right; margin-top:20px;">
            <button type="button" class="btn" onclick="closeLogsModal()">Close</button>
        </div>
    </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('add-modal').style.display = 'block';
        updateFormFields();
    }
    function closeAddModal() {
        document.getElementById('add-modal').style.display = 'none';
    }

    function checkExistingCert(mode = 'add') {
        const domainId = document.getElementById(mode + '-domain-id').value;
        const type = document.getElementById(mode + '-type').value;
        const hostname = document.getElementById(mode + '-hostname').value;
        const password = document.getElementById(mode + '-password').value;
        const warningBox = document.getElementById(mode === 'add' ? 'cert-check-warning' : 'edit-cert-check-warning');

        if (!domainId || !type || !hostname) {
            warningBox.style.display = 'none';
            return;
        }

        warningBox.style.display = 'block';
        warningBox.innerHTML = 'Checking device for existing certificate...';

        fetch('{{ route("automations.check-cert") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ domain_id: domainId, type: type, hostname: hostname, password: password })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                warningBox.innerHTML = data.message;
            } else {
                warningBox.innerHTML = 'Could not verify device status: ' + data.message;
            }
        })
        .catch(err => {
            warningBox.innerHTML = 'Error checking device status.';
        });
    }

    function testConnectivity(mode = 'add') {
        const type = document.getElementById(mode + '-type').value;
        const hostname = document.getElementById(mode + '-hostname').value;
        const password = document.getElementById(mode + '-password').value;
        const warningBox = document.getElementById(mode === 'add' ? 'cert-check-warning' : 'edit-cert-check-warning');

        if (!type || !hostname || !password) {
            alert('Please provide type, hostname and API key/password.');
            return;
        }

        warningBox.style.display = 'block';
        warningBox.innerHTML = 'Testing connectivity...';

        fetch('{{ route("automations.test") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ type: type, hostname: hostname, password: password })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                warningBox.innerHTML = '<span style="color:#27ae60">✅ ' + data.message + ' (Found ' + data.count + ' certificates)</span>';
            } else {
                warningBox.innerHTML = '<span style="color:#c0392b">❌ ' + data.message + '</span>';
            }
        })
        .catch(err => {
            warningBox.innerHTML = '<span style="color:#c0392b">❌ Error testing connectivity.</span>';
        });
    }

    function updateFormFields(mode = 'add') {
        const type = document.getElementById(mode + '-type').value;
        const container = document.getElementById(mode + '-extra-fields');
        const pwdLabel = document.getElementById(mode + '-password-label') || document.getElementById('password-label');
        
        let html = '';
        if (type === 'kemp') {
            pwdLabel.innerText = 'API Key / Password';
            html = `
                <div style="margin-bottom:15px">
                    <label>VS Index (Integer, 0 for first VS matching hostname)</label><br>
                    <input type="number" name="config[vs_index]" value="0" required style="width:100%; padding:8px; border:1px solid #ddd;">
                </div>
            `;
        } else if (type === 'fortigate') {
            pwdLabel.innerText = 'API Key';
            html = `
                <div style="margin-bottom:15px">
                    <label>Port (Default 443)</label><br>
                    <input type="number" name="config[port]" value="443" required style="width:100%; padding:8px; border:1px solid #ddd;">
                </div>
                <div style="margin-bottom:15px">
                    <label>Apply to Roles:</label><br>
                    <label><input type="checkbox" name="config[roles][vpn_ssl]" value="1" checked> SSL-VPN Certificate</label><br>
                    <label><input type="checkbox" name="config[roles][web_ui]" value="1"> Admin WebUI Certificate</label>
                </div>
            `;
        } else if (type === 'paloalto') {
            pwdLabel.innerText = 'API Key';
            html = `
                <div style="margin-bottom:15px">
                    <label>SSL/TLS Service Profile Names (Optional, comma separated)</label><br>
                    <input type="text" name="config[profiles_string]" placeholder="VPN-Profile,Portal-Profile" style="width:100%; padding:8px; border:1px solid #ddd;">
                </div>
            `;
        }
        container.innerHTML = html;
    }

    function openEditModal(id) {
        fetch(`/automations/${id}`, {
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(res => res.json())
            .then(data => {
                const auto = data.automation;
                document.getElementById('edit-form').action = `/automations/${id}`;
                document.getElementById('edit-domain-id').value = auto.domain_id;
                document.getElementById('edit-type').value = auto.type;
                document.getElementById('edit-hostname').value = auto.hostname;
                
                updateFormFields('edit');
                
                // Fill extra fields
                if (auto.type === 'kemp') {
                    document.querySelector('[name="config[vs_index]"]').value = auto.config.vs_index || 0;
                } else if (auto.type === 'fortigate') {
                    document.querySelector('[name="config[port]"]').value = auto.config.port || 443;
                    document.querySelector('[name="config[roles][vpn_ssl]"]').checked = !!auto.config.roles?.vpn_ssl;
                    document.querySelector('[name="config[roles][web_ui]"]').checked = !!auto.config.roles?.web_ui;
                } else if (auto.type === 'paloalto') {
                    document.querySelector('[name="config[profiles_string]"]').value = auto.config.profiles_string || '';
                }

                document.getElementById('edit-modal').style.display = 'block';
            });
    }

    function closeEditModal() {
        document.getElementById('edit-modal').style.display = 'none';
    }

    function openLogsModal(id) {
        fetch(`/automations/${id}`, {
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(res => res.json())
            .then(data => {
                const logs = data.logs;
                let html = '<table style="width:100%; border-collapse: collapse;">';
                html += '<tr style="background:#eee;"><th style="padding:10px;">Date</th><th style="padding:10px;">Status</th><th style="padding:10px;">Message</th></tr>';
                
                logs.forEach(log => {
                    let color = '#27ae60';
                    if (log.status === 'failure' || log.status === 'error') color = '#c0392b';
                    if (log.status === 'warning') color = '#f39c12';

                    html += `<tr style="border-bottom:1px solid #ddd;">
                        <td style="padding:10px;">${new Date(log.created_at).toLocaleString()}</td>
                        <td style="padding:10px;"><span class="tag" style="background:${color}; color:white;">${log.status}</span></td>
                        <td style="padding:10px;">${log.message}</td>
                    </tr>`;
                });
                
                if (logs.length === 0) html += '<tr><td colspan="3" style="padding:20px; text-align:center;">No logs yet.</td></tr>';
                html += '</table>';
                
                document.getElementById('logs-content').innerHTML = html;
                document.getElementById('logs-modal').style.display = 'block';
            });
    }

    function closeLogsModal() {
        document.getElementById('logs-modal').style.display = 'none';
    }

    function runDryRun(btn, id) {
        const originalText = btn.innerText;
        btn.innerText = 'Testing...';
        btn.disabled = true;

        fetch(`/automations/${id}/test`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
            .then(res => {
                return res.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Non-JSON response:', text);
                        // Show first 100 chars of response if it's HTML
                        const errorMsg = text.includes('<!DOCTYPE') || text.includes('<html') 
                            ? 'Server returned HTML instead of JSON. This might be a session timeout or a server error.'
                            : 'Invalid JSON response.';
                        throw new Error(errorMsg);
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    alert('Dry-run check successful!\n' + JSON.stringify(data.status, null, 2));
                } else {
                    alert('Check failed: ' + data.message);
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
            })
            .finally(() => {
                btn.innerText = originalText;
                btn.disabled = false;
            });
    }
</script>
@endsection
