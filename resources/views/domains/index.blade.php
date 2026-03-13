@extends('layouts.app')

@section('content')
@if($errors->any())
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
        {{ $errors->first() }}
    </div>
@endif

@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
    </div>
@endif

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>{{ $pageTitle ?? 'Managed Domains' }}</h2>
    <div style="display: flex; gap: 10px;">
        <form action="{{ $isCa ? route('domains.authorities') : route('domains.index') }}" method="GET" style="display: flex; gap: 5px;">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search domains or tags..." style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 200px;">
            <input type="hidden" name="status" value="{{ request('status') }}">
            <button type="submit" class="btn">Search</button>
        </form>
        @if($isCa)
            <button class="btn btn-primary" onclick="document.getElementById('import-cert-modal').style.display='block'">Import Certificate</button>
        @else
            <button class="btn btn-primary" onclick="document.getElementById('add-domain-modal').style.display='block'">+ Add Domain</button>
        @endif
        <button class="btn" onclick="document.getElementById('import-pfx-modal').style.display='block'">Import PFX</button>
    </div>
</div>

<div style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; font-size: 0.9rem;">
    <span>Filter:</span>
    <a href="{{ route('domains.index', array_merge(request()->all(), ['status' => ''])) }}" class="tag" style="text-decoration: none; padding: 5px 12px; {{ !request('status') ? 'background: #2c3e50; color: white;' : '' }}">All</a>
    <a href="{{ route('domains.index', array_merge(request()->all(), ['status' => 'expiring'])) }}" class="tag" style="text-decoration: none; padding: 5px 12px; background: #f1c40f; color: #8a6d3b; {{ request('status') === 'expiring' ? 'border: 2px solid #333;' : '' }}">Expiring Soon</a>
    <a href="{{ route('domains.index', array_merge(request()->all(), ['status' => 'expired'])) }}" class="tag" style="text-decoration: none; padding: 5px 12px; background: #e74c3c; color: white; {{ request('status') === 'expired' ? 'border: 2px solid #333;' : '' }}">Expired</a>
    <a href="{{ route('domains.index', array_merge(request()->all(), ['status' => 'healthy'])) }}" class="tag" style="text-decoration: none; padding: 5px 12px; background: #2ecc71; color: white; {{ request('status') === 'healthy' ? 'border: 2px solid #333;' : '' }}">Healthy</a>
    <a href="{{ route('domains.index', array_merge(request()->all(), ['status' => 'none'])) }}" class="tag" style="text-decoration: none; padding: 5px 12px; background: #95a5a6; color: white; {{ request('status') === 'none' ? 'border: 2px solid #333;' : '' }}">No Cert</a>
    
    <label style="margin-left: 20px; display: flex; align-items: center; gap: 5px; color: #666;">
        <input type="checkbox" onchange="window.location.href='{{ route('domains.index', array_merge(request()->all(), ['show_disabled' => request('show_disabled') ? 0 : 1])) }}'" {{ request('show_disabled') ? 'checked' : '' }}> Show Disabled
    </label>

    @if(request('search') || request('status') || request('show_disabled'))
        <a href="{{ route('domains.index') }}" style="margin-left: 10px; color: #888;">Clear All</a>
    @endif
</div>

<ul class="domain-list">
    @foreach($domains as $domain)
        <li class="domain-item" onclick="openDrawer({{ $domain->id }})" style="border-left: 6px solid {{ $domain->health_color }}; {{ $domain->is_enabled ? '' : 'opacity: 0.6; background: #f9f9f9;' }}">
            <div style="flex: 1;">
                <strong>{{ $domain->name }}</strong> @if(!$domain->is_enabled) <small style="color:#e74c3c;">(Disabled)</small> @endif
                <div style="margin-top: 5px;">
                    @foreach($domain->tags as $tag)
                        <span class="tag {{ $tag->type }}" style="font-size: 0.65rem;">{{ $tag->name }}</span>
                    @endforeach
                </div>
            </div>
            <div style="text-align: right;">
                @if($domain->latest_expiry)
                    <div style="font-size: 0.9rem; font-weight: 600; color: {{ $domain->health_color == '#f1c40f' ? '#8a6d3b' : $domain->health_color }}">
                        Expires: {{ $domain->latest_expiry }}
                    </div>
                    <div style="font-size: 0.75rem; color: #888;">
                        ({{ $domain->expiry_human }})
                    </div>
                @else
                    <div style="font-size: 0.8rem; color: #888;">No active certificates</div>
                @endif
            </div>
        </li>
    @endforeach
</ul>

<div id="add-domain-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 10px rgba(0,0,0,0.5); z-index:1001; border-radius:8px; width:400px;">
    <h3>Add New Domain</h3>
    <form action="{{ route('domains.store') }}" method="POST">
        @csrf
        <div style="margin-bottom:15px">
            <label>Domain Name / Wildcard</label><br>
            <input type="text" name="name" id="new-domain-name" required style="width:100%; padding:8px; border:1px solid #ddd;" oninput="checkWildcardDns(this.value)">
        </div>
        <div style="margin-bottom:15px">
            <label>
                <input type="hidden" name="dns_monitored" value="0">
                <input type="checkbox" name="dns_monitored" id="new-domain-dns-monitored" value="1" checked> Monitor DNS Records
            </label>
        </div>
        <div style="margin-bottom:15px">
            <label>Notes</label><br>
            <textarea name="notes" style="width:100%; padding:8px; border:1px solid #ddd;"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Add Domain</button>
        <button type="button" class="btn" onclick="this.parentElement.parentElement.style.display='none'">Cancel</button>
    </form>
</div>

<script>
    function checkWildcardDns(val) {
        const checkbox = document.getElementById('new-domain-dns-monitored');
        if (val.startsWith('*.')) {
            checkbox.checked = false;
            checkbox.disabled = true;
        } else {
            checkbox.disabled = false;
            if (checkbox.hasAttribute('data-last-user-state')) {
                // Restore user state if we have it
            } else {
                checkbox.checked = true;
            }
        }
    }
</script>

<div id="import-cert-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 10px rgba(0,0,0,0.5); z-index:1001; border-radius:8px; width:400px;">
    <h3>Import Certificate File</h3>
    <form action="{{ route('domains.import-cert') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div style="margin-bottom:15px">
            <label>Certificate File (.cer, .crt, .pem)</label><br>
            <input type="file" name="certificate_file" required style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <button type="submit" class="btn btn-primary">Import Certificate</button>
        <button type="button" class="btn" onclick="this.parentElement.parentElement.style.display='none'">Cancel</button>
    </form>
</div>

<div id="import-pfx-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 10px rgba(0,0,0,0.5); z-index:1001; border-radius:8px; width:400px;">
    <h3>Import PFX File</h3>
    <form action="{{ route('domains.import-pfx') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div style="margin-bottom:15px">
            <label>PFX File</label><br>
            <input type="file" name="pfx_file" required style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <div style="margin-bottom:15px">
            <label>PFX Password</label><br>
            <input type="password" name="password" required style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>
        <button type="submit" class="btn btn-primary">Import Certificate</button>
        <button type="button" class="btn" onclick="this.parentElement.parentElement.style.display='none'">Cancel</button>
    </form>
</div>

@endsection
