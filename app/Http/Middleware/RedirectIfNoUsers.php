<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNoUsers
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for console, setup routes, or if the user table doesn't exist yet
        if (app()->runningInConsole() || 
            $request->is('setup*') || 
            $request->is('_debugbar*') || 
            $request->is('up')) {
            return $next($request);
        }

        try {
            if (User::count() === 0) {
                return redirect()->route('setup.index');
            }
        } catch (\Exception $e) {
            // DB might not be ready
            return $next($request);
        }

        return $next($request);
    }
}
