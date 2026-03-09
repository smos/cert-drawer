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
    <button class="btn btn-primary" onclick="document.getElementById('add-automation-modal').style.display='block'">+ Add Automation</button>
</div>

<div style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
        <thead>
            <tr style="background: #f8f9fa; border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 12px 15px;">Domain</th>
                <th style="padding: 12px 15px;">Device Type</th>
                <th style="padding: 12px 15px;">Hostname / IP</th>
                <th style="padding: 12px 15px;">Kemp Cert Name</th>
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
                        auto_{{ str_replace('*', 'wildcard', $auto->domain->name) }}
                    </td>
                    <td style="padding: 12px 15px; text-align: right;">
                        <div style="display: flex; gap: 5px; justify-content: flex-end;">
                            <form action="{{ route('automations.run', $auto->id) }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Upload and REPLACE certificate on Kemp now?')">Run Now</button>
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

<div id="add-automation-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; box-shadow:0 0 20px rgba(0,0,0,0.5); z-index:1001; border-radius:8px; width:500px; max-height: 90vh; overflow-y: auto;">
    <h3>Add Kemp Automation</h3>
    <p style="font-size: 0.85rem; color: #666; margin-bottom: 20px;">
        This will automatically upload the latest certificate to your Kemp Loadmaster using the name 
        <strong>auto_domain_name</strong> with the <em>replace</em> flag enabled.
    </p>
    
    <form action="{{ route('automations.store') }}" method="POST">
        @csrf
        <input type="hidden" name="type" value="kemp">
        <input type="hidden" name="config[mode]" value="auto_prefix">

        <div style="margin-bottom:15px">
            <label style="font-weight: 600;">Link to Domain</label><br>
            <select name="domain_id" required style="width:100%; padding:8px; border:1px solid #ddd;">
                @foreach($domains as $domain)
                    <option value="{{ $domain->id }}">{{ $domain->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="margin-bottom:15px">
            <label style="font-weight: 600;">Kemp Hostname / IP</label><br>
            <input type="text" name="hostname" required placeholder="10.0.0.50" style="width:100%; padding:8px; border:1px solid #ddd;">
        </div>

        <div style="margin-bottom:15px">
            <label style="font-weight: 600;">Kemp API Key</label><br>
            <input type="password" name="password" required placeholder="Paste API Key here" style="width:100%; padding:8px; border:1px solid #ddd;">
            <small style="color: #888;">Ensure the API Key has permissions to 'addcert'.</small>
        </div>

        <div style="margin-top: 30px; text-align: right;">
            <button type="button" class="btn" onclick="this.parentElement.parentElement.parentElement.style.display='none'">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Automation</button>
        </div>
    </form>
</div>
@endsection
