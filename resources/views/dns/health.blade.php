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
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
        <h3 style="margin-top: 0; margin-bottom: 15px;">Monitored Domains</h3>
        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee; text-align: left;">
                        <th style="padding: 12px 15px;">Domain</th>
                        <th style="padding: 12px 15px;">Last Check</th>
                        <th style="padding: 12px 15px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($domains as $domain)
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px 15px;">
                                <strong style="cursor:pointer; color: #3498db;" onclick="openDrawer({{ $domain->id }})">{{ $domain->name }}</strong>
                            </td>
                            <td style="padding: 12px 15px; color: #666;">
                                {{ $domain->last_dns_check ? $domain->last_dns_check->diffForHumans() : 'Never' }}
                            </td>
                            <td style="padding: 12px 15px; text-align: right;">
                                <a href="{{ route('dns.domain-logs', $domain->id) }}" class="btn btn-sm" style="background: #eee;">View History</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
        <h3 style="margin-top: 0; margin-bottom: 15px;">Recent DNS Changes</h3>
        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee; text-align: left;">
                        <th style="padding: 12px 15px;">Domain</th>
                        <th style="padding: 12px 15px;">Type</th>
                        <th style="padding: 12px 15px;">Changes</th>
                        <th style="padding: 12px 15px;">Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px 15px; font-weight: 600;">{{ $log->domain->name }}</td>
                            <td style="padding: 12px 15px;"><span class="tag">{{ $log->record_type }}</span></td>
                            <td style="padding: 12px 15px; font-size: 0.85rem;">
                                <div style="color: #c0392b; text-decoration: line-through;">
                                    {{ implode(', ', (array)$log->old_value) ?: '(empty)' }}
                                </div>
                                <div style="color: #27ae60; font-weight: 600;">
                                    {{ implode(', ', (array)$log->new_value) ?: '(empty)' }}
                                </div>
                            </td>
                            <td style="padding: 12px 15px; color: #888; white-space: nowrap;">
                                {{ $log->created_at->format('Y-m-d H:i') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="padding: 30px; text-align: center; color: #888;">No DNS changes detected yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
