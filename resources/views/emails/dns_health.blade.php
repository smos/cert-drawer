<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .domain { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .change { margin-left: 20px; font-size: 0.9em; }
        .type { font-weight: bold; color: #0056b3; }
        .old { color: #d9534f; text-decoration: line-through; }
        .new { color: #5cb85c; }
        .no-changes { font-style: italic; color: #666; }
    </style>
</head>
<body>
    <h2>DNS Health Check Report - {{ now()->toDateTimeString() }}</h2>
    <p>The automated DNS monitoring run has completed.</p>

    @if($changes->isEmpty())
        <p class="no-changes">No DNS record changes were detected during this run.</p>
    @else
        <p><strong>{{ $changes->count() }} changes detected:</strong></p>
        @foreach($changes->groupBy('domain_id') as $domainId => $logs)
            <div class="domain">
                <strong>{{ $logs->first()->domain->name }}</strong>
                @foreach($logs as $log)
                    <div class="change">
                        <span class="type">[{{ $log->record_type }}]</span>
                        @if(empty($log->old_value))
                            <span class="new">Added: {{ implode(', ', $log->new_value) }}</span>
                        @elseif(empty($log->new_value))
                            <span class="old">Removed: {{ implode(', ', $log->old_value) }}</span>
                        @else
                            <span class="old">{{ implode(', ', $log->old_value) }}</span>
                            &rarr;
                            <span class="new">{{ implode(', ', $log->new_value) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif

    <hr>
    <p><small>This is an automated notification from Cert Drawer.</small></p>
</body>
</html>
