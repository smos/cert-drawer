@extends('layouts.app')

@section('content')
@if(session('success'))
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        {{ session('success') }}
    </div>
@endif

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Live Certificate Health Monitoring</h2>
    <form action="{{ route('cert-health.check-all') }}" method="POST">
        @csrf
        <button type="submit" class="btn btn-primary">Check All Domains (Now)</button>
    </form>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 10px;">Domain</th>
                <th style="padding: 10px;">Status</th>
                <th style="padding: 10px;">Resolved IP Checks</th>
                <th style="padding: 10px;">Last Check</th>
            </tr>
        </thead>
        <tbody>
            @foreach($domains as $domain)
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px; font-weight: 600;">
                        {{ $domain->name }}
                        @if($domain->mismatch)
                            <div style="color: #e67e22; font-size: 0.75rem; margin-top: 5px;">
                                ⚠️ <strong>Mismatch:</strong> IPv4 and IPv6 returned different certificates!
                            </div>
                        @endif
                        @if($domain->has_errors)
                            <div style="color: #c0392b; font-size: 0.75rem; margin-top: 5px;">
                                ⚠️ Some IP checks failed to connect.
                            </div>
                        @endif
                    </td>
                    <td style="padding: 10px;">
                        @switch($domain->health_status)
                            @case('expired')
                                <span class="tag" style="background: #e74c3c; color: white;">Expired</span>
                                @break
                            @case('critical')
                                <span class="tag" style="background: #c0392b; color: white;">Critical</span>
                                @break
                            @case('urgent')
                                <span class="tag" style="background: #e67e22; color: white;">Urgent</span>
                                @break
                            @case('warning')
                                <span class="tag" style="background: #f1c40f; color: black;">Warning</span>
                                @break
                            @case('healthy')
                                <span class="tag" style="background: #27ae60; color: white;">Healthy</span>
                                @break
                            @default
                                <span class="tag" style="background: #95a5a6; color: white;">No Data</span>
                        @endswitch
                    </td>
                    <td style="padding: 10px;">
                        <div style="display: flex; flex-direction: column; gap: 5px;">
                            @foreach($domain->health_logs as $log)
                                <div style="font-size: 0.85rem; border: 1px solid #f0f0f0; padding: 5px; border-radius: 4px;">
                                    <strong>[{{ $log->ip_version }}]</strong> {{ $log->ip_address }} 
                                    @if($log->error)
                                        <span style="color: #c0392b;">&times; {{ $log->error }}</span>
                                    @else
                                        <span style="color: #27ae60;">&check;</span> 
                                        <small style="color: #666;">
                                            Thumb: <code>{{ substr($log->thumbprint_sha256, 0, 8) }}...</code> 
                                            Exp: {{ $log->expiry_date ? $log->expiry_date->format('Y-m-d') : 'N/A' }}
                                        </small>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </td>
                    <td style="padding: 10px; color: #666; font-size: 0.85rem;">
                        {{ $domain->last_cert_check ? $domain->last_cert_check->diffForHumans() : 'Never' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
