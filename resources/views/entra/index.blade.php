@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1>Entra ID Applications</h1>
        <div style="display: flex; gap: 10px;">
            <form action="{{ route('entra.sync') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-primary">Sync from Entra ID</button>
            </form>
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <form action="{{ route('entra.index') }}" method="GET" style="display: flex; gap: 10px;">
            <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Search by name, App-ID or tag..." style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
            <button type="submit" class="btn">Search</button>
        </form>
    </div>

    <div class="table-responsive">
        <div class="domain-list">
            @foreach($apps as $app)
                <div class="domain-item" onclick="openEntraDrawer({{ $app->id }})">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: {{ $app->health_color }};" title="{{ ucfirst($app->health_status) }}"></div>
                        <div>
                            <strong style="font-size: 1.1rem;">{{ $app->display_name }}</strong>
                            <div style="font-size: 0.8rem; color: #666;">
                                ID: {{ $app->app_id }} | {{ ucfirst(str_replace('_', ' ', $app->type)) }}
                            </div>
                            <div style="margin-top: 5px;">
                                @foreach($app->tags as $tag)
                                    <span class="tag {{ $tag->type }}">{{ $tag->name }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: bold; color: {{ $app->health_color }};">
                            {{ $app->expiry_human }}
                        </div>
                        <div style="font-size: 0.8rem; color: #888;">
                            Next: {{ $app->next_expiry }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

@endsection
