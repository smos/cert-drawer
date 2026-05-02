@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <h2 style="margin: 0;">Expiration Calendar</h2>
        <div style="display: flex; gap: 20px; font-size: 0.9rem;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 15px; height: 15px; background: #fff; border: 1px solid #ddd; border-radius: 3px;"></div>
                <span>Weekday</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 15px; height: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px;"></div>
                <span>Weekend</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 15px; height: 15px; border: 2px solid var(--accent); border-radius: 3px;"></div>
                <span>Today</span>
            </div>
        </div>
    </div>

    <div class="calendar-grid">
        <div class="calendar-header">
            <div>Monday</div>
            <div>Tuesday</div>
            <div>Wednesday</div>
            <div>Thursday</div>
            <div>Friday</div>
            <div>Saturday</div>
            <div>Sunday</div>
        </div>
        
        @foreach($calendar as $week)
            <div class="calendar-row">
                @foreach($week as $day)
                    <div class="calendar-day {{ $day['is_weekend'] ? 'weekend' : '' }} {{ $day['is_today'] ? 'today' : '' }}">
                        <div class="day-number">{{ $day['date']->format('j') }} <span class="day-month">{{ $day['date']->format('M') }}</span></div>
                        <div class="day-events">
                            @foreach($day['events'] as $event)
                                <div class="event-item" onclick="openDrawer({{ $event['domain_id'] }})" title="{{ $event['domain_name'] }} ({{ $event['expiry_time'] }})">
                                    <span class="event-time">{{ $event['expiry_time'] }}</span>
                                    <span class="event-name">{{ $event['domain_name'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>

<style>
    .calendar-grid {
        display: flex;
        flex-direction: column;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }

    .calendar-header {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: var(--primary);
        color: white;
        font-weight: 600;
        text-align: center;
    }

    .calendar-header div {
        padding: 10px;
        border-right: 1px solid var(--secondary);
    }

    .calendar-header div:last-child {
        border-right: none;
    }

    .calendar-row {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        border-bottom: 1px solid var(--border);
        min-height: 150px;
    }

    .calendar-row:last-child {
        border-bottom: none;
    }

    .calendar-day {
        padding: 10px;
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        background: #fff;
        transition: background 0.2s;
    }

    .calendar-day:last-child {
        border-right: none;
    }

    .calendar-day.weekend {
        background: #f9f9f9;
    }

    .calendar-day.today {
        border: 2px solid var(--accent);
        z-index: 1;
    }

    .day-number {
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 10px;
        color: var(--secondary);
    }

    .day-month {
        font-size: 0.75rem;
        font-weight: 400;
        color: #888;
        text-transform: uppercase;
    }

    .day-events {
        display: flex;
        flex-direction: column;
        gap: 4px;
        flex: 1;
        overflow-y: auto;
    }

    .event-item {
        background: #e1f5fe;
        border-left: 3px solid var(--accent);
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
        cursor: pointer;
        display: flex;
        gap: 5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: transform 0.1s, background 0.1s;
    }

    .event-item:hover {
        transform: scale(1.02);
        background: #b3e5fc;
    }

    .event-time {
        font-weight: 700;
        color: var(--primary);
        font-size: 0.75rem;
    }

    .event-name {
        color: var(--secondary);
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>
@endsection
