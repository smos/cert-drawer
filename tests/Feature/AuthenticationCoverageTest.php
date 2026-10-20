<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The explicit, audited whitelist of routes that are allowed to be accessible without 'auth' middleware.
     */
    protected array $allowedPublicUris = [
        'up',              // Framework health check
        'health',          // App health status JSON
        'login',           // Login page & submission
        'logout',          // Logout endpoint
        'setup',           // Initial setup (only when User::count() === 0)
    ];

    /**
     * Test that all registered routes in the application have 'auth' middleware,
     * except for the strictly audited public whitelist.
     */
    public function test_all_routes_except_whitelisted_have_auth_middleware(): void
    {
        $routes = Route::getRoutes();
        $unprotectedRoutes = [];

        foreach ($routes as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            // If it has 'auth' middleware, it's protected
            if (in_array('auth', $middleware, true)) {
                continue;
            }

            // Check if URI is in the allowed public whitelist
            if (!in_array($uri, $this->allowedPublicUris, true)) {
                $unprotectedRoutes[] = "{$uri} [" . implode(',', $route->methods()) . "]";
            }
        }

        $this->assertEmpty(
            $unprotectedRoutes,
            "The following routes are missing 'auth' middleware and are not whitelisted:\n" . implode("\n", $unprotectedRoutes)
        );
    }

    /**
     * Test that unauthenticated guests cannot access core application pages.
     */
    public function test_unauthenticated_guests_are_redirected_to_login(): void
    {
        // Ensure a user exists so RedirectIfNoUsers redirects to login rather than setup
        User::factory()->create();

        $protectedGetEndpoints = [
            '/',
            '/domains',
            '/authorities',
            '/settings',
            '/settings/auth',
            '/audit-logs',
            '/dns-health',
            '/cert-health',
            '/entra',
            '/automations',
        ];

        foreach ($protectedGetEndpoints as $endpoint) {
            $response = $this->get($endpoint);
            $response->assertRedirect('/login', "Expected {$endpoint} to redirect unauthenticated guest to /login.");
        }
    }

    /**
     * Test that JSON/API endpoints return 401/403 for unauthenticated guests.
     */
    public function test_unauthenticated_guests_receive_401_or_redirect_on_json_requests(): void
    {
        User::factory()->create();

        $response = $this->getJson('/domains');
        $response->assertStatus(401);

        $response = $this->postJson('/domains', ['name' => 'test.com']);
        $response->assertStatus(401);

        $response = $this->postJson('/settings/test-webhook');
        $response->assertStatus(401);
    }

    /**
     * Test that setup page is blocked once an admin user exists.
     */
    public function test_setup_is_locked_once_users_exist(): void
    {
        User::factory()->create();

        $response = $this->get('/setup');
        $response->assertRedirect('/');

        $response = $this->post('/setup', [
            'name' => 'Attacker',
            'email' => 'attacker@test.local',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $response->assertRedirect('/');
    }
}
