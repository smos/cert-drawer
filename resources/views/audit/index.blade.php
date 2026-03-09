@extends('layouts.app')

@section('content')
<h2>Audit Logs</h2>

<div style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
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
