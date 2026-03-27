<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\AuditLog;
use Illuminate\Http\Request;

use App\Mail\TestMail;
use Illuminate\Support\Facades\Mail;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return view('settings.index', compact('settings'));
    }

    public function testEmail(Request $request)
    {
        // Save settings first so the test uses what's on screen
        $this->update($request);

        $recipient = $request->input('test_recipient');
        if (empty($recipient)) {
            return back()->with('error', 'Please provide a test recipient email address.');
        }

        try {
            // Re-trigger boot mail to ensure the latest saved settings are used in the current request
            (new \App\Providers\AppServiceProvider(app()))->bootMailConfiguration();
            
            Mail::to($recipient)->send(new TestMail());
            return back()->with('success', "Test email sent successfully to {$recipient}.");
        } catch (\Exception $e) {
            return back()->with('error', "Failed to send test email: " . $e->getMessage());
        }
    }

    public function searchGroups(Request $request)
    {
        // Still needed for the domain drawer group search
        $term = $request->input('q');
        if (empty($term)) return response()->json([]);

        try {
            $groups = \LdapRecord\Models\ActiveDirectory\Group::where('cn', 'contains', $term)
                ->limit(10)
                ->get()
                ->map(function($group) {
                    return [
                        'dn' => $group->getDn(),
                        'cn' => $group->getName(),
                    ];
                });
            return response()->json($groups);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request)
    {
        foreach ($request->except(['_token']) as $key => $value) {
            if (in_array($key, ['acme_hmac', 'mail_password', 'poller_api_key']) && ($value === '********' || empty($value))) {
                if ($value === '********') continue;
                if (empty($value) && Setting::where('key', $key)->exists()) continue;
            }
            
            // Handle array to string conversion safely
            $storeValue = is_array($value) ? json_encode($value) : $value;
            Setting::updateOrCreate(['key' => $key], ['value' => $storeValue]);
        }

        AuditLog::log('settings_update', "Updated general application settings");

        return redirect()->route('settings.index')->with('success', 'Settings updated.');
    }
}
