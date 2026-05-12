<?php

namespace App\Http\Controllers;

use App\Models\EntraApp;
use App\Models\Setting;
use App\Models\AuditLog;
use App\Services\EntraIdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EntraAppController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = EntraApp::with('secrets', 'tags');

        // Only show apps with secrets OR local app registrations we can manage
        $query->where(function($q) {
            $q->has('secrets')
              ->orWhere('type', 'app_registration');
        });

        if (!$request->has('show_disabled') || !$request->input('show_disabled')) {
            $query->where('is_enabled', true);
        }

        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                  ->orWhere('app_id', 'like', "%{$search}%")
                  ->orWhereHas('tags', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $apps = $query->orderBy('display_name')->get();

        $settings = Setting::all()->pluck('value', 'key');
        $yellow = (int) ($settings['expiry_yellow'] ?? 30);
        $red = (int) ($settings['expiry_red'] ?? 10);

        $filteredApps = [];
        foreach ($apps as $app) {
            // Check visibility permission
            if (!$this->canAccess($app)) {
                continue;
            }

            $healthColor = '#2ecc71'; // Healthy
            $healthStatus = 'healthy';
            $nextExpiryDate = null;
            $minDays = 9999;

            $secretsByType = $app->secrets->groupBy('type');
            $hasIssue = false;

            foreach ($secretsByType as $type => $secrets) {
                $active = $secrets->filter(fn($s) => $s->end_date && $s->end_date > now());
                $expired = $secrets->filter(fn($s) => $s->end_date && $s->end_date <= now());

                // If we have expired items but NO active replacement, it's a critical error
                if ($expired->isNotEmpty() && $active->isEmpty()) {
                    $healthColor = '#e74c3c';
                    $healthStatus = 'expired';
                    $hasIssue = true;
                    // For expired items with no replacement, the "next expiry" is the one that already passed
                    $recentExpired = $expired->sortByDesc('end_date')->first();
                    if (!$nextExpiryDate || $recentExpired->end_date < $nextExpiryDate) {
                        $nextExpiryDate = $recentExpired->end_date;
                    }
                }

                // Check health of active items
                foreach ($active as $secret) {
                    $days = (int) ceil(now()->diffInDays($secret->end_date, false));
                    
                    if ($days < $minDays) {
                        $minDays = $days;
                        $nextExpiryDate = $secret->end_date;
                    }

                    // Only update status if we don't already have a more critical (expired) state
                    if ($healthStatus !== 'expired') {
                        if ($days <= $red) {
                            $healthColor = '#c0392b';
                            $healthStatus = 'critical';
                        } elseif ($days <= $yellow && $healthStatus !== 'critical') {
                            $healthColor = '#f1c40f';
                            $healthStatus = 'warning';
                        }
                    }
                }
            }

            if (!$nextExpiryDate) {
                $healthColor = '#95a5a6';
                $healthStatus = 'none';
            }

            $app->health_color = $healthColor;
            $app->health_status = $healthStatus;
            $app->next_expiry = $nextExpiryDate ? $nextExpiryDate->format('Y-m-d') : 'N/A';
            $app->expiry_human = $nextExpiryDate ? $nextExpiryDate->diffForHumans() : 'No secrets/certs';

            $filteredApps[] = $app;
        }

        return view('entra.index', [
            'apps' => $filteredApps,
            'search' => $search
        ]);
    }

    public function show(EntraApp $app)
    {
        if (!$this->canAccess($app)) {
            abort(403);
        }

        $app->load('secrets', 'tags');
        
        return response()->json([
            'app' => $app
        ]);
    }

    public function sync(EntraIdService $service)
    {
        try {
            $service->syncApplications();
            return back()->with('success', 'Entra ID synchronization completed.');
        } catch (\Exception $e) {
            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    public function updateNotes(Request $request, EntraApp $app)
    {
        if (!$this->canAccess($app)) abort(403);
        
        $app->update(['notes' => $request->input('notes')]);
        AuditLog::log('entra_app_notes_update', "Updated notes for Entra App: {$app->display_name}", [], $app->id);
        return response()->json(['success' => true]);
    }

    public function addTag(Request $request, EntraApp $app)
    {
        if (!$this->canAccess($app)) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:server,client',
        ]);

        $tag = $app->tags()->create($validated);
        AuditLog::log('tag_add', "Added tag '{$tag->name}' to Entra App: {$app->display_name}", [], $app->id);

        return response()->json($tag);
    }

    protected function canAccess(EntraApp $app)
    {
        $user = Auth::user();
        if (empty($user->guid)) return true;
        
        return $user->hasAccessTo('entra');
    }
}
