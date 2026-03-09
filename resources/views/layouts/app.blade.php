<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Drawer</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --primary: #2c3e50;
            --secondary: #34495e;
            --accent: #3498db;
            --bg: #f4f7f6;
            --text: #333;
            --white: #fff;
            --border: #ddd;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        nav {
            width: 250px;
            background-color: var(--primary);
            color: var(--white);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }

        nav h1 {
            font-size: 1.5rem;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--secondary);
            padding-bottom: 10px;
        }

        nav a {
            color: var(--white);
            text-decoration: none;
            padding: 10px 0;
            border-bottom: 1px solid var(--secondary);
        }

        nav a:hover {
            color: var(--accent);
        }

        main {
            flex: 1;
            padding: 40px;
            overflow-y: auto;
            position: relative;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .domain-list {
            list-style: none;
            padding: 0;
        }

        .domain-item {
            background: var(--white);
            padding: 15px 20px;
            margin-bottom: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.2s;
        }

        .domain-item:hover {
            transform: translateX(5px);
            border-left: 4px solid var(--accent);
        }

        .tag {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 5px;
            background: #eee;
        }

        .tag.server { background: #d1ecf1; color: #0c5460; }
        .tag.client { background: #d4edda; color: #155724; }

        /* Drawer Styles */
        #drawer {
            position: fixed;
            top: 0;
            right: -600px;
            width: 500px;
            height: 100%;
            background: var(--white);
            box-shadow: -5px 0 15px rgba(0,0,0,0.1);
            transition: right 0.3s ease-in-out;
            padding: 30px 30px 80px 30px;
            overflow-y: auto;
            z-index: 1000;
        }

        #drawer.open {
            right: 0;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            display: none;
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-primary { background: var(--accent); color: white; }
        .btn-sm { padding: 4px 8px; font-size: 0.85rem; }

        .cert-history {
            margin-top: 20px;
        }

        .cert-item {
            border: 1px solid var(--border);
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .cert-item h4 { margin: 0 0 10px 0; }

        .collapsible {
            display: none;
            padding-top: 10px;
            border-top: 1px dashed var(--border);
        }

        .collapsible.active { display: block; }
    </style>
</head>
<body>
    <nav>
        <h1>Cert Drawer</h1>
        @auth
            <a href="{{ route('domains.index') }}">Domains</a>
            <a href="{{ route('domains.authorities') }}">Authorities</a>
            <a href="{{ route('auth.settings.index') }}">Authentication</a>
            <a href="{{ route('settings.index') }}">General Settings</a>
            <a href="{{ route('automations.index') }}">Automations</a>
            <a href="{{ route('audit.index') }}">Audit Logs</a>
            <div style="margin-top: auto; padding-top: 20px; border-top: 1px solid var(--secondary);">
                <div style="font-size: 0.85rem; color: #888; margin-bottom: 10px;">Logged in as:</div>
                <div style="font-weight: 600; margin-bottom: 15px;">{{ Auth::user()->name }}</div>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-sm" style="width: 100%; background: #c0392b; color: white;">Logout</button>
                </form>
            </div>
        @else
            <a href="{{ route('login') }}">Login</a>
        @endauth
    </nav>

    <main>
        <div class="container">
            @yield('content')
        </div>
    </main>

    <div id="drawer">
        <div id="drawer-content"></div>
    </div>
    
    <div id="csr-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1100; border-radius:8px; width:600px;">
        <h3>Certificate Signing Request</h3>
        <textarea id="csr-text" readonly style="width:100%; height:300px; font-family:monospace; padding:10px; border:1px solid #ddd;"></textarea>
        <div style="margin-top:15px; text-align:right;">
            <button class="btn btn-primary" onclick="copyCsr()">Copy to Clipboard</button>
            <button class="btn" onclick="document.getElementById('csr-modal').style.display='none'">Close</button>
        </div>
    </div>

    <div id="csr-config-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1100; border-radius:8px; width:600px;">
        <h3>Preview CSR Configuration</h3>
        <p style="font-size: 0.85rem; color: #666;">Review and edit the OpenSSL configuration before generating the CSR.</p>
        <textarea id="csr-config-text" style="width:100%; height:300px; font-family:monospace; padding:10px; border:1px solid #ddd;"></textarea>
        <div style="margin-top:15px; text-align:right;">
            <button class="btn btn-primary" id="csr-config-generate">Generate CSR</button>
            <button class="btn" onclick="document.getElementById('csr-config-modal').style.display='none'; if(!drawer.classList.contains('open')) overlay.classList.remove('active');">Cancel</button>
        </div>
    </div>

    <div id="password-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1200; border-radius:8px; width:400px;">
        <h3 id="password-modal-title">Enter Password</h3>
        <input type="password" id="password-modal-input" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; margin-bottom:15px;">
        <div style="text-align:right;">
            <button class="btn" id="password-modal-cancel">Cancel</button>
            <button class="btn btn-primary" id="password-modal-submit">Submit</button>
        </div>
    </div>

    <div id="text-prompt-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1200; border-radius:8px; width:500px;">
        <h3 id="text-prompt-modal-title">Enter Content</h3>
        <input type="text" id="text-prompt-modal-input-single" style="display:none; width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; margin-bottom:15px;">
        <textarea id="text-prompt-modal-input" style="display:none; width:100%; height:200px; padding:10px; border:1px solid #ddd; border-radius:4px; margin-bottom:15px; font-family:monospace;"></textarea>
        <div style="text-align:right;">
            <button class="btn" id="text-prompt-modal-cancel">Cancel</button>
            <button class="btn btn-primary" id="text-prompt-modal-submit">Submit</button>
        </div>
    </div>

    <div id="fulfillment-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1200; border-radius:8px; width:400px;">
        <h3>Choose Fulfillment Method</h3>
        <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
            <button class="btn btn-primary" id="fulfill-adcs">Active Directory CS (ADCS)</button>
            <button class="btn btn-primary" id="fulfill-acme">ACME (Networking4all)</button>
            <button class="btn btn-primary" id="fulfill-manual">Manual Upload (PEM)</button>
        </div>
        <div style="text-align:right;">
            <button class="btn" onclick="document.getElementById('fulfillment-modal').style.display='none'; if(!drawer.classList.contains('open')) overlay.classList.remove('active');">Cancel</button>
        </div>
    </div>

    <div id="details-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1100; border-radius:8px; width:600px; max-height: 80vh; overflow-y: auto;">
        <h3>Certificate Details</h3>
        <div id="details-content"></div>
        <div style="margin-top:15px; text-align:right;">
            <button class="btn" onclick="document.getElementById('details-modal').style.display='none'; if(!drawer.classList.contains('open')) overlay.classList.remove('active');">Close</button>
        </div>
    </div>

    <div id="acme-processing-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1200; border-radius:8px; width:400px; text-align:center;">
        <h3>Processing ACME Request</h3>
        <div id="acme-spinner" style="margin: 20px auto; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 2s linear infinite;"></div>
        <p id="acme-status">Starting verification...</p>
        <p style="font-size: 0.8rem; color: #888;">This can take up to 5 minutes. Please do not close this window.</p>
    </div>

    <style>
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>

    <div class="overlay" id="overlay"></div>

    <script>
        const drawer = document.getElementById('drawer');
        const overlay = document.getElementById('overlay');
        const drawerContent = document.getElementById('drawer-content');

        function openDrawer(domainId) {
            fetch(`/domains/${domainId}`)
                .then(res => {
                    if (!res.ok) throw new Error('Network response was not ok');
                    return res.json();
                })
                .then(data => {
                    try {
                        renderDrawer(data.domain, data.global_groups, data.is_admin);
                        drawer.classList.add('open');
                        overlay.classList.add('active');
                    } catch (e) {
                        console.error('Render Error:', e);
                        alert('Error rendering drawer: ' + e.message);
                    }
                })
                .catch(err => {
                    console.error('Fetch Error:', err);
                    alert('Error loading domain details: ' + err.message);
                });
        }

        function closeDrawer() {
            drawer.classList.remove('open');
            overlay.classList.remove('active');
        }

        overlay.onclick = closeDrawer;

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && document.activeElement.id === 'new-tag-name') {
                const domainId = document.querySelector('#drawer h2').getAttribute('data-id');
                if (domainId) addTag(domainId);
            }
        });

        function addTag(domainId) {
            const name = document.getElementById('new-tag-name').value;
            const type = document.getElementById('new-tag-type').value;

            if (!name) return;

            fetch(`/domains/${domainId}/tags`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ name, type })
            })
            .then(res => res.json())
            .then(data => {
                openDrawer(domainId);
            });
        }

        function removeTag(tagId, domainId) {
            if (!confirm('Remove this tag?')) return;

            fetch(`/tags/${tagId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(() => {
                openDrawer(domainId);
            });
        }

        function renderDrawer(domain, globalGroups = [], isAdmin = false) {
            let html = `
                <div style="display:flex; justify-content:space-between; align-items:center">
                    <h2 data-id="${domain.id}">${domain.name} ${domain.is_enabled ? '' : '<small style="color:#e74c3c">(Disabled)</small>'}</h2>
                    <div>
                        <button class="btn btn-sm ${domain.is_enabled ? 'btn-danger' : 'btn-primary'}" 
                                style="${domain.is_enabled ? 'background:#e74c3c; color:white;' : ''}"
                                onclick="toggleDomainStatus(${domain.id})">
                            ${domain.is_enabled ? 'Disable' : 'Enable'}
                        </button>
                        <button onclick="closeDrawer()" class="btn">Close</button>
                    </div>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: 600; font-size: 0.85rem; display:block; margin-bottom:5px;">Notes</label>
                    <textarea id="domain-notes" style="width:100%; height:60px; padding:8px; border:1px solid #ddd; border-radius:4px; font-family:inherit; font-size:0.9rem;">${domain.notes || ''}</textarea>
                    <div style="text-align:right; margin-top:5px;">
                        <button class="btn btn-sm btn-primary" onclick="saveNotes(${domain.id})">Save Notes</button>
                    </div>
                </div>

                <div id="tag-container" style="margin-bottom:10px; display:flex; flex-wrap:wrap; gap:5px;">
                    ${(domain.tags || []).map(t => `<span class="tag ${t.type}" onclick="removeTag(${t.id}, ${domain.id})" title="Click to remove">${t.name} <small>&times;</small></span>`).join('')}
                </div>
                <div style="display:flex; gap:5px; margin-bottom:15px;">
                    <input type="text" id="new-tag-name" placeholder="Add tag (e.g. Server A)" style="flex:1; padding:5px; border:1px solid #ddd;">
                    <select id="new-tag-type" style="padding:5px;">
                        <option value="server">Private/Public (Server)</option>
                        <option value="client">Public only (Client)</option>
                    </select>
                    <button class="btn btn-sm btn-primary" onclick="addTag(${domain.id})">Add</button>
                </div>

                ${isAdmin ? `
                <div style="margin-bottom:20px; border: 1px solid #eee; padding: 15px; border-radius: 4px; background: #fafafa;">
                    <label style="font-weight: 600; font-size: 0.85rem; display:block; margin-bottom:10px;">Domain Access Groups</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        ${globalGroups.length === 0 ? '<p style="font-size:0.8rem; color:#888;">No LDAP groups configured in Settings.</p>' : ''}
                        ${globalGroups.map(dn => {
                            const cn = dn.split(',')[0].replace('CN=', '');
                            const isActive = (domain.allowed_groups || []).includes(dn);
                            return `
                                <button class="btn btn-sm group-toggle-btn" 
                                        data-dn="${dn}"
                                        data-domain-id="${domain.id}"
                                        data-current='${JSON.stringify(domain.allowed_groups || [])}'
                                        style="background: ${isActive ? '#3498db' : '#eee'}; color: ${isActive ? 'white' : '#333'}; border: 1px solid ${isActive ? '#2980b9' : '#ddd'};">
                                    ${cn}
                                </button>
                            `;
                        }).join('')}
                    </div>
                    <p style="font-size: 0.75rem; color: #888; margin-top: 10px;">If no groups are selected, the domain is visible to all authenticated users.</p>
                </div>
                ` : ''}
                <hr>
                
                <div id="request-form" style="background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:20px">
                    <h4>Request New Certificate</h4>
                    <form onsubmit="previewCsr(event, ${domain.id})">
                        <div style="margin-bottom:10px;">
                            <label><input type="radio" name="csr_option" value="auto" checked> Automatic CSR (from template)</label><br>
                            <label><input type="radio" name="csr_option" value="custom"> Custom CSR (define SANs, etc.)</label><br>
                            <label><input type="radio" name="csr_option" value="upload"> Upload Existing CSR</label>
                        </div>
                        <div id="custom-csr-options" style="display:none; margin-bottom:10px;">
                            <label>Common Name:</label><input type="text" id="csr-cn" value="${domain.name}" style="width:100%; padding:8px; border:1px solid #ddd; margin-bottom:5px;"><br>
                            <label>Subject Alternative Names (comma separated):</label><textarea id="csr-sans" placeholder="example.com,www.example.com" style="width:100%; padding:8px; border:1px solid #ddd;"></textarea>
                        </div>
                        <div id="upload-csr-options" style="display:none; margin-bottom:10px;">
                            <label>Paste CSR here:</label><textarea id="uploaded-csr" placeholder="-----BEGIN CERTIFICATE REQUEST-----..." style="width:100%; height:100px; padding:8px; border:1px solid #ddd;"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%; padding: 12px; font-size: 1rem;">Generate/Upload CSR</button>
                    </form>
                </div>

                <h3>Certificate History</h3>
                <div class="cert-history">
                    ${(domain.certificates || []).length ? (domain.certificates || []).map(c => `
                        <div class="cert-item">
                            <div onclick="this.nextElementSibling.classList.toggle('active')" style="cursor:pointer; display:flex; justify-content:space-between; align-items: center;">
                                <div>
                                    <strong>${c.status === 'issued' ? 'CERT' : 'CSR'}</strong> 
                                    <small style="margin-left: 10px; color: #666;">
                                        ${c.valid_from && c.expiry_fmt ? `${c.valid_from} to ${c.expiry_fmt}` : new Date(c.created_at).toLocaleDateString()}
                                    </small>
                                </div>
                                <span style="font-size: 0.8rem; color: #888;">${c.is_ca ? '(CA)' : ''} &blacktriangledown;</span>
                            </div>
                            <div class="collapsible">
                                <p>Status: <span class="tag">${c.status}</span></p>
                                <p>Issuer: ${c.issuer || 'N/A'}</p>
                                <p>Import Date: ${new Date(c.created_at).toLocaleString()}</p>
                                <div class="actions" style="margin-top:10px">
                                    <button class="btn btn-sm btn-primary" onclick="showCertificateDetails(${c.id})">Details</button>
                                    ${c.csr ? `
                                        <button class="btn btn-primary btn-sm" onclick="showCsr('${btoa(c.csr)}')">Show CSR</button>
                                        <a href="/certificates/${c.id}/download/csr" class="btn btn-sm">Download CSR</a>
                                    ` : ''}
                                    ${c.status === 'requested' && c.csr ? `
                                        <button class="btn btn-sm" onclick="showFulfillmentOptions(${c.id}, ${domain.id})">Fulfill Certificate</button>
                                    ` : ''}
                                    ${c.status === 'pending_verification' ? `
                                        <button class="btn btn-sm" style="background:#27ae60; color:white;" onclick="fulfillAcme(${c.id}, ${domain.id})">Verify & Fulfill ACME</button>
                                    ` : ''}
                                    ${c.certificate ? `
                                        <a href="/certificates/${c.id}/download/cert" class="btn btn-sm">Download Cert</a>
                                        ${c.pfx_password ? 
                                            `<button class="btn btn-sm" onclick="downloadPfx(${c.id})">Download PFX</button>` : 
                                            `<button class="btn btn-sm" onclick="promptPfx(${c.id})">Generate PFX</button>`
                                        }
                                        ${c.private_key ? `<button class="btn btn-sm" onclick="downloadKey(${c.id})">Download Key</button>` : ''}
                                    ` : `
                                        <button class="btn btn-sm" onclick="uploadCert(${c.id})">Upload Cert</button>
                                    `}
                                </div>
                            </div>
                        </div>
                    `).join('') : '<p>No history available</p>'}
                </div>

                ${isAdmin ? `
                <hr style="margin-top: 40px; border: 0; border-top: 1px solid #ffcfcf;">
                <div style="text-align: center; padding-bottom: 50px;">
                    <button class="btn btn-sm" style="background: #c0392b; color: white;" onclick="deleteDomain(${domain.id})">Delete Domain & Purge Files</button>
                </div>
                ` : ''}
            `;
            drawerContent.innerHTML = html;

            // Delegated click handler for toggle buttons
            document.querySelectorAll('.group-toggle-btn').forEach(btn => {
                btn.onclick = () => {
                    const dn = btn.getAttribute('data-dn');
                    const domainId = btn.getAttribute('data-domain-id');
                    const current = JSON.parse(btn.getAttribute('data-current'));
                    toggleDomainGroup(dn, domainId, current);
                };
            });

            // Event listeners for radio buttons
            document.querySelectorAll('input[name="csr_option"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.getElementById('custom-csr-options').style.display = (this.value === 'custom') ? 'block' : 'none';
                    document.getElementById('upload-csr-options').style.display = (this.value === 'upload') ? 'block' : 'none';
                });
            });
        }

        function toggleDomainStatus(domainId) {
            fetch(`/domains/${domainId}/toggle-status`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(res => res.json())
            .then(data => {
                const status = data.is_enabled ? 'enabled' : 'disabled';
                alert(`Domain has been ${status}.`);
                openDrawer(domainId);
            });
        }

        function deleteDomain(domainId) {
            if (!confirm('Are you sure you want to delete this domain? This will permanently PURGE all associated certificate files and records.')) {
                return;
            }

            fetch(`/domains/${domainId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(res => res.json())
            .then(() => {
                alert('Domain deleted and files purged.');
                window.location.reload();
            });
        }

        function saveNotes(domainId) {
            const notes = document.getElementById('domain-notes').value;
            fetch(`/domains/${domainId}/notes`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ notes })
            })
            .then(res => res.json())
            .then(() => {
                alert('Notes saved successfully');
            });
        }

        function toggleDomainGroup(dn, domainId, currentGroups) {
            let newGroups;
            if (currentGroups.includes(dn)) {
                newGroups = currentGroups.filter(g => g !== dn);
            } else {
                newGroups = [...currentGroups, dn];
            }
            updateDomainGroups(domainId, newGroups);
        }

        function updateDomainGroups(domainId, groups) {
            fetch(`/domains/${domainId}/groups`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ allowed_groups: groups })
            })
            .then(res => res.json())
            .then(() => {
                openDrawer(domainId);
            });
        }


        function showCsr(base64Csr) {
            if (!base64Csr) {
                alert('No CSR available');
                return;
            }
            const csr = atob(base64Csr);
            document.getElementById('csr-text').value = csr;
            document.getElementById('csr-modal').style.display = 'block';
        }

        function copyCsr() {
            const textarea = document.getElementById('csr-text');
            textarea.select();
            document.execCommand('copy');
            alert('CSR copied to clipboard');
        }

        function previewCsr(event, domainId) {
            event.preventDefault();
            const checkedOption = document.querySelector('input[name="csr_option"]:checked');
            if (!checkedOption) {
                alert('Please select a CSR option.');
                return;
            }
            const csrOption = checkedOption.value;
            
            if (csrOption === 'upload') {
                initiateCertificateRequest(event, domainId);
                return;
            }

            let query = `?csr_option=${csrOption}`;
            if (csrOption === 'custom') {
                const cn = document.getElementById('csr-cn').value;
                const sans = document.getElementById('csr-sans').value.split(',').map(s => s.trim()).filter(s => s.length > 0);
                query += `&cn=${encodeURIComponent(cn)}`;
                sans.forEach(s => query += `&sans[]=${encodeURIComponent(s)}`);
            }

            console.log(`Fetching CSR preview: /domains/${domainId}/preview-csr-config${query}`);

            fetch(`/domains/${domainId}/preview-csr-config${query}`)
                .then(res => {
                    if (!res.ok) {
                        return res.json().then(err => { throw new Error(err.message || 'Server error'); });
                    }
                    return res.json();
                })
                .then(data => {
                    console.log('CSR Preview data received:', data);
                    const modal = document.getElementById('csr-config-modal');
                    const textArea = document.getElementById('csr-config-text');
                    
                    if (!modal || !textArea) {
                        throw new Error('CSR Modal elements not found in DOM');
                    }

                    textArea.value = data.config;
                    modal.style.display = 'block';
                    overlay.classList.add('active');
                    
                    document.getElementById('csr-config-generate').onclick = () => {
                        const editedConfig = textArea.value;
                        modal.style.display = 'none';
                        initiateCertificateRequest(null, domainId, editedConfig);
                    };
                })
                .catch(err => {
                    console.error('CSR Preview Error:', err);
                    alert('Error preparing CSR preview: ' + err.message);
                });
        }

        function initiateCertificateRequest(event, domainId, manualConfig = null) {
            if (event) event.preventDefault();
            
            let csrData = {};
            if (manualConfig) {
                csrData = {
                    csr_option: 'manual_config',
                    config: manualConfig
                };
            } else {
                const csrOption = document.querySelector('input[name="csr_option"]:checked').value;
                if (csrOption === 'custom') {
                    csrData = {
                        csr_option: 'custom',
                        cn: document.getElementById('csr-cn').value,
                        sans: document.getElementById('csr-sans').value.split(',').map(s => s.trim()).filter(s => s.length > 0)
                    };
                } else if (csrOption === 'upload') {
                    csrData = {
                        csr_option: 'upload',
                        csr: document.getElementById('uploaded-csr').value
                    };
                } else { // auto
                    csrData = { csr_option: 'auto' };
                }
            }

            fetch(`/domains/${domainId}/initiate-request`, { 
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(csrData)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('CSR generated/uploaded successfully.');
                    openDrawer(domainId); 
                } else {
                    alert('CSR Request Failed: ' + data.message);
                }
            });
        }

        async function textPrompt(title, isSingleLine = false) {
            return new Promise((resolve) => {
                const modal = document.getElementById('text-prompt-modal');
                const textarea = document.getElementById('text-prompt-modal-input');
                const input = document.getElementById('text-prompt-modal-input-single');
                const submit = document.getElementById('text-prompt-modal-submit');
                const cancel = document.getElementById('text-prompt-modal-cancel');
                const titleEl = document.getElementById('text-prompt-modal-title');

                titleEl.innerText = title;
                textarea.value = '';
                input.value = '';
                
                if (isSingleLine) {
                    textarea.style.display = 'none';
                    input.style.display = 'block';
                    setTimeout(() => input.focus(), 10);
                } else {
                    textarea.style.display = 'block';
                    input.style.display = 'none';
                    setTimeout(() => textarea.focus(), 10);
                }

                modal.style.display = 'block';
                overlay.classList.add('active');

                const cleanup = () => {
                    modal.style.display = 'none';
                    if (!drawer.classList.contains('open')) overlay.classList.remove('active');
                    submit.onclick = null;
                    cancel.onclick = null;
                    input.onkeydown = null;
                };

                submit.onclick = () => {
                    const val = isSingleLine ? input.value : textarea.value;
                    cleanup();
                    resolve(val);
                };

                cancel.onclick = () => {
                    cleanup();
                    resolve(null);
                };

                input.onkeydown = (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        submit.click();
                    }
                    if (e.key === 'Escape') cancel.click();
                };
            });
        }

        function fulfillAcme(certificateId, domainId) {
            console.log('fulfillAcme triggered for ID:', certificateId);
            const modal = document.getElementById('acme-processing-modal');
            const statusText = document.getElementById('acme-status');
            
            modal.style.display = 'block';
            overlay.classList.add('active');
            statusText.innerText = 'Initializing acme.sh verification...';

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 300000); // 5 minute timeout

            fetch(`/certificates/${certificateId}/acme-fulfill`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                signal: controller.signal
            })
            .then(res => res.json())
            .then(data => {
                clearTimeout(timeoutId);
                modal.style.display = 'none';
                if (data.success) {
                    alert('Success! Certificate has been issued via acme.sh.');
                    openDrawer(domainId);
                } else {
                    alert('ACME Error: ' + data.message);
                    if (!drawer.classList.contains('open')) overlay.classList.remove('active');
                }
            })
            .catch(err => {
                clearTimeout(timeoutId);
                modal.style.display = 'none';
                alert('An error occurred: ' + err.message);
                if (!drawer.classList.contains('open')) overlay.classList.remove('active');
            });
        }

        function initiateAcmeRequest(certificateId, domainId) {
            fetch(`/certificates/${certificateId}/acme-request`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    openDrawer(domainId);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        function showFulfillmentOptions(certificateId, domainId) {
            const modal = document.getElementById('fulfillment-modal');
            modal.style.display = 'block';
            overlay.classList.add('active');

            document.getElementById('fulfill-adcs').onclick = () => {
                modal.style.display = 'none';
                promptAdcsCredentials(certificateId, domainId);
            };

            document.getElementById('fulfill-acme').onclick = () => {
                modal.style.display = 'none';
                initiateAcmeRequest(certificateId, domainId);
            };

            document.getElementById('fulfill-manual').onclick = () => {
                modal.style.display = 'none';
                uploadCert(certificateId, domainId);
            };
        }

        async function promptAdcsCredentials(certificateId, domainId) {
            const adcsUsername = await textPrompt("Enter ADCS Username:", true);
            if (!adcsUsername) return;
            const adcsPassword = await passwordPrompt("Enter ADCS Password:");
            if (!adcsPassword) return;

            fetch(`/certificates/${certificateId}/adcs-request`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ adcs_username: adcsUsername, adcs_password: adcsPassword })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    openDrawer(domainId);
                } else {
                    alert('ADCS Request Failed: ' + data.message);
                }
            })
            .catch(err => {
                alert('Error sending request to ADCS: ' + err.message);
            });
        }

        async function uploadCert(certId, domainId) {
            const certData = await textPrompt("Paste Certificate PEM here:");
            if (certData) {
                            fetch(`/certificates/${certId}/upload`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ certificate: certData })
                })
                .then(res => res.json())
                .then(data => {
                    alert('Certificate uploaded');
                    openDrawer(domainId);
                });
            }
        }

        function passwordPrompt(title) {
            return new Promise((resolve) => {
                const modal = document.getElementById('password-modal');
                const input = document.getElementById('password-modal-input');
                const submit = document.getElementById('password-modal-submit');
                const cancel = document.getElementById('password-modal-cancel');
                const titleEl = document.getElementById('password-modal-title');

                titleEl.innerText = title;
                input.value = '';
                modal.style.display = 'block';
                overlay.classList.add('active');
                input.focus();

                const cleanup = () => {
                    modal.style.display = 'none';
                    if (!drawer.classList.contains('open')) {
                        overlay.classList.remove('active');
                    }
                    submit.onclick = null;
                    cancel.onclick = null;
                    input.onkeydown = null;
                };

                submit.onclick = () => {
                    const val = input.value;
                    cleanup();
                    resolve(val);
                };

                cancel.onclick = () => {
                    cleanup();
                    resolve(null);
                };

                input.onkeydown = (e) => {
                    if (e.key === 'Enter') submit.click();
                    if (e.key === 'Escape') cancel.click();
                };
            });
        }

        async function promptPfx(certId) {
            const pwd = await passwordPrompt("Enter PFX Password for generation:");
            if (pwd) {
                downloadViaPost(`/certificates/${certId}/pfx`, { password: pwd });
                setTimeout(() => {
                    const domainId = document.querySelector('#drawer h2').getAttribute('data-id');
                    openDrawer(domainId);
                }, 2000);
            }
        }

        async function downloadPfx(certId) {
            const pwd = await passwordPrompt("Enter PFX Password to verify and download:");
            if (pwd) {
                downloadViaPost(`/certificates/${certId}/pfx`, { password: pwd });
            }
        }

        async function downloadKey(certId) {
            const pwd = await passwordPrompt("Enter PFX Password to verify and download private key:");
            if (pwd) {
                downloadViaPost(`/certificates/${certId}/download/key`, { password: pwd });
            }
        }

        function downloadViaPost(url, data) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url;

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = '{{ csrf_token() }}';
            form.appendChild(csrfInput);

            for (const key in data) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = data[key];
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function showCertificateDetails(certId) {
            fetch(`/certificates/${certId}`)
                .then(res => res.json())
                .then(data => {
                    let html = `
                        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600; width:150px;">Type</td><td style="padding:8px;">${data.type}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Status</td><td style="padding:8px;">${data.status}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Issuer</td><td style="padding:8px;">${data.issuer || 'N/A'}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Valid From</td><td style="padding:8px;">${data.valid_from || 'N/A'}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Expires</td><td style="padding:8px;">${data.expiry_date || 'N/A'}</td></tr>
                    `;

                    if (data.type === 'Certificate') {
                        html += `
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Serial</td><td style="padding:8px; font-family:monospace;">${data.serial}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Signature</td><td style="padding:8px;">${data.signature_type}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Subject</td><td style="padding:8px; font-size:0.8rem;">${data.subject}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">SANs</td><td style="padding:8px;">${data.sans.join(', ') || 'None'}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Thumbprint (SHA1)</td><td style="padding:8px; font-family:monospace; font-size:0.8rem;">${data.thumbprint_sha1 || 'N/A'}</td></tr>
                            <tr style="border-bottom:1px solid #eee"><td style="padding:8px; font-weight:600;">Thumbprint (SHA256)</td><td style="padding:8px; font-family:monospace; font-size:0.8rem;">${data.thumbprint_sha256 || 'N/A'}</td></tr>
                        `;
                    } else if (data.type === 'CSR') {
                        html += `
                            <tr><td colspan="2" style="padding:8px; font-weight:600;">CSR Body:</td></tr>
                            <tr><td colspan="2" style="padding:8px;"><pre style="background:#f4f4f4; padding:10px; font-size:0.75rem; border-radius:4px; overflow-x:auto;">${data.csr_body}</pre></td></tr>
                        `;
                    }

                    html += `</table>`;
                    document.getElementById('details-content').innerHTML = html;
                    document.getElementById('details-modal').style.display = 'block';
                    overlay.classList.add('active');
                });
        }

    </script>
</body>
</html>
