<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * The user an explicit `actingAs()` set, and on which guard.
     *
     * Remembered because `call()` below forgets every resolved guard, which throws away
     * a session-based `actingAs()` along with the token guard it is aiming at. Without
     * this, `actingAs($admin, 'admin')` produced a request with nobody signed in — the
     * middleware redirected to the login page and the test looked like a broken
     * permission check rather than a broken helper.
     *
     * @var array{0: Authenticatable, 1: string|null}|null
     */
    private ?array $actingAsUser = null;

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->actingAsUser = [$user, $guard];

        return parent::actingAs($user, $guard);
    }

    /**
     * Every test request starts with no resolved user, the way a real one
     * does.
     *
     * In production each request boots the application fresh, so a guard
     * never carries a user over from the previous request. Inside one test
     * the application is reused, and `RequestGuard` (which is what
     * `auth:sanctum` is) memoises the user it resolved — so a request made
     * after the token was deleted would still come back authenticated, and
     * a test asserting that logout or device revocation locks someone out
     * would pass for the wrong reason.
     *
     * Forgetting the guards here restores the real-world behaviour rather
     * than working around it. `json()` and friends all funnel through
     * `call()`, so this is the only place it is needed.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        // `Authenticate` middleware calls `shouldUse($guard)` on success, which
        // changes the DEFAULT guard for the rest of the process. In production
        // that dies with the request; inside one test it persists, so after any
        // authenticated call `$request->user()` would resolve a bearer token on
        // a route that has no auth middleware at all — and a test of an
        // unauthenticated path would silently exercise an authenticated one.
        $this->app['auth']->shouldUse(config('auth.defaults.guard'));

        /*
         * An explicit `actingAs()` is re-applied, because the two cases are different
         * in kind. A token in a header is re-read from the request every time, which is
         * exactly what the forgetting above restores; a session user is carried by a
         * cookie in production and so SHOULD survive between requests in a test.
         *
         * Tests that assert somebody is locked out after a token is revoked use
         * `withToken()`, not `actingAs()`, so they still see the real behaviour.
         */
        if ($this->actingAsUser !== null) {
            [$user, $guard] = $this->actingAsUser;

            $this->app['auth']->guard($guard)->setUser($user);

            if ($guard !== null) {
                $this->app['auth']->shouldUse($guard);
            }
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
