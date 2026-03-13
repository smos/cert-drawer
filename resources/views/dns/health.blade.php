@extends('layouts.app')

@section('content')
@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
    </div>
@endif

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>DNS Health Monitoring</h2>
    <form action="{{ route('dns.check-all') }}" method="POST">
        @csrf
        <button type="submit" class="btn btn-primary">Run All Checks (Now)</button>
    </form>
</div>

<div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <h3>Monitored Domains</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid #eee; text-align: left;">
                    <th style="padding: 10px;">Domain</th>
                    <th style="padding: 10px;">Last Check</th>
                    <th style="padding: 10px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($domains as $domain)
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px; font-weight: 600;">{{ $domain->name }}</td>
                        <td style="padding: 10px; color: #666;">
                            {{ $domain->last_dns_check ? $domain->last_dns_check->diffForHumans() : 'Never' }}
                        </td>
                        <td style="padding: 10px;">
                            <a href="{{ route('dns.domain-logs', $domain->id) }}" class="btn btn-sm">View History</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <h3>Recent DNS Changes</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid #eee; text-align: left;">
                    <th style="padding: 10px;">Domain</th>
                    <th style="padding: 10px;">Type</th>
                    <th style="padding: 10px;">Changes</th>
                    <th style="padding: 10px;">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px; font-weight: 600;">{{ $log->domain->name }}</td>
                        <td style="padding: 10px;"><span class="tag">{{ $log->record_type }}</span></td>
                        <td style="padding: 10px; font-size: 0.85rem;">
                            <div style="color: #c0392b; text-decoration: line-through;">
                                {{ implode(', ', (array)$log->old_value) ?: '(empty)' }}
                            </div>
                            <div style="color: #27ae60; font-weight: 600;">
                                {{ implode(', ', (array)$log->new_value) ?: '(empty)' }}
                            </div>
                        </td>
                        <td style="padding: 10px; color: #888; white-space: nowrap;">
                            {{ $log->created_at->format('Y-m-d H:i') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="padding: 20px; text-align: center; color: #888;">No DNS changes detected yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
