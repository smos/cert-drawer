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
            <input type="text" id="group-search" placeholder="Search for LDAP groups..." style="width:100%; padding:8px; border:1px solid #ddd; border-radius: 4px;">
            <div id="group-results" style="display:none; position: absolute; top: 100%; left: 0; width: 100%; background: white; border: 1px solid #ddd; border-top: none; z-index: 100; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto;"></div>
        </div>
    </div>

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
    const groupSearch = document.getElementById('group-search');
    const groupResults = document.getElementById('group-results');
    const selectedGroups = document.getElementById('selected-groups');
    let searchTimeout;

    groupSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const q = this.value;
        if (q.length < 2) {
            groupResults.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(() => {
            fetch(`{{ route('auth.settings.search-groups') }}?q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => {
                    groupResults.innerHTML = '';
                    if (data.length === 0) {
                        groupResults.innerHTML = '<div style="padding: 10px; color: #888;">No groups found</div>';
                    } else {
                        data.forEach(group => {
                            const div = document.createElement('div');
                            div.style.padding = '10px';
                            div.style.cursor = 'pointer';
                            div.style.borderBottom = '1px solid #eee';
                            div.innerHTML = `<strong>${group.cn}</strong><br><small style="color: #888;">${group.dn}</small>`;
                            div.onclick = () => addGroup(group);
                            groupResults.appendChild(div);
                        });
                    }
                    groupResults.style.display = 'block';
                });
        }, 300);
    });

    function addGroup(group) {
        const existing = document.querySelector(`input[value="${group.dn}"]`);
        if (existing) {
            groupSearch.value = '';
            groupResults.style.display = 'none';
            return;
        }

        const tag = document.createElement('div');
        tag.className = 'tag';
        tag.style.cssText = 'background: #3498db; color: white; display: flex; align-items: center; gap: 5px; padding: 5px 10px;';
        tag.innerHTML = `
            <span style="font-size: 0.8rem;">${group.cn}</span>
            <input type="hidden" name="ldap_allowed_groups[]" value="${group.dn}">
            <span style="cursor: pointer; font-weight: bold;" onclick="this.parentElement.remove()">&times;</span>
        `;
        selectedGroups.appendChild(tag);
        groupSearch.value = '';
        groupResults.style.display = 'none';
    }

    document.addEventListener('click', function(e) {
        if (!groupSearch.contains(e.target) && !groupResults.contains(e.target)) {
            groupResults.style.display = 'none';
        }
    });
</script>
@endsection
