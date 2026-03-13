@extends('layouts.app')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>DNS Change History: {{ $domain->name }}</h2>
    <a href="{{ route('dns.health') }}" class="btn">&larr; Back to Health</a>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 10px;">Type</th>
                <th style="padding: 10px;">Old Value</th>
                <th style="padding: 10px;">New Value</th>
                <th style="padding: 10px;">Timestamp</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px;"><span class="tag">{{ $log->record_type }}</span></td>
                    <td style="padding: 10px; font-size: 0.85rem; color: #c0392b; text-decoration: line-through;">
                        {{ is_array($log->old_value) ? implode(', ', $log->old_value) : $log->old_value }}
                        @if(empty($log->old_value)) (empty) @endif
                    </td>
                    <td style="padding: 10px; font-size: 0.85rem; color: #27ae60; font-weight: 600;">
                        {{ is_array($log->new_value) ? implode(', ', $log->new_value) : $log->new_value }}
                        @if(empty($log->new_value)) (empty) @endif
                    </td>
                    <td style="padding: 10px; color: #888; white-space: nowrap;">
                        {{ $log->created_at->format('Y-m-d H:i:s') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="padding: 20px; text-align: center; color: #888;">No DNS changes recorded for this domain.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 20px;">
        {{ $logs->links() }}
    </div>
</div>
@endsection
