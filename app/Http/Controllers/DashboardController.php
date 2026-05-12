<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $startOfCalendar = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $endOfCalendar = $startOfCalendar->copy()->addWeeks(4)->endOfDay();

        // Get all issued certificates expiring in the next 4 weeks
        $query = Certificate::where('status', 'issued')
            ->whereNull('archived_at')
            ->whereBetween('expiry_date', [$startOfCalendar, $endOfCalendar])
            ->with('domain');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->whereHas('domain', function($dq) use ($search) {
                    $dq->where('name', 'like', "%{$search}%")
                      ->orWhereHas('tags', function($tq) use ($search) {
                          $tq->where('name', 'like', "%{$search}%");
                      });
                })
                ->orWhere('thumbprint_sha1', 'like', "%{$search}%")
                ->orWhere('thumbprint_sha256', 'like', "%{$search}%")
                ->orWhere('serial_number', 'like', "%{$search}%")
                ->orWhere('issuer', 'like', "%{$search}%");
            });
        }

        $expiringCerts = $query->get();

        // Get expiring Entra ID secrets/certs
        $entraQuery = \App\Models\EntraAppSecret::with('app')
            ->whereHas('app', function($q) {
                $q->where('is_enabled', true);
            })
            ->whereBetween('end_date', [$startOfCalendar, $endOfCalendar]);

        if ($search) {
            $entraQuery->whereHas('app', function($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                  ->orWhere('app_id', 'like', "%{$search}%")
                  ->orWhereHas('tags', function($tq) use ($search) {
                      $tq->where('name', 'like', "%{$search}%");
                  });
            });
        }
        
        $expiringEntra = $entraQuery->get();

        // Filter by user access
        $user = Auth::user();
        $expiringCerts = $expiringCerts->filter(function($cert) use ($user) {
            return $user->canAccess($cert->domain);
        });

        $expiringEntra = $expiringEntra->filter(function($secret) use ($user) {
            if (empty($user->guid)) return true;
            return $user->hasAccessTo('entra');
        });

        // Group by date
        $events = [];
        foreach ($expiringCerts as $cert) {
            $date = Carbon::parse($cert->expiry_date)->format('Y-m-d');
            $events[$date][] = [
                'type' => 'certificate',
                'name' => $cert->domain->name,
                'id' => $cert->domain_id,
                'expiry_time' => Carbon::parse($cert->expiry_date)->format('H:i'),
                'color' => '#e1f5fe',
                'border' => '#3498db',
            ];
        }

        foreach ($expiringEntra as $secret) {
            $date = Carbon::parse($secret->end_date)->format('Y-m-d');
            $events[$date][] = [
                'type' => 'entra',
                'name' => $secret->app->display_name . " (" . ($secret->display_name ?: $secret->type) . ")",
                'id' => $secret->app->id,
                'expiry_time' => Carbon::parse($secret->end_date)->format('H:i'),
                'color' => '#fff3e0',
                'border' => '#ff9800',
            ];
        }

        // Build the 4x7 grid
        $calendar = [];
        $currentDate = $startOfCalendar->copy();
        
        for ($row = 0; $row < 4; $row++) {
            $week = [];
            for ($col = 0; $col < 7; $col++) {
                $dateString = $currentDate->format('Y-m-d');
                $week[] = [
                    'date' => $currentDate->copy(),
                    'is_today' => $currentDate->isToday(),
                    'is_weekend' => $currentDate->isWeekend(),
                    'events' => $events[$dateString] ?? [],
                ];
                $currentDate->addDay();
            }
            $calendar[] = $week;
        }

        return view('dashboard.index', compact('calendar'));
    }
}
