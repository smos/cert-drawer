@extends('layouts.app')

@section('content')
@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
    </div>
@endif

<h2>Authentication Settings</h2>

<form action="{{ route('auth.settings.update') }}" method="POST" id="auth-settings-form">
    @csrf
    
    <h3>LDAP / Active Directory Configuration</h3>
    <div style="margin-bottom:15px">
        <label>LDAP Host</label><br>
        <input type="text" name="ldap_host" value="{{ $settings['ldap_host'] ?? '' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>LDAP Port</label><br>
        <input type="number" name="ldap_port" value="{{ $settings['ldap_port'] ?? '389' }}" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>LDAP Bind User (DN / email)</label><br>
        <input type="text" name="ldap_username" value="{{ $settings['ldap_username'] ?? '' }}" placeholder="cn=admin,dc=example,dc=com" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>LDAP Bind Password</label><br>
        <input type="password" name="ldap_password" value="{{ isset($settings['ldap_password']) ? '********' : '' }}" placeholder="Enter password" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>LDAP Base DN</label><br>
        <input type="text" name="ldap_base_dn" value="{{ $settings['ldap_base_dn'] ?? '' }}" placeholder="dc=example,dc=com" style="width:100%; padding:8px; border:1px solid #ddd;">
    </div>
    <div style="margin-bottom:15px">
        <label>
            <input type="hidden" name="ldap_use_tls" value="0">
            <input type="checkbox" name="ldap_use_tls" value="1" {{ ($settings['ldap_use_tls'] ?? '0') == '1' ? 'checked' : '' }}> Use TLS (LDAPS)
        </label>
    </div>
    <div style="margin-bottom:15px">
        <label>
            <input type="hidden" name="ldap_use_starttls" value="0">
            <input type="checkbox" name="ldap_use_starttls" value="1" {{ ($settings['ldap_use_starttls'] ?? '0') == '1' ? 'checked' : '' }}> Use StartTLS
        </label>
    </div>
    <div style="margin-bottom:15px">
        <label>
            <input type="hidden" name="ldap_skip_verify" value="0">
            <input type="checkbox" name="ldap_skip_verify" value="1" {{ ($settings['ldap_skip_verify'] ?? '0') == '1' ? 'checked' : '' }}> Skip TLS Verification
        </label>
    </div>

    <div style="margin-bottom:20px; border: 1px solid #ddd; padding: 15px; border-radius: 8px; background: #fafafa;">
        <label style="font-weight: 600;">Allowed LDAP Groups (Global Access)</label>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 10px;">Members of these groups will be allowed to sign in. If empty, all LDAP users can sign in.</p>
        
        <div id="selected-groups" style="display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px;">
            @php
                $allowedGroups = json_decode($settings['ldap_allowed_groups'] ?? '[]', true);
                if (!is_array($allowedGroups)) $allowedGroups = [];
            @endphp
            @foreach($allowedGroups as $dn)
                <div class="tag" style="background: #3498db; color: white; display: flex; align-items: center; gap: 5px; padding: 5px 10px;">
                    <span style="font-size: 0.8rem;">{{ explode(',', $dn)[0] }}</span>
                    <input type="hidden" name="ldap_allowed_groups[]" value="{{ $dn }}">
                    <span style="cursor: pointer; font-weight: bold;" onclick="this.parentElement.remove()">&times;</span>
                </div>
            @endforeach
        </div>

        <div style="position: relative;">
            <input type="text" class="group-search-input" data-target="selected-groups" data-name="ldap_allowed_groups[]" placeholder="Search for LDAP groups..." style="width:100%; padding:8px; border:1px solid #ddd; border-radius: 4px;">
            <div class="group-results-container" style="display:none; position: absolute; top: 100%; left: 0; width: 100%; background: white; border: 1px solid #ddd; border-top: none; z-index: 100; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto;"></div>
        </div>
    </div>

    <div style="margin-bottom:20px; border: 1px solid #ddd; padding: 15px; border-radius: 8px; background: #fff3cd;">
        <label style="font-weight: 600;">Full Admin Access Groups (Super Admin)</label>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 10px;">
            Members of these groups have full access to all areas, can manage domain access groups, and have the permission to <strong>permanently delete domains and certificates</strong>.
        </p>
        
        <div id="admin-selected-groups" style="display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px;">
            @php
                $adminGroupsStr = $settings['admin_groups'] ?? '[]';
                $adminGroups = json_decode($adminGroupsStr, true);
                if (!is_array($adminGroups)) {
                    $adminGroups = array_filter(explode(';', $adminGroupsStr));
                }
            @endphp
            @foreach($adminGroups as $dn)
                <div class="tag" style="background: #e67e22; color: white; display: flex; align-items: center; gap: 5px; padding: 5px 10px;">
                    <span style="font-size: 0.8rem;">{{ explode(',', $dn)[0] }}</span>
                    <input type="hidden" name="admin_groups[]" value="{{ $dn }}">
                    <span style="cursor: pointer; font-weight: bold;" onclick="this.parentElement.remove()">&times;</span>
                </div>
            @endforeach
        </div>

        <div style="position: relative;">
            <input type="text" class="group-search-input" data-target="admin-selected-groups" data-name="admin_groups[]" placeholder="Search for Admin groups..." style="width:100%; padding:8px; border:1px solid #ddd; border-radius: 4px;">
            <div class="group-results-container" style="display:none; position: absolute; top: 100%; left: 0; width: 100%; background: white; border: 1px solid #ddd; border-top: none; z-index: 100; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto;"></div>
        </div>
    </div>

    @php
        $granular = [
            'auth' => ['label' => 'Authentication Settings Access', 'color' => '#8e44ad'],
            'settings' => ['label' => 'General Settings Access', 'color' => '#27ae60'],
            'automations' => ['label' => 'Automations Access', 'color' => '#2980b9'],
            'audit' => ['label' => 'Audit Logs Access', 'color' => '#7f8c8d'],
            'dns' => ['label' => 'DNS Health Access', 'color' => '#d35400'],
            'cert_health' => ['label' => 'Cert Health Access', 'color' => '#c0392b'],
        ];
    @endphp

    @foreach($granular as $area => $cfg)
    <div style="margin-bottom:20px; border: 1px solid #ddd; padding: 15px; border-radius: 8px; background: #f0f4f8;">
        <label style="font-weight: 600;">{{ $cfg['label'] }}</label>
        
        <div id="access-{{ $area }}-selected" style="display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px;">
            @php
                $key = "access_groups_{$area}";
                $areaGroups = json_decode($settings[$key] ?? '[]', true);
                if (!is_array($areaGroups)) $areaGroups = [];
            @endphp
            @foreach($areaGroups as $dn)
                <div class="tag" style="background: {{ $cfg['color'] }}; color: white; display: flex; align-items: center; gap: 5px; padding: 5px 10px;">
                    <span style="font-size: 0.8rem;">{{ explode(',', $dn)[0] }}</span>
                    <input type="hidden" name="{{ $key }}[]" value="{{ $dn }}">
                    <span style="cursor: pointer; font-weight: bold;" onclick="this.parentElement.remove()">&times;</span>
                </div>
            @endforeach
        </div>

        <div style="position: relative;">
            <input type="text" class="group-search-input" data-target="access-{{ $area }}-selected" data-name="access_groups_{{ $area }}[]" placeholder="Search groups for {{ $area }} access..." style="width:100%; padding:8px; border:1px solid #ddd; border-radius: 4px;">
            <div class="group-results-container" style="display:none; position: absolute; top: 100%; left: 0; width: 100%; background: white; border: 1px solid #ddd; border-top: none; z-index: 100; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto;"></div>
        </div>
    </div>
    @endforeach

    <div style="margin-bottom: 20px;">
        <button type="button" class="btn" onclick="testLdap()" style="background: #e67e22; color: white;">Test Connection</button>
        <span id="ldap-test-result" style="margin-left: 10px; font-weight: 600;"></span>
    </div>

    <button type="submit" class="btn btn-primary">Save Authentication Settings</button>
</form>

<script>
    function testLdap() {
        const form = document.getElementById('auth-settings-form');
        const formData = new FormData(form);
        const result = document.getElementById('ldap-test-result');
        
        result.innerText = 'Testing...';
        result.style.color = 'black';

        fetch('{{ route("auth.settings.test-ldap") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: formData
        })
        .then(async res => {
            const isJson = res.headers.get('content-type')?.includes('application/json');
            const data = isJson ? await res.json() : null;

            if (!res.ok) {
                throw new Error(data?.message || `Server Error: ${res.status} ${res.statusText}`);
            }
            return data;
        })
        .then(data => {
            if (data.success) {
                result.innerText = '✅ Connection Successful!';
                result.style.color = 'green';
            } else {
                result.innerText = '❌ Failed: ' + data.message;
                result.style.color = 'red';
            }
        })
        .catch(err => {
            result.innerText = '❌ Error: ' + err.message;
            result.style.color = 'red';
            console.error(err);
        });
    }

    // Group Search Logic
    document.querySelectorAll('.group-search-input').forEach(input => {
        const resultsContainer = input.nextElementSibling;
        const targetContainerId = input.getAttribute('data-target');
        const inputName = input.getAttribute('data-name');
        const targetContainer = document.getElementById(targetContainerId);
        let searchTimeout;

        input.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const q = this.value;
            if (q.length < 2) {
                resultsContainer.style.display = 'none';
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch(`{{ route('auth.settings.search-groups') }}?q=${encodeURIComponent(q)}`)
                    .then(res => res.json())
                    .then(data => {
                        resultsContainer.innerHTML = '';
                        if (data.length === 0) {
                            resultsContainer.innerHTML = '<div style="padding: 10px; color: #888;">No groups found</div>';
                        } else {
                            data.forEach(group => {
                                const div = document.createElement('div');
                                div.style.padding = '10px';
                                div.style.cursor = 'pointer';
                                div.style.borderBottom = '1px solid #eee';
                                div.innerHTML = `<strong>${group.cn}</strong><br><small style="color: #888;">${group.dn}</small>`;
                                div.onclick = () => addGroup(group, targetContainer, input, resultsContainer, inputName);
                                resultsContainer.appendChild(div);
                            });
                        }
                        resultsContainer.style.display = 'block';
                    });
            }, 300);
        });
    });

    function addGroup(group, targetContainer, searchInput, resultsContainer, inputName) {
        const existing = targetContainer.querySelector(`input[value="${group.dn}"]`);
        if (existing) {
            searchInput.value = '';
            resultsContainer.style.display = 'none';
            return;
        }

        // Try to get color from first existing tag in this container, or fallback
        const firstTag = targetContainer.querySelector('.tag');
        const bgColor = firstTag ? firstTag.style.backgroundColor : (inputName.includes('admin') ? '#e67e22' : '#3498db');

        const tag = document.createElement('div');
        tag.className = 'tag';
        tag.style.cssText = `background: ${bgColor}; color: white; display: flex; align-items: center; gap: 5px; padding: 5px 10px;`;
        tag.innerHTML = `
            <span style="font-size: 0.8rem;">${group.cn}</span>
            <input type="hidden" name="${inputName}" value="${group.dn}">
            <span style="cursor: pointer; font-weight: bold;" onclick="this.parentElement.remove()">&times;</span>
        `;
        targetContainer.appendChild(tag);
        searchInput.value = '';
        resultsContainer.style.display = 'none';
    }

    document.addEventListener('click', function(e) {
        document.querySelectorAll('.group-results-container').forEach(container => {
            if (!container.previousElementSibling.contains(e.target) && !container.contains(e.target)) {
                container.style.display = 'none';
            }
        });
    });
</script>
@endsection
