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

    /**
     * Test that the login page displays the inactivity expiration notice when requested.
     */
    public function test_login_page_displays_session_expired_message(): void
    {
        $response = $this->get('/login?expired=1');
        $response->assertStatus(200);
        $response->assertSee('Your session has expired due to inactivity. Please sign in again.');
    }

    /**
     * Test that an authenticated user navigating to login with expired=1 is logged out cleanly.
     */
    public function test_authenticated_user_hitting_login_with_expired_flag_is_logged_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        $response = $this->get('/login?expired=1');
        $response->assertStatus(200);
        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'logout',
            'description' => "Session expired due to inactivity for: {$user->email}",
        ]);
    }

    /**
     * Test that authenticated pages include the background session inactivity script.
     */
    public function test_authenticated_pages_include_inactivity_tracker_script(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
        $response->assertSee('certdrawer_last_activity');
        $response->assertSee('checkSessionTimeout');
    }

    /**
     * Test that guest pages do not include the background session inactivity script.
     */
    public function test_guest_pages_do_not_include_inactivity_tracker_script(): void
    {
        $guestResponse = $this->get('/login');
        $guestResponse->assertStatus(200);
        $guestResponse->assertDontSee('certdrawer_last_activity');
    }
}
