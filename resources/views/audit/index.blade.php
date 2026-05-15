@extends('layouts.app')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Audit Logs</h2>
    <div style="display: flex; gap: 5px;">
        <a href="{{ route('audit.index') }}" class="btn btn-sm" style="background: {{ !request('category') ? '#34495e' : '#ecf0f1' }}; color: {{ !request('category') ? 'white' : '#333' }}; border: 1px solid #ddd;">All</a>
        <a href="{{ route('audit.index', ['category' => 'certificate']) }}" class="btn btn-sm" style="background: {{ request('category') === 'certificate' ? '#3498db' : '#ecf0f1' }}; color: {{ request('category') === 'certificate' ? 'white' : '#333' }}; border: 1px solid #ddd;">Certificates</a>
        <a href="{{ route('audit.index', ['category' => 'automation']) }}" class="btn btn-sm" style="background: {{ request('category') === 'automation' ? '#2ecc71' : '#ecf0f1' }}; color: {{ request('category') === 'automation' ? 'white' : '#333' }}; border: 1px solid #ddd;">Automations</a>
        <a href="{{ route('audit.index', ['category' => 'entra']) }}" class="btn btn-sm" style="background: {{ request('category') === 'entra' ? '#9b59b6' : '#ecf0f1' }}; color: {{ request('category') === 'entra' ? 'white' : '#333' }}; border: 1px solid #ddd;">Entra ID</a>
        <a href="{{ route('audit.index', ['category' => 'domain']) }}" class="btn btn-sm" style="background: {{ request('category') === 'domain' ? '#f1c40f' : '#ecf0f1' }}; color: {{ request('category') === 'domain' ? 'white' : '#333' }}; border: 1px solid #ddd;">Domains</a>
        <a href="{{ route('audit.index', ['category' => 'auth']) }}" class="btn btn-sm" style="background: {{ request('category') === 'auth' ? '#e67e22' : '#ecf0f1' }}; color: {{ request('category') === 'auth' ? 'white' : '#333' }}; border: 1px solid #ddd;">Auth</a>
    </div>
</div>

<div class="table-responsive" style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
        <thead>
            <tr style="background: #f8f9fa; border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 12px 15px;">Timestamp</th>
                <th style="padding: 12px 15px;">User</th>
                <th style="padding: 12px 15px;">Action</th>
                <th style="padding: 12px 15px;">Description</th>
                <th style="padding: 12px 15px;">IP Address</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 12px 15px; color: #666; white-space: nowrap;">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                    <td style="padding: 12px 15px;">
                        <strong>{{ $log->user ? $log->user->name : 'System / Unknown' }}</strong><br>
                        <small style="color: #888;">{{ $log->user ? $log->user->email : '' }}</small>
                    </td>
                    <td style="padding: 12px 15px;">
                        <span class="tag" style="background: #e9ecef; color: #495057; font-weight: 600; text-transform: uppercase; font-size: 0.7rem;">
                            {{ str_replace('_', ' ', $log->action) }}
                        </span>
                    </td>
                    <td style="padding: 12px 15px;">{{ $log->description }}</td>
                    <td style="padding: 12px 15px; color: #888; font-family: monospace;">{{ $log->ip_address }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div style="margin-top: 20px;">
    {{ $logs->links() }}
</div>
@endsection
