<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return view('settings.index', compact('settings'));
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
            if ($key === 'acme_hmac' && ($value === '********' || empty($value))) {
                if ($value === '********') continue;
                if (empty($value) && Setting::where('key', $key)->exists()) continue;
            }
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        AuditLog::log('settings_update', "Updated general application settings");

        return redirect()->route('settings.index')->with('success', 'Settings updated.');
    }
}
