<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $area = null): Response
    {
        if (Auth::check()) {
            if ($area) {
                if (Auth::user()->hasAccessTo($area)) {
                    return $next($request);
                }
            } else {
                if (Auth::user()->canAccessDomainManagement()) {
                    return $next($request);
                }
            }
        }

        abort(403, 'Unauthorized access. You do not have permission to access this area.');
    }
}
